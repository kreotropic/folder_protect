<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Controller;

use OCA\FolderProtection\ProtectionChecker;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class AdminController extends Controller {

    private IDBConnection $db;
    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;
    private ICacheFactory $cacheFactory;
    private IAppManager $appManager;

    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        IAppManager $appManager
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
        $this->cacheFactory = $cacheFactory;
        $this->appManager = $appManager;
    }

    /**
     * List all protected folders (admin only)
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from('folder_protection')
               ->orderBy('created_at', 'DESC');

            $result = $qb->executeQuery();
            $folders = [];

            while ($row = $result->fetch()) {
                $folders[] = [
                    'id' => (int)$row['id'],
                    'path' => $row['path'],
                    'file_id' => $row['file_id'] ? (int)$row['file_id'] : null,
                    'user_id' => $row['user_id'],
                    'created_by' => $row['created_by'],
                    'created_at' => (int)$row['created_at'],
                    'reason' => $row['reason'],
                ];
            }
            $result->closeCursor();

            // annotate groupfolders with their visible mountPoint if available
            if ($this->appManager->isInstalled('groupfolders')) {
                $mountPoints = $this->fetchGroupFolderMountPoints();
                foreach ($folders as &$folder) {
                    if (preg_match('#^/__groupfolders/(\d+)(/.*)?$#', $folder['path'], $m)) {
                        $id = (int)$m[1];
                        if (isset($mountPoints[$id])) {
                            $folder['mountPoint'] = $mountPoints[$id];
                        }
                    }
                }
                unset($folder);
            }

            return new JSONResponse([
                'success' => true,
                'folders' => $folders
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error listing protected folders', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error listing protected folders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Protect a folder (admin only)
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function protect(string $path, ?string $reason = null, ?string $userId = null): JSONResponse {
        try {
            $path = $this->protectionChecker->normalizePath($path);

            if ($this->protectionChecker->isProtected($path)) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Folder is already protected'
                ], 400);
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('folder_protection')
               ->values([
                   'path' => $qb->createNamedParameter($path),
                   'user_id' => $qb->createNamedParameter($userId),
                   'created_by' => $qb->createNamedParameter($userId),
                   'created_at' => $qb->createNamedParameter(time()),
                   'reason' => $qb->createNamedParameter($reason),
               ]);
            $qb->executeStatement();

            $this->clearCacheInternal();
            $this->logger->info('Protected folder', ['path' => $path, 'user' => $userId]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Folder protected successfully',
                'path' => $path
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error protecting folder', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error protecting folder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unprotect a folder (admin only)
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function unprotect(int $id): JSONResponse {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('folder_protection')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

            $affected = $qb->executeStatement();

            if ($affected === 0) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'Folder protection not found'
                ], 404);
            }

            $this->clearCacheInternal();
            $this->logger->info('Unprotected folder', ['id' => $id]);

            return new JSONResponse([
                'success' => true,
                'message' => 'Folder unprotected successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error unprotecting folder', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error unprotecting folder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a path is protected (accessible to all users — read-only)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function check(string $path): JSONResponse {
        try {
            $path = $this->protectionChecker->normalizePath($path);
            $isProtected = $this->protectionChecker->isProtected($path);

            return new JSONResponse([
                'success' => true,
                'path' => $path,
                'protected' => $isProtected
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error checking folder protection', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error checking folder: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear protection cache (admin only)
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function clearCache(): JSONResponse {
        try {
            $this->clearCacheInternal();

            return new JSONResponse([
                'success' => true,
                'message' => 'Cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error clearing cache', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error clearing cache: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all group folders with their protection status (admin only)
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function listGroupFolders(): JSONResponse {
        try {
            if (!$this->appManager->isInstalled('groupfolders')) {
                return new JSONResponse([
                    'success' => true,
                    'available' => false,
                    'folders' => [],
                ]);
            }

            // Fetch all group folders
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id', 'mount_point')->from('group_folders');
            $result = $qb->executeQuery();
            $groupFolders = [];
            while ($row = $result->fetch()) {
                $groupFolders[(int)$row['folder_id']] = $row['mount_point'];
            }
            $result->closeCursor();

            // Fetch which group folder paths are already protected
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('id', 'path', 'reason', 'created_by')
                ->from('folder_protection')
                ->where($qb2->expr()->like('path', $qb2->createNamedParameter('/__groupfolders/%')));
            $result2 = $qb2->executeQuery();
            $protected = [];
            while ($row = $result2->fetch()) {
                if (preg_match('#^/__groupfolders/(\d+)$#', $row['path'], $m)) {
                    $protected[(int)$m[1]] = [
                        'protection_id' => (int)$row['id'],
                        'reason'        => $row['reason'],
                        'created_by'    => $row['created_by'],
                    ];
                }
            }
            $result2->closeCursor();

            $folders = [];
            foreach ($groupFolders as $id => $mountPoint) {
                $isProtected = isset($protected[$id]);
                $folders[] = [
                    'id'           => $id,
                    'mountPoint'   => $mountPoint,
                    'path'         => '/__groupfolders/' . $id,
                    'protected'    => $isProtected,
                    'protectionId' => $isProtected ? $protected[$id]['protection_id'] : null,
                    'reason'       => $isProtected ? $protected[$id]['reason'] : null,
                    'createdBy'    => $isProtected ? $protected[$id]['created_by'] : null,
                ];
            }

            return new JSONResponse([
                'success'   => true,
                'available' => true,
                'folders'   => $folders,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error listing group folders', ['exception' => $e->getMessage()]);
            return new JSONResponse([
                'success' => false,
                'message' => 'Error listing group folders: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function clearCacheInternal(): void {
        $cache = $this->cacheFactory->createDistributed('folder_protection');
        $cache->clear();
    }

    /**
     * Get protection status for all folders (accessible to all users — used by UI badges)
     *
     * For group folder paths (/__groupfolders/N), also emits an alias at /files/<mountPoint>
     * so that folder-protection-ui.js (which builds /files/<name> paths) can match them.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getFolderStatuses(): JSONResponse {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('path', 'reason', 'created_by')
                ->from('folder_protection');

            $result = $qb->executeQuery();
            $protections = [];

            while ($row = $result->fetch()) {
                $protections[$row['path']] = [
                    'protected' => true,
                    'reason' => $row['reason'],
                    'created_by' => $row['created_by']
                ];
            }
            $result->closeCursor();

            // For group folder paths, also expose their visible mount-point path
            // so the web UI can mark them with the lock icon.
            if ($this->appManager->isInstalled('groupfolders')) {
                $mountPoints = $this->fetchGroupFolderMountPoints();
                $aliases = [];
                foreach ($protections as $path => $info) {
                    if (preg_match('#^/__groupfolders/(\d+)(/.*)?$#', $path, $m)) {
                        $folderId = (int)$m[1];
                        if (isset($mountPoints[$folderId])) {
                            $subPath = $m[2] ?? '';
                            $aliases['/files/' . $mountPoints[$folderId] . $subPath] = $info;
                        }
                    }
                }
                $protections = array_merge($protections, $aliases);
            }

            return new JSONResponse([
                'success' => true,
                'protections' => $protections
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error getting folder statuses', [
                'exception' => $e->getMessage()
            ]);

            return new JSONResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Returns a map of folder_id => mount_point from the group_folders table.
     * Only call this when the groupfolders app is confirmed to be installed.
     *
     * @return array<int, string>
     */
    private function fetchGroupFolderMountPoints(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')->from('group_folders');
        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['folder_id']] = $row['mount_point'];
        }
        $result->closeCursor();
        return $map;
    }
}
