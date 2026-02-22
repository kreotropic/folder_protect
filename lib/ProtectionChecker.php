<?php

/**
 * ProtectionChecker
 *
 * Responsável por toda a lógica de verificação de proteção de pastas.
 *
 * Integração:
 * - Usado por plugins DAV, wrappers de storage e outros componentes para decidir se um path está protegido.
 * - Usa cache distribuída (via ICacheFactory) para acelerar verificações e reduzir load na base de dados.
 * - Consulta a tabela `folder_protection` para paths protegidos.
 *
 * Métodos principais:
 * - `isProtected($path)`: verifica proteção exacta (com cache).
 * - `isProtectedOrParentProtected($path)`: verifica proteção no path e em todos os pais.
 * - `isAnyProtectedWithBasename($basename)`: heurística para evitar operações copy-then-delete.
 * - `getProtectedFolders()`: devolve todos os paths protegidos (com cache).
 * - `normalizePath($path)`: normaliza paths para formato canónico.
 *
 * Notas:
 * - O uso de cache é fundamental para performance, especialmente em ambientes com muitos acessos DAV.
 * - O método `isAnyProtectedWithBasename` é uma heurística para bloquear operações "espertas" de clientes desktop.
 */

namespace OCA\FolderProtection;

use OCP\IDBConnection;
use OCP\ICacheFactory;
use OCP\ICache;

class ProtectionChecker {

