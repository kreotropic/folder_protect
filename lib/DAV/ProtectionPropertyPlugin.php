<?php
declare(strict_types=1);

namespace OCA\FolderProtection\DAV;

use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\INode;
use Psr\Log\LoggerInterface;

/**
 * Plugin que adiciona propriedades DAV customizadas para folders protegidos
 */
class ProtectionPropertyPlugin extends ServerPlugin {
    
    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;
    private ?\Sabre\DAV\Server $server = null;

    // Namespace e propriedades customizadas
    const NS_NEXTCLOUD = 'http://nextcloud.org/ns';
    const PROP_IS_PROTECTED = '{http://nextcloud.org/ns}is-protected';
    const PROP_PROTECTION_REASON = '{http://nextcloud.org/ns}protection-reason';
    const PROP_IS_DELETABLE = '{http://nextcloud.org/ns}is-deletable';
    const PROP_IS_RENAMEABLE = '{http://nextcloud.org/ns}is-renameable';
    const PROP_IS_MOVEABLE = '{http://nextcloud.org/ns}is-moveable';

    // Propriedade OwnCloud de permissões — lida pelo cliente desktop para decidir operações
    const PROP_OC_PERMISSIONS = '{http://owncloud.org/ns}permissions';

    public function __construct(
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger
    ) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

    /**
     * Inicializar plugin no servidor DAV
     */
    public function initialize(\Sabre\DAV\Server $server): void {
        $this->server = $server;
        // Prioridade 150: corre DEPOIS do FilesPlugin do core (prioridade 100 default)
        // para podermos ler e sobrepor oc:permissions já calculado pelo core
        $server->on('propFind', [$this, 'propFind'], 150);

        $this->logger->debug('FolderProtection: ProtectionPropertyPlugin initialized');
    }

    /**
     * Handler para PROPFIND - adiciona propriedades customizadas
     */
    public function propFind(PropFind $propFind, INode $node): void {
        $path = $this->getNodePath($node, $propFind->getPath());

        // Só aplicar a ficheiros e diretórios do Nextcloud
        if (!($node instanceof \OCA\DAV\Connector\Sabre\Directory) && 
            !($node instanceof \OCA\DAV\Connector\Sabre\File)) {
            return;
        }

        // Se não conseguimos determinar o path, skip
        if (empty($path)) {
            return;
        }

        // Verificar proteção uma vez
        $isProtected = $this->protectionChecker->isProtected($path);
        $protectionInfo = $isProtected ? $this->protectionChecker->getProtectionInfo($path) : null;

        $this->logger->debug("FolderProtection PROPFIND: path='$path', protected=" . ($isProtected ? 'yes' : 'no'));

        // 1. Flag: está protegido?
        $propFind->handle(self::PROP_IS_PROTECTED, function() use ($isProtected) {
            return $isProtected ? 'true' : 'false';
        });

        // 2. Razão da proteção
        $propFind->handle(self::PROP_PROTECTION_REASON, function() use ($protectionInfo) {
            return $protectionInfo['reason'] ?? '';
        });

        // 3. Meta-propriedades para controlar UI do cliente
        $propFind->handle(self::PROP_IS_DELETABLE, function() use ($isProtected) {
            return $isProtected ? 'false' : 'true';
        });

        $propFind->handle(self::PROP_IS_RENAMEABLE, function() use ($isProtected) {
            return $isProtected ? 'false' : 'true';
        });

        $propFind->handle(self::PROP_IS_MOVEABLE, function() use ($isProtected) {
            return $isProtected ? 'false' : 'true';
        });

        // 4. Remover 'D' (delete), 'N' (rename) e 'V' (move) das permissões OwnCloud para pastas protegidas.
        // O cliente desktop lê oc:permissions antes de tentar qualquer operação:
        //   'D' ausente → não tenta DELETE
        //   'N' ausente → não tenta rename (evita o fallback copy-then-delete)
        //   'V' ausente → não tenta MOVE para outra localização
        // Corremos com prioridade 150 (depois do FilesPlugin a 100) para poder sobrepor o valor.
        if ($isProtected) {
            $currentPerms = $propFind->get(self::PROP_OC_PERMISSIONS);
            if (is_string($currentPerms)) {
                $newPerms = str_replace(['D', 'N', 'V'], '', $currentPerms);
                if ($newPerms !== $currentPerms) {
                    $propFind->set(self::PROP_OC_PERMISSIONS, $newPerms);
                    $this->logger->debug("FolderProtection: Restricted oc:permissions for '$path': '$currentPerms' → '$newPerms'");
                }
            }
        }
    }

    /**
     * Extrair path interno do node, com suporte a group folders.
     *
     * @param INode  $node  DAV node being inspected
     * @param string $uri   Path of the node relative to the DAV tree root (e.g. 'files/ncadmin/folder').
     *                      Provided by PropFind::getPath() — already in the correct format for regular nodes.
     */
    private function getNodePath(INode $node, string $uri = ''): string {
        try {
            if (method_exists($node, 'getFileInfo')) {
                $fileInfo = $node->getFileInfo();

                // Detect group folder: traverse storage wrapper chain
                $folderId = $this->getGroupFolderIdFromStorage($fileInfo->getStorage());
                if ($folderId !== null) {
                    $subPath = $fileInfo->getInternalPath();
                    $groupPath = '/__groupfolders/' . $folderId;
                    if (!empty($subPath) && $subPath !== '.') {
                        $groupPath .= '/' . ltrim($subPath, '/');
                    }
                    return $groupPath;
                }

                // Regular file/folder: $uri from PropFind is already 'files/username/path'.
                // (getInternalPath() omits the username, so we cannot use it directly.)
                if (!empty($uri) && (strpos($uri, 'files/') === 0 || strpos($uri, '__groupfolders/') === 0)) {
                    return '/' . $uri;
                }

                // Fallback (should rarely be reached)
                $path = $fileInfo->getInternalPath();
                if (strpos($path, 'files/') !== 0) {
                    $path = 'files/' . ltrim($path, '/');
                }
                return '/' . $path;
            }
        } catch (\Exception $e) {
            $this->logger->error('FolderProtection: Error getting node path', [
                'exception' => $e->getMessage()
            ]);
        }

        return '';
    }

    /**
     * Traverse the storage wrapper chain to find a GroupFolder storage with getFolderId().
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

    /**
     * Nome do plugin
     */
    public function getPluginName(): string {
        return 'folder-protection-properties';
    }

    /**
     * Features suportadas
     */
    public function getFeatures(): array {
        return ['folder-protection'];
    }
}