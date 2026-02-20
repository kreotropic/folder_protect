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
        $path = $this->getNodePath($node);

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

        // 4. Remover 'D' (delete) das permissões OwnCloud para pastas protegidas.
        // O cliente desktop Nextcloud lê oc:permissions e só tenta DELETE se 'D' estiver presente.
        // Corremos com prioridade 150 (depois do FilesPlugin a 100) para poder sobrepor o valor.
        if ($isProtected) {
            $currentPerms = $propFind->get(self::PROP_OC_PERMISSIONS);
            if (is_string($currentPerms) && str_contains($currentPerms, 'D')) {
                $newPerms = str_replace('D', '', $currentPerms);
                $propFind->set(self::PROP_OC_PERMISSIONS, $newPerms);
                $this->logger->debug("FolderProtection: Removed 'D' from oc:permissions for '$path': '$currentPerms' → '$newPerms'");
            }
        }
    }

    /**
     * Extrair path interno do node
     */
    private function getNodePath(INode $node): string {
        try {
            if (method_exists($node, 'getFileInfo')) {
                $fileInfo = $node->getFileInfo();
                $path = $fileInfo->getInternalPath();
                
                // Normalizar path: deve começar com /files/
                if (strpos($path, '__groupfolders/') === 0) {
                    // Group folder: /__groupfolders/ID ou /files/nome
                    // Vamos usar o path como está
                    return '/' . $path;
                }
                
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