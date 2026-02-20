<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Controller;

use OCA\FolderProtection\ProtectionChecker;
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

    public function __construct(
        string $appName,
        IRequest $request,
        IDBConnection $db,
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger,
        ICacheFactory $cacheFactory
    ) {
        parent::__construct($appName, $request);
        $this->db = $db;
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
        $this->cacheFactory = $cacheFactory;
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

    private function clearCacheInternal(): void {
        $cache = $this->cacheFactory->createDistributed('folder_protection');
        $cache->clear();
    }

    /**
     * Get protection status for all folders (accessible to all users — used by UI badges)
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
}
