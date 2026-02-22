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

class ProtectionPlugin extends ServerPlugin {

    private $protectionChecker;
    private $logger;
    private $server;

    public function __construct(ProtectionChecker $protectionChecker, LoggerInterface $logger) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

    public function initialize(Server $server) {
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
        // NOTE: This handler is registered too late in the Sabre event lifecycle
        // (SabrePluginAuthInitEvent fires during emit('beforeMethod'), so our listener
        // is added after the current emit() has already started iterating).
        // DELETE and MOVE protection is handled in beforeUnbind/beforeMove instead.
        // COPY protection is handled in beforeCopy.
    }

    private function sendErrorResponse(int $code, string $message): void {
        $this->server->httpResponse->setStatus($code);
        $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');

        // Formato de erro padrão do SabreDAV/Nextcloud
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">' . "\n";
        $xml .= '  <s:exception>Sabre\DAV\Exception\Forbidden</s:exception>' . "\n";
        $xml .= '  <s:message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</s:message>' . "\n";
        $xml .= '</d:error>';

        $this->server->httpResponse->setBody($xml);
    }

    private function getInternalPath($uri) {
        // Try to get the node to detect group folders (which need special path translation).
        // For regular nodes, $uri is already in the correct 'files/username/path' format.
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                // Check if this node is backed by a group folder storage.
                // Group folders appear in the user's DAV namespace (e.g. /files/ncadmin/Projetos)
                // but are protected via /__groupfolders/N paths.
                if (method_exists($node, 'getFileInfo')) {
                    $fileInfo = $node->getFileInfo();
                    $folderId = $this->getGroupFolderIdFromStorage($fileInfo->getStorage());
                    if ($folderId !== null) {
                        $subPath = $fileInfo->getInternalPath(); // path within the group folder
                        $groupPath = '__groupfolders/' . $folderId;
                        if (!empty($subPath) && $subPath !== '.') {
                            $groupPath .= '/' . ltrim($subPath, '/');
                        }
                        return $groupPath;
                    }
                }

                // Not a group folder: $uri is already 'files/username/path' — use it directly.
                // (node->getPath() returns a path without the username, so we cannot use it.)
                if (strpos($uri, 'files/') === 0 || strpos($uri, '__groupfolders/') === 0) {
                    return $uri;
                }
                $internalPath = $node->getPath();
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

    /**
     * Traverse the storage wrapper chain to find a GroupFolder storage with getFolderId().
     * Returns the folder ID or null if not a group folder.
     */
    private function getGroupFolderIdFromStorage($storage): ?int {
        $curr = $storage;
        $depth = 0;
        while ($curr !== null && $depth < 10) {
            if (method_exists($curr, 'getFolderId')) {
                return (int)$curr->getFolderId();
            }
            $curr = method_exists($curr, 'getWrapperStorage') ? $curr->getWrapperStorage() : null;
            $depth++;
        }
        return null;
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
                    // Touch the parent directory so the desktop client re-lists it and
                    // removes the local copy it created before the server rejected the MKCOL.
                    // Use 403 (not 423) so the client treats it as permanent and cleans up.
                    $parentUri = dirname($uri);
                    if ($parentUri && $parentUri !== '.' && $parentUri !== $uri) {
                        $this->touchProtectedNode($parentUri);
                    }
                    $this->setHeaders('create', 'Cannot create folders with protected names');
                    $this->sendProtectionNotification($candidate, 'create');
                    $this->sendErrorResponse(403, '🛡️ FOLDER PROTECTED: Cannot create folders with protected names');
                    return false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("FolderProtection DAV: Error in beforeBind: " . $e->getMessage());
            $this->sendErrorResponse(403, 'Protection check failed');
            return false;
        }
    }

    public function beforeUnbind($uri) {
        try {
            $path = $this->getInternalPath($uri);
            $pathsToCheck = $this->buildPathsToCheck($path);

            foreach ($pathsToCheck as $candidate) {
                if ($this->protectionChecker->isProtected($candidate)) {
                    // Update ETag so the sync client detects the change and restores the folder
                    $this->touchProtectedNode($uri);

                    $info = $this->protectionChecker->getProtectionInfo($candidate);
                    $reason = 'Protected by server policy';
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }

                    $this->setHeaders('delete', $reason);
                    $this->sendProtectionNotification($candidate, 'delete');
                    $this->sendErrorResponse(403, "🛡️ FOLDER PROTECTED: $reason");
                    return false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("FolderProtection DAV: Error in beforeUnbind: " . $e->getMessage());
            $this->sendErrorResponse(403, 'Protection check failed');
            return false;
        }
    }

    public function beforeMove($sourcePath, $destinationPath) {
        try {
            $src = $this->getInternalPath($sourcePath);
            $pathsToCheck = $this->buildPathsToCheck($src);

            // Block if SOURCE is a protected folder
            foreach ($pathsToCheck as $candidate) {
                if ($this->protectionChecker->isProtected($candidate)) {
                    $this->touchProtectedNode($sourcePath);

                    $info = $this->protectionChecker->getProtectionInfo($candidate);
                    $reason = 'Protected by server policy';
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }

                    $this->setHeaders('move', $reason);
                    $this->sendProtectionNotification($candidate, 'move');
                    $this->sendErrorResponse(403, "🛡️ FOLDER PROTECTED: $reason");
                    return false;
                }
            }

            // Block if DESTINATION has a protected name (prevents "create temp folder + rename" bypass).
            // When the client creates an empty stepping-stone folder and then renames it to a protected
            // name, we block the rename here and also delete the now-orphaned source folder.
            $dst = $this->getInternalPath($destinationPath);
            foreach ($this->buildPathsToCheck($dst) as $destCandidate) {
                if ($this->protectionChecker->isAnyProtectedWithBasename(basename($destCandidate))) {
                    $this->logger->warning("FolderProtection DAV: Blocking rename to protected name: $destCandidate (src: $src)");
                    // Delete the empty stepping-stone folder from the server so it does not become orphaned.
                    $this->deleteEmptyNode($sourcePath);
                    $this->setHeaders('move', 'Cannot rename to a protected folder name');
                    $this->sendErrorResponse(403, '🛡️ FOLDER PROTECTED: Cannot rename to a protected folder name');
                    return false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("FolderProtection DAV: Error in beforeMove: " . $e->getMessage());
            $this->sendErrorResponse(403, 'Protection check failed');
            return false;
        }
    }

    /**
     * Delete a node if it exists and is an empty collection.
     * Used to clean up stepping-stone folders created by the client before a blocked rename.
     */
    private function deleteEmptyNode(string $uri): void {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof \Sabre\DAV\ICollection && empty($node->getChildren())) {
                $node->delete();
                $this->logger->info("FolderProtection DAV: Deleted empty stepping-stone folder: $uri");
            }
        } catch (\Exception $e) {
            $this->logger->debug("FolderProtection DAV: Could not delete stepping-stone '$uri': " . $e->getMessage());
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
            $this->logger->warning("FolderProtection DAV: Failed to touch node '$uri': " . $e->getMessage());
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
