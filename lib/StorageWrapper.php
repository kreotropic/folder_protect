<?php
namespace OCA\FolderProtection;

use OCP\Files\NotPermittedException;
use OC\Files\Storage\Wrapper\Wrapper;
use Psr\Log\LoggerInterface;

class StorageWrapper extends Wrapper {

    private $protectionChecker;

    public function __construct($parameters) {
        parent::__construct($parameters);
        $this->protectionChecker = $parameters['protectionChecker'];
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
            \OC::$server->get(LoggerInterface::class)->error('FolderProtection: Failed to send notification: ' . $e->getMessage());
        }
    }

    public function __call($method, $args) {
        error_log("FolderProtection: UNKNOWN method called: $method with args: " . json_encode($args));
        return call_user_func_array([$this->storage, $method], $args);
    }

    public function is_dir($path): bool {
        return $this->storage->is_dir($path);
    }

    public function isDeletable($path): bool {
        if ($this->protectionChecker->isProtected($path)) {
            return false;
        }
        return $this->storage->isDeletable($path);
    }

    public function isUpdatable($path): bool {
        if ($this->protectionChecker->isProtected($path)) {
            return false;
        }
        return $this->storage->isUpdatable($path);
    }

    public function copy($source, $target): bool {
        if ($this->protectionChecker->isProtected($source)) {
            $this->sendProtectionNotification($source, 'copy');
            throw new NotPermittedException('This folder is protected and cannot be copied.', false);
        }
        if ($this->protectionChecker->isAnyProtectedWithBasename(basename($target))) {
            throw new NotPermittedException('Cannot create folders with protected names.', false);
        }
        return $this->storage->copy($source, $target);
    }

    public function rename(string $source, string $target): bool {
        if ($this->protectionChecker->isProtected($source)) {
            \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked rename/move of protected folder: $source");
            $this->sendProtectionNotification($source, 'move');
            throw new NotPermittedException("Moving protected folders is not allowed");
        }
        return $this->storage->rename($source, $target);
    }

    public function unlink(string $path): bool {
        if ($this->protectionChecker->isProtected($path)) {
            \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked unlink of protected path: $path");
            $this->sendProtectionNotification($path, 'delete');
            throw new NotPermittedException("Deleting protected folders is not allowed");
        }
        return $this->storage->unlink($path);
    }

    public function copyFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        if (!empty($sourceInternalPath) && $this->protectionChecker->isProtected($sourceInternalPath)) {
            $this->sendProtectionNotification($sourceInternalPath, 'copy');
            throw new NotPermittedException('This folder is protected and cannot be copied.', false);
        }

        if (method_exists($sourceStorage, 'getFolderId')) {
            $folderId = $sourceStorage->getFolderId();
            $groupFolderPath = "/__groupfolders/$folderId";
            if ($this->protectionChecker->isProtectedOrParentProtected($groupFolderPath)) {
                $this->sendProtectionNotification($groupFolderPath, 'copy');
                throw new NotPermittedException('This group folder is protected and cannot be copied.', false);
            }
        }

        if ($this->protectionChecker->isProtected($targetInternalPath)) {
            throw new NotPermittedException('Cannot copy into protected folders.', false);
        }

        if ($this->protectionChecker->isAnyProtectedWithBasename(basename($targetInternalPath))) {
            throw new NotPermittedException('Cannot create folders with protected names.', false);
        }

        return parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    public function moveFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        if ($this->protectionChecker->isProtected($sourceInternalPath)) {
            $this->sendProtectionNotification($sourceInternalPath, 'move');
            throw new NotPermittedException('This folder is protected and cannot be moved.', false);
        }
        return parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    public function rmdir(string $path): bool {
        if ($this->protectionChecker->isProtected($path)) {
            \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked rmdir of protected folder: $path");
            $this->sendProtectionNotification($path, 'delete');
            throw new NotPermittedException("Deleting protected folders is not allowed");
        }
        return $this->storage->rmdir($path);
    }

    public function getPermissions($path): int {
        if ($this->protectionChecker->isProtected($path)) {
            return \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_SHARE;
        }
        return $this->storage->getPermissions($path);
    }

    public function file_exists($path): bool {
        return $this->storage->file_exists($path);
    }

    public function mkdir(string $path): bool {
        if ($this->protectionChecker->isProtected($path)) {
            $this->sendProtectionNotification($path, 'create');
            throw new NotPermittedException('Cannot create directory: target is protected or inside a protected folder.', false);
        }
        if ($this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
            throw new NotPermittedException('Cannot create directory with this name because a protected folder exists.', false);
        }
        return $this->storage->mkdir($path);
    }
}