    private IDBConnection $db;
    private ICache $cache;

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory) {
        $this->db = $db;
        // Cria uma cache distribuída específica para a app (namespace 'folder_protection').
        // Isto permite partilhar cache entre múltiplos nós/servidores.
        $this->cache = $cacheFactory->createDistributed('folder_protection');
    }

    /**
     * Verifica se um path está protegido (exact match)
     */
    public function isProtected(string $path): bool {
        $path = $this->normalizePath($path);
        // Verifica se o path está protegido (exact match). Usa cache para acelerar.

        // Check cache first
        $cacheKey = 'protected_' . md5($path);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            // Cache hit: devolve resultado imediatamente.
            return (bool)$cached;
        }

        // Check database
        $result = $this->checkDatabaseExact($path);

        // Cache result for 5 minutes
        $this->cache->set($cacheKey, $result ? 1 : 0, 300);

        return $result;
    }

    /**
     * Check if a path or any of its parent paths are protected
     */
    public function isProtectedOrParentProtected(string $path): bool {
        $path = $this->normalizePath($path);

        // Primeiro verifica o próprio path
        if ($this->isProtected($path)) {
            return true;
        }

        // Depois verifica todos os pais (ex: /a/b/c -> /a, /a/b)
        $parts = explode('/', trim($path, '/'));
        $currentPath = '';

        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }
            $currentPath .= '/' . $part;
            if ($this->isProtected($currentPath)) {
                return true;
            }
        }

        // Nenhum match encontrado
        return false;
    }

    /**
     * Get all protected folders from database
     */
    public function getProtectedFolders(): array {
        $cacheKey = 'all_protected_folders';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            // Cache hit: devolve lista de paths protegidos
            return json_decode($cached, true);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('path')
           ->from('folder_protection');

        $result = $qb->executeQuery();
        $folders = [];
        while ($row = (method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch())) {
            $folders[] = $row['path'];
        }
        $result->closeCursor();

        // Cache for 5 minutes
        $this->cache->set($cacheKey, json_encode($folders), 300);

        return $folders;
    }

    /**
     * Verifica se existe uma entrada EXACTA na tabela folder_protection
     */
    private function checkDatabaseExact(string $path): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('folder_protection')
           ->where($qb->expr()->eq('path', $qb->createNamedParameter($path)));

        $result = $qb->executeQuery();
        // usar fetchAssociative quando disponível
        $row = method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch();
        $result->closeCursor();

        // Retorna true se encontrou uma linha (path protegido)
        return $row !== false && $row !== null;
    }

    /**
     * Verifica se existe alguma pasta protegida com o mesmo basename.
     * Para group folders (stored como /__groupfolders/N), também verifica o mount_point
     * (o nome visível da pasta) de modo a bloquear cópias via rename ou drag-and-drop.
     */
    public function isAnyProtectedWithBasename(string $basename): bool {
        $basename = trim($basename, '/');
        if ($basename === '') {
            return false;
        }

        $protectedFolders = $this->getProtectedFolders();

        foreach ($protectedFolders as $protectedPath) {
            if (basename($protectedPath) === $basename) {
                return true;
            }
        }

        // Para group folders, o basename numérico (/__groupfolders/2) não bate com o nome visível.
        // Verifica também contra os mount_point names registados no app groupfolders.
        foreach ($this->getProtectedGroupFolderMountPoints() as $mountPoint) {
            if ($mountPoint === $basename) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devolve os mount_point names das group folders protegidas.
     * Retorna lista vazia se o app groupfolders não estiver instalado.
     */
    private function getProtectedGroupFolderMountPoints(): array {
        $cacheKey = 'protected_gf_mountpoints';
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return json_decode($cached, true) ?: [];
        }

        // Extrai os IDs das group folders protegidas
        $groupFolderIds = [];
        foreach ($this->getProtectedFolders() as $path) {
            if (preg_match('#^/__groupfolders/(\d+)$#', $path, $m)) {
                $groupFolderIds[] = (int)$m[1];
            }
        }

        if (empty($groupFolderIds)) {
            $this->cache->set($cacheKey, json_encode([]), 300);
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('mount_point')
               ->from('group_folders')
               ->where($qb->expr()->in(
                   'folder_id',
                   $qb->createNamedParameter($groupFolderIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
               ));

            $result = $qb->executeQuery();
            $mountPoints = [];
            while ($row = (method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch())) {
                $mountPoints[] = $row['mount_point'];
            }
            $result->closeCursor();
        } catch (\Exception $e) {
            // Tabela group_folders não existe (app não instalado) — ignora silenciosamente
            $mountPoints = [];
        }

        $this->cache->set($cacheKey, json_encode($mountPoints), 300);
        return $mountPoints;
    }

    /**
     * Normaliza paths usados na DB: remove slashes redundantes e garante leading slash.
     * Ex: "" -> "/", "foo/bar" -> "/foo/bar"
     */
    public function normalizePath(string $path): string {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return '/';
        }
        // Garante que todos os paths têm um único slash inicial e nenhum final
        return '/' . $trimmed;
    }


    /**
 * Get detailed protection info for a path
 * @param string $path
 * @return array|null ['id', 'path', 'reason', 'created_by', 'created_at'] ou null
 */
    public function getProtectionInfo(string $path): ?array {
        $path = $this->normalizePath($path);
        
        // Check cache first
        // Guardamos false para "não encontrado" e array para "encontrado".
        // Não podemos usar `$cached ?: null` porque false ?: null = null (cache miss falso).
        $cacheKey = 'folder_protection_info_' . md5($path);
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached === false ? null : $cached;
            }
        }
        
        // Query database
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('folder_protection')
            ->where($qb->expr()->eq('path', $qb->createNamedParameter($path)));
        
        $result = $qb->executeQuery();
        $row = method_exists($result, 'fetchAssociative') ? $result->fetchAssociative() : $result->fetch();
        $result->closeCursor();
        
        // Cache result
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, $row ?: false, 300); // 5 min
        }
        
        return $row ?: null;
    }

    /**
     * Verifica se deve enviar notificação (Rate Limiting)
     * Evita spam de notificações para a mesma pasta/ação num curto período.
     * TTL: 30 minutos (1800 segundos)
     */
    public function shouldNotify(string $path, string $action): bool {
        $cacheKey = 'notification_sent_' . md5($path) . '_' . $action;
        
        if ($this->cache->get($cacheKey)) {
            return false;
        }
        
        $this->cache->set($cacheKey, 1, 1800);
        return true;
    }

    /**
     * Limpa toda a cache da aplicação (incluindo rate limiting de notificações)
     */
    public function clearCache(): void {
        $this->cache->clear();
    }
}