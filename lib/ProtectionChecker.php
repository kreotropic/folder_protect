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
        error_log("FolderProtection: ProtectionChecker checking path: '$path'");

        // Check cache first
        $cacheKey = 'protected_' . md5($path);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            // Cache hit: devolve resultado imediatamente.
            error_log("FolderProtection: Cache hit for '$path': " . ($cached ? 'PROTECTED' : 'NOT PROTECTED'));
            return (bool)$cached;
        }

        // Check database
        $result = $this->checkDatabaseExact($path);
        error_log("FolderProtection: Database check for '$path': " . ($result ? 'PROTECTED' : 'NOT PROTECTED'));

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
            error_log("FolderProtection: Path '$path' is directly protected");
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
                error_log("FolderProtection: Parent path '$currentPath' is protected for '$path'");
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
     * PoC helper: verifica se existe alguma pasta protegida com o mesmo basename
     * (útil como heurística para evitar copy-then-delete do client desktop)
     */
    public function isAnyProtectedWithBasename(string $basename): bool {
        $basename = trim($basename, "/");
        if ($basename === '') {
            return false;
        }

        $protectedFolders = $this->getProtectedFolders();
        
        // Procura se existe algum path protegido com o mesmo basename
        foreach ($protectedFolders as $protectedPath) {
            if (basename($protectedPath) === $basename) {
                error_log("FolderProtection: Found protected folder with basename '$basename': $protectedPath");
                return true;
            }
        }

        // Não encontrou nenhum
        return false;
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

}