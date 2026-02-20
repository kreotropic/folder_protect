<?php
declare(strict_types=1);

namespace OCA\FolderProtection\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Locks\LockInfo;
use Sabre\DAV\Xml\Response\MultiStatus;
use Psr\Log\LoggerInterface;

/**
 * Plugin WebDAV que implementa bloqueio de lock/unlock para pastas protegidas.
 * 
 * Estratégia:
 * 1. Bloqueia tentativas de LOCK em pastas protegidas (com exceção 403)
 * 2. Bloqueia tentativas de UNLOCK em pastas protegidas (com exceção 403)
 * 3. Reporta propriedades customizadas que indicam proteção 
 * 4. Usa o namespace Nextcloud para máxima compatibilidade
 * 
 * Windows, macOS e clientes WebDAV:
 * - Veem que a pasta está protegida
 * - Recebem 403 se tentarem lock/unlock
 * - Sem necessidade de restaurar a pasta depois
 */
class LockPlugin extends ServerPlugin {

    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;
    private ?Server $server = null;

    public function __construct(
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger
    ) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

    public function initialize(Server $server): void {
        $this->server = $server;
        
        // Handlers para interceptar operações de lock
        $server->on('beforeLock', [$this, 'beforeLock'], 5);   // Prioridade alta - antes do ProtectionPlugin
        $server->on('beforeUnlock', [$this, 'beforeUnlock'], 5);
        
        // Handler para PROPFIND - reportar locks nas propriedades
        $server->on('propFind', [$this, 'propFind']);
        
        $this->logger->info('FolderProtection: Lock plugin initialized');
    }

    /**
     * Intercepta tentativas de LOCK - bloqueia locks em pastas protegidas
     */
    public function beforeLock(string $uri, LockInfo $lock): void {
        try {
            $path = $this->getInternalPath($uri);
            
            // Se a pasta protegida já tem um lock automático, rejeita qualquer lock novo
            if ($this->protectionChecker->isProtected($path)) {
                $info = $this->protectionChecker->getProtectionInfo($path);
                $reason = $info['reason'] ?? 'Protected by server policy';
                
                $this->logger->warning("FolderProtection Lock: Blocking LOCK attempt on protected: $path");
                $this->setResponseHeaders('lock', $reason);
                
                // Lança exceção para bloquear a operação (SabreDAV standard)
                throw new \Sabre\DAV\Exception\Forbidden(
                    sprintf('🛡️ FOLDER PROTECTED: %s', $reason)
                );
            }
        } catch (\Sabre\DAV\Exception $e) {
            // Re-lança exceções DAV
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection Lock: Error in beforeLock', [
                'error' => $e->getMessage()
            ]);
            throw new \Sabre\DAV\Exception\InternalServerError('Protection check failed');
        }
    }

    /**
     * Intercepta tentativas de UNLOCK - bloqueia unlock em pastas protegidas
     */
    public function beforeUnlock(string $uri, LockInfo $lock): void {
        try {
            $path = $this->getInternalPath($uri);
            
            if ($this->protectionChecker->isProtected($path)) {
                // Bloqueia tentativas de fazer unlock numa pasta protegida
                $this->logger->warning("FolderProtection Lock: Blocking UNLOCK attempt on protected: $path");
                
                throw new \Sabre\DAV\Exception\Forbidden(
                    'Cannot unlock protected folders. They are locked by the system.'
                );
            }
        } catch (\Sabre\DAV\Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection Lock: Error in beforeUnlock', [
                'error' => $e->getMessage()
            ]);
            throw new \Sabre\DAV\Exception\InternalServerError('Protection check failed');
        }
    }

    /**
     * Handler para PROPFIND - Reporta status de proteção em pastas
     * Clientes WebDAV verificam propriedades antes de operações
     */
    public function propFind(PropFind $propFind, \Sabre\DAV\INode $node): void {
        try {
            $path = $this->getNodePath($node);
            if (empty($path)) {
                return;
            }

            // Verifica se está protegida
            if (!$this->protectionChecker->isProtected($path)) {
                return;
            }

            $info = $this->protectionChecker->getProtectionInfo($path);
            $reason = $info['reason'] ?? 'Protected by server policy';

            // Reporta propriedade customizada que indica proteção
            $propFind->handle('{http://nextcloud.org/ns}is-locked', 'true');
            $propFind->handle('{http://nextcloud.org/ns}lock-reason', $reason);

            $this->logger->debug("FolderProtection Lock: Reported lock status for: $path");
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection Lock: Error in propFind', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extrai o path interno do nó
     */
    private function getNodePath(\Sabre\DAV\INode $node): string {
        try {
            if (method_exists($node, 'getFileInfo')) {
                $fileInfo = $node->getFileInfo();
                $path = $fileInfo->getInternalPath();
                
                if (strpos($path, 'files/') !== 0) {
                    $path = 'files/' . ltrim($path, '/');
                }
                
                return '/' . $path;
            }
        } catch (\Exception $e) {
            $this->logger->error('FolderProtection Lock: Error getting node path', [
                'error' => $e->getMessage()
            ]);
        }
        
        return '';
    }

    /**
     * Extrai e normaliza path do URI
     */
    private function getInternalPath(string $uri): string {
        // Remove prefixos de encoding e normaliza
        $path = urldecode($uri);
        $path = dirname($path);
        
        if (!str_starts_with($path, '/files/')) {
            $path = '/files' . $path;
        }
        
        return $path;
    }

    /**
     * Define headers HTTP para resposta
     */
    private function setResponseHeaders(string $action, string $reason): void {
        if ($this->server) {
            $this->server->httpResponse->setHeader('X-NC-Folder-Protected', 'true');
            $this->server->httpResponse->setHeader('X-NC-Protection-Action', $action);
            $this->server->httpResponse->setHeader('X-NC-Protection-Reason', $reason);
        }
    }

    public function getPluginName(): string {
        return 'folder-protection-lock';
    }

    public function getFeatures(): array {
        return ['folder-protection-lock'];
    }
}
