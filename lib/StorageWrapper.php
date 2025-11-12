<?php
namespace OCA\FolderProtection;

use OC\Files\Storage\Wrapper\Wrapper;
use OCP\Files\ForbiddenException;
use Psr\Log\LoggerInterface;
use OCP\Files\NotPermittedException;




class StorageWrapper extends Wrapper {

    private $protectionChecker;

    public function __construct($parameters) {
        parent::__construct($parameters);
        $this->protectionChecker = $parameters['protectionChecker'];
    }

    /**
     * Log all method calls for debugging
     */
    public function __call($method, $args) {
        error_log("FolderProtection: UNKNOWN method called: $method with args: " . json_encode($args));
        return call_user_func_array([$this->storage, $method], $args);
    }

    /**
     * Check if path is a directory
     */
    public function is_dir($path): bool {
        error_log("FolderProtection: is_dir called for: $path");
        return $this->storage->is_dir($path);
    }

    /**
     * Check if path is deletable
     */
    public function isDeletable($path): bool {
        error_log("FolderProtection: isDeletable called for: $path");
        if ($this->protectionChecker->isProtected($path)) {
            error_log("FolderProtection: BLOCKING delete on $path via isDeletable");
            return false;
        }
        return $this->storage->isDeletable($path);
    }

    /**
     * Check if path is updatable
     */
    public function isUpdatable($path): bool {
        error_log("FolderProtection: isUpdatable called for: $path");
        if ($this->protectionChecker->isProtected($path)) {
            error_log("FolderProtection: BLOCKING update on $path via isUpdatable");
            return false;
        }
        return $this->storage->isUpdatable($path);
    }

    /**
     * Block copy operations on protected folders
     */
    public function copy($source, $target): bool {
        error_log("FolderProtection: copy called - source: $source, target: $target");

        if ($this->protectionChecker->isProtected($source)) {
            error_log("FolderProtection: BLOCKING copy of $source");
            throw new ForbiddenException(
                'This folder is protected and cannot be copied.',
                false
            );
        }
        return $this->storage->copy($source, $target);
    }

    /**
     * Block rename/move operations on protected folders
     */
public function rename(string $source, string $target): bool {
    error_log("FolderProtection: rename called - source: $source, target: $target");
    if ($this->protectionChecker->isProtected($source)) {
        \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked rename/move of protected folder: $source");
        throw new NotPermittedException("Moving protected folders is not allowed");
    }

    return $this->storage->rename($source, $target);
}

    /**
     * Block file deletion on protected paths
     */
public function unlink(string $path): bool {
    error_log("FolderProtection: unlink called for: $path");
    if ($this->protectionChecker->isProtected($path)) {
        \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked unlink of protected path: $path");
        throw new NotPermittedException("Deleting protected folders is not allowed");
    }

    return $this->storage->unlink($path);
}

    /**
     * Block cross-storage copy (important for GroupFolders)
     */
    public function copyFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        error_log("FolderProtection: copyFromStorage called - source: $sourceInternalPath, target: $targetInternalPath");

        if ($this->protectionChecker->isProtected($sourceInternalPath)) {
            error_log("FolderProtection: BLOCKING copyFromStorage of $sourceInternalPath");
            throw new ForbiddenException(
                'This folder is protected and cannot be copied.',
                false
            );
        }
        return parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    /**
     * Block cross-storage move (important for GroupFolders)
     */
    public function moveFromStorage(\OCP\Files\Storage\IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        error_log("FolderProtection: moveFromStorage called - source: $sourceInternalPath, target: $targetInternalPath");

        if ($this->protectionChecker->isProtected($sourceInternalPath)) {
            error_log("FolderProtection: BLOCKING moveFromStorage of $sourceInternalPath");
            throw new ForbiddenException(
                'This folder is protected and cannot be moved.',
                false
            );
        }
        return parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
    }

    /**
     * Block folder deletion on protected paths
     */
public function rmdir(string $path): bool {
    error_log("FolderProtection: rmdir called for: $path");
    if ($this->protectionChecker->isProtected($path)) {
        \OC::$server->get(LoggerInterface::class)->warning("FolderProtection: blocked rmdir of protected folder: $path");
        throw new NotPermittedException("Deleting protected folders is not allowed");
    }

    return $this->storage->rmdir($path);
}

    /**
     * Remove write permissions from protected paths
     */
    public function getPermissions($path): int {
        if ($this->protectionChecker->isProtected($path)) {
            error_log("FolderProtection: REMOVING write permissions from $path");
            // Return only read permission, remove all write/delete/update
            return \OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_SHARE;
        }
        return $this->storage->getPermissions($path);
    }
    public function file_exists($path): bool {
        error_log("FolderProtection: file_exists called for: $path");
        return $this->storage->file_exists($path);
    }


    public function mkdir(string $path): bool {
    error_log("FolderProtection: mkdir called for: $path");

    // Bloqueia se o destino ou qualquer ancestor estiver protegido
    if ($this->protectionChecker->isProtected($path) || $this->protectionChecker->isProtectedOrParentProtected($path)) {
        error_log("FolderProtection: BLOCKING mkdir for protected path: $path");
        throw new \OCP\Files\ForbiddenException(
            'Cannot create directory: target is protected or inside a protected folder.',
            false
        );
    }

    // Bloqueia se o basename do novo item conflitar com uma pasta protegida (opção PoC)
    if ($this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
        error_log("FolderProtection: BLOCKING mkdir for $path because protected basename: " . basename($path));
        throw new \OCP\Files\ForbiddenException(
            'Cannot create directory with this name because a protected folder exists.',
            false
        );
    }

    return $this->storage->mkdir($path);
}


}
