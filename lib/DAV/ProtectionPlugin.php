<?php
namespace OCA\FolderProtection\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception\Locked;
use Psr\Log\LoggerInterface;

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
        try {
        $raw = $request->getPath();
        $path = $this->getInternalPath($raw);
        $method = $request->getMethod();

        $this->logger->debug("FolderProtection DAV: beforeMethod: $method -> raw='$raw'");
        $this->logger->debug("FolderProtection DAV: beforeMethod: internal path='$path'");

        $pathsToCheck = $this->buildPathsToCheck($path);
        $this->logger->debug("FolderProtection DAV: beforeMethod: pathsToCheck=" . implode(', ', $pathsToCheck));
        
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
                        throw new Locked('Cannot copy protected folders.');
                }
            }
        }

        if ($method === 'PROPFIND') {
            foreach ($pathsToCheck as $candidate) {
                if ($this->protectionChecker->isProtected($candidate)) {
                    $this->logger->debug("FolderProtection DAV: PROPFIND: '$candidate' IS protected.");
                        $info = $this->protectionChecker->getProtectionInfo($candidate);
                        $reason = 'Protected by server policy'; // Default reason
                        if (is_array($info) && !empty($info['reason'])) {
                            $reason = (string)$info['reason'];
                        }
                        $this->logger->warning("FolderProtection DAV: Blocking PROPFIND on protected path: $candidate");
                        $this->setHeaders('read', $reason);
                        $this->sendProtectionNotification($candidate, 'read');
                        throw new Locked('Protected folder.');
                }
                $this->logger->debug("FolderProtection DAV: PROPFIND: '$candidate' IS NOT protected.");
            }
        }

        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeMethod: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
        }
    }

    private function getInternalPath($uri) {
        // Try to get the path from the SabreDAV tree first
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                // Node::getPath() returns something like '/ncadmin/teste' or '/__groupfolders/1/path'
                $internalPath = $node->getPath();
                // If it's a user file path, prepend 'files' and remove leading slash
                if (strpos($internalPath, '/__groupfolders/') !== 0) { // Not a group folder
                    return 'files' . $internalPath; // e.g., files/ncadmin/teste
                }
                return ltrim($internalPath, '/'); // e.g., __groupfolders/1/path
            }
        } catch (\Exception $e) {
            // This is expected for paths that don't exist yet (e.g., during MKCOL, PUT, MOVE to new location)
            $this->logger->debug("FolderProtection DAV: getNodeForPath failed for '$uri': " . $e->getMessage());
        }

        // Fallback for paths that don't exist yet or other DAV endpoints
        // Example URI: /remote.php/dav/files/ncadmin/teste
        // We want: files/ncadmin/teste
        if (preg_match('#^/remote\.php/(?:web)?dav/files/([^/]+)(/.*)?$#', $uri, $matches)) {
            $username = $matches[1];
            $filePath = $matches[2] ?? ''; // This will be '/teste' or empty
            return 'files/' . $username . $filePath;
        }
        
        // Fallback for group folders if getNodeForPath failed
        if (preg_match('#^/remote\.php/(?:web)?dav/__groupfolders/(\d+)(/.*)?$#', $uri, $matches)) {
            $folderId = $matches[1];
            $filePath = $matches[2] ?? '';
            return '__groupfolders/' . $folderId . $filePath;
        }

        // If the URI is already an internal path (e.g., from another internal call)
        // and starts with 'files/' or '__groupfolders/', use it directly.
        if (strpos($uri, 'files/') === 0 || strpos($uri, '__groupfolders/') === 0) {
            return $uri;
        }

        // Last resort: return as is, let normalizePath handle it.
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
                throw new Locked('Cannot create items in protected folders');
            }
        }
        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeBind: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
        }
    }

    public function beforeUnbind($uri) {
        try {
        $path = $this->getInternalPath($uri);
        $this->logger->info("FolderProtection DAV: beforeUnbind checking '$path'");
        
        foreach ($this->buildPathsToCheck($path) as $checkPath) {
            if ($this->protectionChecker->isProtected($checkPath)) {
                    $info = $this->protectionChecker->getProtectionInfo($checkPath);
                    $reason = 'Protected by server policy'; // Default reason
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking delete - matched protected path: $checkPath");
                    $this->setHeaders('restore-from-server', $reason);
                    $this->sendProtectionNotification($checkPath, 'delete');
                    throw new Locked(
                        '🛡️ FOLDER PROTECTED: This folder is protected by server policy and cannot be deleted. ' .
                        'If deleted locally, please restore from Recycle Bin or force re-sync from server.'
                    );
            }
        }
        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeUnbind: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
        }
    }

    public function beforeMove($sourcePath, $destinationPath) {
        try {
        $src = $this->getInternalPath($sourcePath);
        $dest = $this->getInternalPath($destinationPath);
        
        $this->logger->info("FolderProtection DAV: beforeMove checking src='$src' dest='$dest'");
        
        foreach ($this->buildPathsToCheck($src) as $checkSrc) {
            if ($this->protectionChecker->isProtected($checkSrc)) {
                    $info = $this->protectionChecker->getProtectionInfo($checkSrc);
                    $reason = 'Protected by server policy'; // Default reason
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking move - source is protected: $checkSrc");
                    $this->setHeaders('restore-from-server', $reason);
                    $this->sendProtectionNotification($checkSrc, 'move');
                    throw new Locked(
                        '🛡️ FOLDER PROTECTED: This folder is protected by server policy and cannot be moved. ' .
                        'If moved locally, please restore from Recycle Bin or force re-sync from server.'
                    );
            }
        }
        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeMove: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
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
                    $reason = 'Protected by server policy'; // Default reason
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking copy - source is protected: $checkSrc");
                    $this->setHeaders('copy', $reason);
                    $this->sendProtectionNotification($checkSrc, 'copy');
                    throw new Locked('Cannot copy protected folder: ' . basename($src));
            }
        }
        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeCopy: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
        }
    }

    public function propPatch($path, \Sabre\DAV\PropPatch $propPatch) {
        try {
        $internalPath = $this->getInternalPath($path);
        foreach ($this->buildPathsToCheck($internalPath) as $checkPath) {
            if ($this->protectionChecker->isProtected($checkPath)) {
                    $info = $this->protectionChecker->getProtectionInfo($checkPath);
                    $reason = 'Protected by server policy'; // Default reason
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }
                    $this->logger->warning("FolderProtection DAV: Blocking property update on protected path: $checkPath");
                    $this->setHeaders('prop_patch', $reason);
                    $this->sendProtectionNotification($checkPath, 'prop_patch');
                    throw new Locked('Cannot update properties of protected folder');
            }
        }
        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in propPatch: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
        }
    }

    public function beforeLock($uri, \Sabre\DAV\Locks\LockInfo $lock) {
        try {
        $path = $this->getInternalPath($uri);
        if ($lock->scope === \Sabre\DAV\Locks\LockInfo::EXCLUSIVE) {
            foreach ($this->buildPathsToCheck($path) as $checkPath) {
                if ($this->protectionChecker->isProtected($checkPath)) {
                        $info = $this->protectionChecker->getProtectionInfo($checkPath);
                        $reason = 'Protected by server policy'; // Default reason
                        if (is_array($info) && !empty($info['reason'])) {
                            $reason = (string)$info['reason'];
                        }
                        $this->logger->warning("FolderProtection DAV: Blocking exclusive lock on protected path: $checkPath");
                        $this->setHeaders('lock', $reason);
                        $this->sendProtectionNotification($checkPath, 'lock');
                        throw new Locked('Cannot lock items in protected folders');
                }
            }
        }
        } catch (\Throwable $e) {
            if ($e instanceof Locked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeLock: " . $e->getMessage());
            throw new Locked('Internal server error during protection check.');
        }
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
