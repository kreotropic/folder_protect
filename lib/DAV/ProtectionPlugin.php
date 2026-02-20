<?php
namespace OCA\FolderProtection\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception;
use Psr\Log\LoggerInterface;

/**
 * Exceção personalizada para retornar 423 Locked com mensagem customizada.
 * A classe Sabre\DAV\Exception\Locked original não aceita mensagem no construtor,
 * o que causava TypeError (Erro 500).
 */
class FolderLocked extends Exception {
    public function getHTTPCode() {
        return 423;
    }
}

/**
 * Exceção para operações proibidas (DELETE, MOVE) que devem retornar 403.
 * Isto é mais forte que 423 e previne que clientes apaguem ficheiros localmente.
 */
class OperationForbidden extends Exception {
    public function getHTTPCode() {
        return 403; // Forbidden
    }
}

class ProtectionPlugin extends ServerPlugin {

    private $protectionChecker;
    private $logger;
    private $server;

    public function __construct(ProtectionChecker $protectionChecker, LoggerInterface $logger) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

    public function initialize(Server $server) {
        error_log("DEBUG_FP: ProtectionPlugin initialize called (Plugin attached to Server)");
        $this->server = $server;

        $server->on('beforeBind', [$this, 'beforeBind'], 10);
        $server->on('beforeUnbind', [$this, 'beforeUnbind'], 10);
        $server->on('beforeMove', [$this, 'beforeMove'], 10);
        $server->on('beforeCopy', [$this, 'beforeCopy'], 10);
        $server->on('propPatch', [$this, 'propPatch'], 10);
        $server->on('beforeLock', [$this, 'beforeLock'], 10);
        $server->on('beforeMethod', [$this, 'beforeMethod'], 10);

        $this->logger->info('FolderProtection: WebDAV plugin initialized successfully');
    }

    private function setHeaders(string $action, string $reason): void {
        $this->server->httpResponse->setHeader('X-NC-Folder-Protected', 'true');
        $this->server->httpResponse->setHeader('X-NC-Protection-Action', $action);
        $this->server->httpResponse->setHeader('X-NC-Protection-Reason', $reason);
    }

    private function sendProtectionNotification(string $path, string $action): void {
        try {
            // Rate limiting: verifica se já notificou recentemente
            if (!$this->protectionChecker->shouldNotify($path, $action)) {
                return;
            }

            $userSession = \OC::$server->getUserSession();
            if (!$userSession || !$userSession->isLoggedIn()) {
                return;
            }
            $user = $userSession->getUser();
            if (!$user) {
                return;
            }

            $manager = \OC::$server->getNotificationManager();
            $notification = $manager->createNotification();

            $notification->setApp('folder_protection')
                ->setUser($user->getUID())
                ->setDateTime(new \DateTime())
                ->setObject('folder', substr(md5($path), 0, 32))
                ->setSubject('folder_protected', [
                    'path' => basename($path),
                    'action' => $action
                ]);

            $manager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error('FolderProtection: Failed to send notification: ' . $e->getMessage());
        }
    }

