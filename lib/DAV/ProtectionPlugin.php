<?php
namespace OCA\FolderProtection\DAV;

use OCA\DAV\Connector\Sabre\Node;
use OCA\FolderProtection\ProtectionChecker;
use OCP\IL10N;
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
 * Exceção personalizada para retornar 403 Forbidden com mensagem customizada.
 *
 * Ao usar esta classe em vez de Sabre\DAV\Exception\Forbidden, o <s:exception>
 * no XML de erro será "OCA\FolderProtection\DAV\FolderProtected" — um valor que
 * o cliente Nextcloud desktop não reconhece — forçando-o a usar <s:message>
 * (que contém a nossa mensagem personalizada) em vez da string hardcoded
 * "You don't have access to this resource."
 */
class FolderProtected extends Exception {
    public function getHTTPCode() {
        return 403;
    }
}

class ProtectionPlugin extends ServerPlugin {

    private $protectionChecker;
    private $logger;
    private $server;
    private IL10N $l10n;

    public function __construct(ProtectionChecker $protectionChecker, LoggerInterface $logger, IL10N $l10n) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
        $this->l10n = $l10n;
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
        // NOTE: This handler is registered too late in the Sabre event lifecycle.
        // SabrePluginAuthInitEvent fires during emit('beforeMethod'), so our listener
        // is added after the current emit() has already started iterating — meaning
        // this handler is NEVER called.
        // DELETE and MOVE protection is handled in beforeUnbind/beforeMove instead.
        // COPY protection is handled in beforeCopy.
    }

    private function sendErrorResponse(int $code, string $message): void {
        $this->server->httpResponse->setStatus($code);
        $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">' . "\n";
        $xml .= '  <s:exception>OCA\FolderProtection\DAV\FolderProtected</s:exception>' . "\n";
        $xml .= '  <s:message>' . htmlspecialchars($message, ENT_XML1, 'UTF-8') . '</s:message>' . "\n";
        $xml .= '</d:error>';

        $this->server->httpResponse->setBody($xml);
    }

    private function getInternalPath($uri) {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                if (method_exists($node, 'getFileInfo')) {
                    $fileInfo = $node->getFileInfo();

                    // Group folder: traversar a cadeia de wrappers para obter o folderId
                    $folderId = $this->getGroupFolderIdFromStorage($fileInfo->getStorage());
                    if ($folderId !== null) {
                        $subPath = $fileInfo->getInternalPath();
                        $groupPath = '__groupfolders/' . $folderId;
                        if (!empty($subPath) && $subPath !== '.') {
                            $groupPath .= '/' . ltrim($subPath, '/');
                        }
                        return $groupPath;
                    }

                    // Pasta normal: usar o internal path do storage (ex: 'files/normal')
                    // que corresponde ao formato guardado na DB (normalizado para '/files/normal').
                    // NÃO usar o URI DAV (ex: 'files/ncadmin/normal') que inclui o username.
                    $internalPath = $fileInfo->getInternalPath();
                    if (strpos($internalPath, 'files/') !== 0) {
                        $internalPath = 'files/' . ltrim($internalPath, '/');
                    }
                    return $internalPath;
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug("FolderProtection DAV: getNodeForPath failed for '$uri': " . $e->getMessage());
        }

        // Fallback para group folders via URL
        if (preg_match('#^/remote\.php/(?:web)?dav/__groupfolders/(\d+)(/.*)?$#', $uri, $matches)) {
            $folderId = $matches[1];
            $filePath = $matches[2] ?? '';
            return '__groupfolders/' . $folderId . $filePath;
        }

        // Fallback genérico (não deve chegar aqui em condições normais)
        return $uri;
    }

    /**
     * Traverse the storage wrapper chain to find a GroupFolder storage with getFolderId().
     * Returns the folder ID or null if not a group folder.
     * Depth limit of 20 to handle complex wrapper chains (encryption + groupfolder + others).
     */
    private function getGroupFolderIdFromStorage($storage): ?int {
        $curr = $storage;
        $depth = 0;
        while ($curr !== null && $depth < 20) {
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
                    $folderName = basename($uri);
                    $this->logger->warning("FolderProtection DAV: Blocking bind in protected path: $candidate");
                    // Must throw an exception — returning false from beforeBind causes Sabre to
                    // still send 201, which confuses the desktop client into infinite retry loops.
                    $this->touchAncestors($uri);
                    $this->setHeaders('create', $this->l10n->t("The folder '%s' is protected", [$folderName]));
                    $this->sendProtectionNotification($candidate, 'create');
                    throw new FolderProtected($this->l10n->t("The folder '%s' is protected and cannot be created here.", [$folderName]));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof \Sabre\DAV\Exception) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeBind: " . $e->getMessage());
            throw new FolderProtected($this->l10n->t('Protection check failed'));
        }
    }

    public function beforeUnbind($uri) {
        try {
            $path = $this->getInternalPath($uri);
            $pathsToCheck = $this->buildPathsToCheck($path);

            foreach ($pathsToCheck as $candidate) {
                if ($this->protectionChecker->isProtected($candidate)) {
                    $this->touchProtectedNode($uri);

                    $info = $this->protectionChecker->getProtectionInfo($candidate);
                    $reason = $this->l10n->t('Protected by server policy');
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }

                    $folderName = basename($uri);
                    $msg = $this->l10n->t("The folder '%s' is protected: %s", [$folderName, $reason]);
                    $this->setHeaders('delete', $msg);
                    $this->sendProtectionNotification($candidate, 'delete');
                    $this->sendErrorResponse(403, $msg);
                    return false;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error("FolderProtection DAV: Error in beforeUnbind: " . $e->getMessage());
            $this->sendErrorResponse(403, $this->l10n->t('Protection check failed'));
            return false;
        }
    }

    public function beforeMove($sourcePath, $destinationPath) {
        try {
            $src = $this->getInternalPath($sourcePath);
            $pathsToCheck = $this->buildPathsToCheck($src);

            foreach ($pathsToCheck as $candidate) {
                if ($this->protectionChecker->isProtected($candidate)) {
                    $this->touchProtectedNode($sourcePath);

                    $info = $this->protectionChecker->getProtectionInfo($candidate);
                    $reason = $this->l10n->t('Protected by server policy');
                    if (is_array($info) && !empty($info['reason'])) {
                        $reason = (string)$info['reason'];
                    }

                    $folderName = basename($sourcePath);
                    $msg = $this->l10n->t("The folder '%s' is protected: %s", [$folderName, $reason]);
                    $this->setHeaders('move', $msg);
                    $this->sendProtectionNotification($candidate, 'move');
                    $this->sendErrorResponse(403, $msg);
                    return false;
                }
            }

            // Block rename to a protected name (prevents "create temp + rename" bypass)
            $dst = $this->getInternalPath($destinationPath);
            foreach ($this->buildPathsToCheck($dst) as $destCandidate) {
                if ($this->protectionChecker->isAnyProtectedWithBasename(basename($destCandidate))) {
                    $destName = basename($destinationPath);
                    $this->logger->warning("FolderProtection DAV: Blocking rename to protected name: $destCandidate (src: $src)");
                    $this->deleteEmptyNode($sourcePath);
                    $this->setHeaders('move', $this->l10n->t("Cannot rename to '%s': folder name is protected", [$destName]));
                    throw new FolderProtected($this->l10n->t("Cannot rename to '%s': this folder name is protected.", [$destName]));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof \Sabre\DAV\Exception) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeMove: " . $e->getMessage());
            throw new FolderProtected($this->l10n->t('Protection check failed'));
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
                    throw new FolderLocked($this->l10n->t("Cannot copy protected folder: %s", [basename($src)]));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeCopy: " . $e->getMessage());
            throw new FolderLocked($this->l10n->t('Internal server error during protection check.'));
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
                    throw new FolderLocked($this->l10n->t('Cannot update properties of protected folder'));
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in propPatch: " . $e->getMessage());
            throw new FolderLocked($this->l10n->t('Internal server error during protection check.'));
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
                        throw new FolderLocked($this->l10n->t('Cannot lock items in protected folders'));
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($e instanceof FolderLocked) throw $e;
            $this->logger->error("FolderProtection DAV: Error in beforeLock: " . $e->getMessage());
            throw new FolderLocked($this->l10n->t('Internal server error during protection check.'));
        }
    }

    private function touchProtectedNode(string $uri): void {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                $this->updateNodeCache($node);
            }

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

    private function touchAncestors(string $uri): void {
        $current = dirname($uri);
        $depth = 0;
        while ($current && $current !== '.' && $current !== '' && $depth < 6) {
            $this->touchProtectedNode($current);
            $parent = dirname($current);
            if ($parent === $current) break;
            $current = $parent;
            $depth++;
        }
    }

    private function updateNodeCache(Node $node): void {
        $info = $node->getFileInfo();
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