    public function beforeMethod($request, $response) {
        try {
            $raw = $request->getPath();
            $path = $this->getInternalPath($raw);
            $method = $request->getMethod();

            // LOGS DE DEBUG (Usando error_log para visibilidade imediata no Docker logs)
            error_log("DEBUG_FP: beforeMethod: $method -> raw='$raw'");
            error_log("DEBUG_FP: beforeMethod: internal path='$path'");

            $pathsToCheck = $this->buildPathsToCheck($path);
            
            // Intercepta DELETE e MOVE cedo para garantir que o "touch" (ETag update) persiste.
            // Usamos 403 Forbidden com corpo XML para que o cliente entenda o erro
            // e, ao ver o novo ETag, force o restauro (Server Wins).
            if ($method === 'DELETE' || $method === 'MOVE') {
                foreach ($pathsToCheck as $candidate) {
                    if ($this->protectionChecker->isProtected($candidate)) {
                        error_log("DEBUG_FP: Protected path match: $candidate");
                        // 1. Força atualização do ETag para que o cliente detete mudança e restaure a pasta
                        $this->touchProtectedNode($raw);

                        // 2. Prepara headers e notificação
                        $info = $this->protectionChecker->getProtectionInfo($candidate);
                        $reason = 'Protected by server policy';
                        if (is_array($info) && !empty($info['reason'])) {
                            $reason = (string)$info['reason'];
                        }
                        
                        $action = ($method === 'DELETE') ? 'delete' : 'move';
                        error_log("DEBUG_FP: Blocking $method on protected path: $candidate");
                        $this->setHeaders($action, $reason);
                        $this->sendProtectionNotification($candidate, $action);
                        
                        // 3. Retorna 403 Forbidden com XML válido (SabreDAV standard)
                        $this->sendErrorResponse(403, "🛡️ FOLDER PROTECTED: $reason");
                        return false;
                    }
                }
            }

            if ($method === 'COPY') {
                foreach ($pathsToCheck as $candidate) {
                    if ($this->protectionChecker->isProtected($candidate) ||
                        $this->protectionChecker->isAnyProtectedWithBasename(basename($candidate))) {
                            $info = $this->protectionChecker->getProtectionInfo($candidate);
                            $reason = 'Protected by server policy'; // Default reason
                            if (is_array($info) && !empty($info['reason'])) {
                                $reason = (string)$info['reason'];
                            }
                            $this->logger->warning("FolderProtection DAV: Blocking COPY on protected path: $candidate");
                            $this->setHeaders('copy', $reason);
                            $this->sendProtectionNotification($candidate, 'copy');
                            throw new FolderLocked('Cannot copy protected folders.');
                    }
                }
            }

        } catch (\Throwable $e) {
            // Se for a nossa exceção, deixa passar
            if ($e instanceof FolderLocked) throw $e;
            
            // Se for outro erro, loga e lança 423 genérico para não crashar com 500
            $this->logger->error("FolderProtection DAV: Error in beforeMethod: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    private function sendErrorResponse(int $code, string $message): void {
        error_log("DEBUG_FP: Sending custom error response. Code: $code");
        
        $this->server->httpResponse->setStatus($code);
        $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
        
        // Formato de erro padrão do SabreDAV/Nextcloud
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">' . "\n";
        $xml .= '  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>' . "\n";
        $xml .= '  <s:message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</s:message>' . "\n";
        $xml .= '</d:error>';
        
        error_log("DEBUG_FP: XML Body: " . $xml);
        $this->server->httpResponse->setBody($xml);
    }

    private function getInternalPath($uri) {
        // Try to get the path from the SabreDAV tree first
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                $internalPath = $node->getPath();
                if (strpos($internalPath, '/__groupfolders/') !== 0) { 
                    return 'files' . $internalPath; 
                }
                return ltrim($internalPath, '/'); 
            }
        } catch (\Exception $e) {
            $this->logger->debug("FolderProtection DAV: getNodeForPath failed for '$uri': " . $e->getMessage());
        }

        if (preg_match('#^/remote\.php/(?:web)?dav/files/([^/]+)(/.*)?$#', $uri, $matches)) {
            $username = $matches[1];
            $filePath = $matches[2] ?? ''; 
            return 'files/' . $username . $filePath;
        }
        
        if (preg_match('#^/remote\.php/(?:web)?dav/__groupfolders/(\d+)(/.*)?$#', $uri, $matches)) {
            $folderId = $matches[1];
            $filePath = $matches[2] ?? '';
            return '__groupfolders/' . $folderId . $filePath;
        }

        if (strpos($uri, 'files/') === 0 || strpos($uri, '__groupfolders/') === 0) {
            return $uri;
        }

        return $uri;
    }

    private function buildPathsToCheck(string $path): array {
        $paths = [$path];
        $decodedPath = rawurldecode($path);
        if ($path !== $decodedPath) {
            $paths[] = $decodedPath;
        }
        return array_unique(array_filter($paths));
    }

    public function beforeBind($uri) {
        try {
            $path = $this->getInternalPath($uri);
            $this->logger->debug("FolderProtection DAV: beforeBind checking '$path'");

            foreach ($this->buildPathsToCheck($path) as $candidate) {
                if ($this->protectionChecker->isAnyProtectedWithBasename(basename($candidate))) {
                    $this->logger->warning("FolderProtection DAV: Blocking bind in protected path: $candidate");
                    $this->setHeaders('create', 'Cannot create items in protected folders');
                    $this->sendProtectionNotification($candidate, 'create');
                    throw new FolderLocked('Cannot create items in protected folders');
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeBind: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    public function beforeUnbind($uri) {
        try {
            $path = $this->getInternalPath($uri);
            $this->logger->info("FolderProtection DAV: beforeUnbind checking '$path'");
            
            // A verificação de DELETE foi movida para beforeMethod.
            // Isto evita que exceções causem rollback na atualização do ETag.
            // O StorageWrapper continua a proteger deletes internos (não-DAV).
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeUnbind: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    public function beforeMove($sourcePath, $destinationPath) {
        try {
            // A verificação de MOVE foi movida para beforeMethod para evitar rollback de transação.
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeMove: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    public function beforeCopy($sourcePath, $destinationPath) {
        try {
            $src = $this->getInternalPath($sourcePath);
            $dest = $this->getInternalPath($destinationPath);
            
            $this->logger->info("FolderProtection DAV: beforeCopy checking src='$src' dest='$dest'");
            
            foreach ($this->buildPathsToCheck($src) as $checkSrc) {
                if ($this->protectionChecker->isProtected($checkSrc)) {
                    $info = $this->protectionChecker->getProtectionInfo($checkSrc);
                    $reason = 'Protected by server policy';
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking copy - source is protected: $checkSrc");
                    $this->setHeaders('copy', $reason);
                    $this->sendProtectionNotification($checkSrc, 'copy');
                    throw new FolderLocked('Cannot copy protected folder: ' . basename($src));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeCopy: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    public function propPatch($path, \Sabre\DAV\PropPatch $propPatch) {
        try {
            $internalPath = $this->getInternalPath($path);
            foreach ($this->buildPathsToCheck($internalPath) as $checkPath) {
                if ($this->protectionChecker->isProtected($checkPath)) {
                    $info = $this->protectionChecker->getProtectionInfo($checkPath);
                    $reason = 'Protected by server policy';
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking property update on protected path: $checkPath");
                    $this->setHeaders('prop_patch', $reason);
                    $this->sendProtectionNotification($checkPath, 'prop_patch');
                    throw new FolderLocked('Cannot update properties of protected folder');
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in propPatch: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    public function beforeLock($uri, \Sabre\DAV\Locks\LockInfo $lock) {
        try {
            $path = $this->getInternalPath($uri);
            if ($lock->scope === \Sabre\DAV\Locks\LockInfo::EXCLUSIVE) {
                foreach ($this->buildPathsToCheck($path) as $checkPath) {
                    if ($this->protectionChecker->isProtected($checkPath)) {
                        $info = $this->protectionChecker->getProtectionInfo($checkPath);
                        $reason = 'Protected by server policy';
                        if (is_array($info) && !empty($info['reason'])) {
                            $reason = (string)$info['reason'];
                        }
                        $this->logger->warning("FolderProtection DAV: Blocking exclusive lock on protected path: $checkPath");
                        $this->setHeaders('lock', $reason);
                        $this->sendProtectionNotification($checkPath, 'lock');
                        throw new FolderLocked('Cannot lock items in protected folders');
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeLock: " . $e->getMessage());
            throw new FolderLocked('Internal server error during protection check.');
        }
    }

    /**
     * Atualiza o mtime e ETag do nó e do seu pai na cache.
     * Isso ajuda clientes de sincronização a perceberem que devem restaurar a pasta
     * em vez de apenas mostrarem erro de sincronização.
     */
    private function touchProtectedNode(string $uri): void {
        try {
            error_log("DEBUG_FP: touchProtectedNode called for $uri");
            // 1. Atualiza a própria pasta
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                $this->updateNodeCache($node);
            }

            // 2. Atualiza a pasta pai (para forçar o cliente a ver a lista de ficheiros novamente)
            $parentUri = dirname($uri);
            if ($parentUri && $parentUri !== '.' && $parentUri !== $uri) {
                try {
                    $parentNode = $this->server->tree->getNodeForPath($parentUri);
                    if ($parentNode instanceof Node) {
                        $this->updateNodeCache($parentNode);
                    }
                } catch (\Exception $e) {
                    // Ignora erro no pai (pode ser a raiz ou inacessível)
                }
            }
        } catch (\Throwable $e) {
            error_log("DEBUG_FP: Failed to touch node '$uri': " . $e->getMessage());
        }
    }

    private function updateNodeCache(Node $node): void {
        $info = $node->getFileInfo();
        
        // Gera novo ETag para garantir que o cliente deteta a mudança de versão
        $newEtag = md5(uniqid((string)time(), true));

        $info->getStorage()->getCache()->update($info->getId(), [
            'mtime' => time(),
            'etag' => $newEtag
        ]);
        
        error_log("DEBUG_FP: Touched node '" . $node->getName() . "' (new etag: $newEtag)");
    }

    public function getPluginName() {
        return 'folder-protection';
    }

    public function getPluginInfo() {
        return [
            'name' => $this->getPluginName(),
            'description' => 'Prevents operations on protected folders via WebDAV'
        ];
    }
}
