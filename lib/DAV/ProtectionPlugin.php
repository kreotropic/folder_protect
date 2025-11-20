<?php
namespace OCA\FolderProtection\DAV;

/**
 * ProtectionPlugin
 *
 * Papel: Plugin do SabreDAV que aplica as regras de proteção de pastas (via `ProtectionChecker`) às
 * operações WebDAV. Este ficheiro contém as validações centrais que recusam operações quando a
 * pasta alvo ou algum ancestral está marcada como protegida.
 *
 * Integração / pontos importantes:
 * - Recebe um `ProtectionChecker` (regras de negócio) e um `Psr\Log\LoggerInterface`.
 * - Regista handlers para eventos do SabreDAV: beforeBind, beforeUnbind, beforeMove, beforeCopy,
 *   beforeWriteContent, beforeCreateFile, beforeCreateDirectory, propPatch, beforeLock e beforeMethod.
 * - Usa `$server->tree->getNodeForPath` para resolver URIs para caminhos internos; quando a node não
 *   existe (caso comum para operações de criação/movimento) recorre a um fallback por expressão regular
 *   que remove o prefixo `/remote.php/(web)?dav/files/{user}`.
 *
 * Métodos do `ProtectionChecker` utilizados (exemplos):
 * - `isProtected($path)` — verifica proteção exata do caminho.
 * - `isProtectedOrParentProtected($path)` — verifica se o caminho ou algum pai está protegido.
 * - `isAnyProtectedWithBasename($basename)` — evita criar entradas com o mesmo nome base que uma pasta protegida.
 *
 * Observações para agentes/colaboradores:
 * - Não alteres a lógica de decisão (throw/403) a menos que entendas o impacto nos clients WebDAV.
 * - Registos (`$this->logger`) ajudam a diagnosticar requests que chegam com URIs não resolvíveis.
 */

use OCA\DAV\Connector\Sabre\Node;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\FolderProtection\ProtectionChecker;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception\Locked;
use Sabre\DAV\INode;
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

        // Hooks principais
        $server->on('beforeBind', [$this, 'beforeBind'], 10);
        $server->on('beforeUnbind', [$this, 'beforeUnbind'], 10);
        $server->on('beforeMove', [$this, 'beforeMove'], 10);
        $server->on('beforeCopy', [$this, 'beforeCopy'], 10);
        //$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 10);
        //$server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 10);
        //$server->on('beforeCreateDirectory', [$this, 'beforeCreateDirectory'], 10);
        $server->on('propPatch', [$this, 'propPatch'], 10);
        $server->on('beforeLock', [$this, 'beforeLock'], 10);

        // Intercepta GET/PROPFIND/COPY (downloads/listagens)
        $server->on('beforeMethod', [$this, 'beforeMethod'], 10);

        $this->logger->info('FolderProtection: WebDAV plugin initialized successfully');
    }

    // Bloqueia GET/PROPFIND/COPY
    public function beforeMethod($request, $response) {
        $raw = $request->getPath();
        $path = $this->getInternalPath($raw);
        $method = $request->getMethod();

        $this->logger->debug("FolderProtection DAV: beforeMethod: $method -> raw='$raw' internal='$path'");

        // métodos que devem ser bloqueados de leitura/cópia
        if (in_array($method, ['GET','PROPFIND','COPY'])) {
            if ($this->protectionChecker->isProtectedOrParentProtected($path)) {
                $this->logger->warning("FolderProtection DAV: Blocking $method on protected path: $path");
                throw new Locked('Cannot read or copy protected folders');
            }
        }

        // bloqueia criação de pastas (MKCOL)
        if ($method === 'MKCOL') {
            if ($this->protectionChecker->isProtectedOrParentProtected($path) ||
                $this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
                $this->logger->warning("FolderProtection DAV: Blocking MKCOL (create dir) on protected/forbidden path: $path");
                throw new Locked('Cannot create directories in or with the name of protected folders');
            }
        }

        // bloqueia writes directos (PUT)
        if ($method === 'PUT') {
            if ($this->protectionChecker->isProtectedOrParentProtected($path) ||
                $this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
                $this->logger->warning("FolderProtection DAV: Blocking PUT on protected/forbidden path: $path");
                throw new Locked('Cannot upload/write files into protected folders');
            }
        }
    }

    private function getInternalPath($uri) {
        try {
            $node = $this->server->tree->getNodeForPath($uri);
            if ($node instanceof Node) {
                return $node->getPath();
            }
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            // Node não existe — isto é normal para operações que apontam para caminhos que ainda não existem
            // (ex: MKCOL, MOVE para destino que será criado). Regista debug para ajudar a diagnosticar URIs.
            $this->logger->debug('FolderProtection: Node not found, extracting path from URI', ['uri' => $uri]);
        } catch (\Exception $e) {
            $this->logger->debug('FolderProtection: Error getting node, extracting path from URI', [
                'uri' => $uri,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback: extrair caminho do URI
        // O regex remove o prefixo padrão que o Nextcloud usa para DAV: /remote.php/dav/files/{user}
        // Ex.: /remote.php/dav/files/alice/Documents/Prot -> /Documents/Prot
        $path = preg_replace('#^/remote\.php/(?:web)?dav/files/[^/]+#', '', $uri);
        return '/' . ltrim($path, '/');
    }

    // Bloqueia criação de ficheiros ou pastas
    public function beforeBind($uri) {
        $path = $this->getInternalPath($uri);
        $this->logger->debug("FolderProtection DAV: beforeBind checking '$path'");
        if ($this->protectionChecker->isProtectedOrParentProtected($path) ||
            $this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
            $this->logger->warning("FolderProtection DAV: Blocking bind in protected path: $path");
            throw new Locked('Cannot create items in protected folders');
        }
    }

    // public function beforeCreateFile($uri, &$data, INode $parent, &$modified) {
    //     $path = $this->getInternalPath($uri);
    //     $this->logger->debug("FolderProtection DAV: beforeCreateFile checking '$path'");
    //     if ($this->protectionChecker->isProtectedOrParentProtected($path) ||
    //         $this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
    //         $this->logger->warning("FolderProtection DAV: Blocking file creation in protected path: $path");
    //         throw new Locked('Cannot create files in protected folders');
    //     }
    // }

    // public function beforeCreateDirectory($uri) {
    //     $path = $this->getInternalPath($uri);
    //     $this->logger->debug("FolderProtection DAV: beforeCreateDirectory checking '$path'");
    //     if ($this->protectionChecker->isProtectedOrParentProtected($path) ||
    //         $this->protectionChecker->isAnyProtectedWithBasename(basename($path))) {
    //         $this->logger->warning("FolderProtection DAV: Blocking directory creation in protected path: $path");
    //         throw new Locked('Cannot create directories in protected folders');
    //     }
    // }


        // Bloqueia deleção
        public function beforeUnbind($uri) {
            $path = $this->getInternalPath($uri);
            
            $this->logger->info("FolderProtection DAV: beforeUnbind CALLED!", [
                'uri' => $uri,
                'internalPath' => $path
            ]);
            
            // Tentar várias variações do path
            $pathsToCheck = [
                $path,
                '/files' . $path,
                preg_replace('#^/files/#', '/', $path),
            ];
            
            foreach ($pathsToCheck as $checkPath) {
                $this->logger->debug("FolderProtection DAV: Checking path variant: $checkPath");
                if ($this->protectionChecker->isProtected($checkPath)) {
                    $this->logger->warning("FolderProtection DAV: Blocking delete - matched protected path", [
                        'uri' => $uri,
                        'matchedPath' => $checkPath
                    ]);
                    throw new Locked('Cannot delete protected folder: ' . basename($path));
                }
            }
            
            $this->logger->debug("FolderProtection DAV: Delete allowed for '$path'");
        }

    // Bloqueia movimentos
    public function beforeMove($sourcePath, $destinationPath) {
        $src = $this->getInternalPath($sourcePath);
        $dest = $this->getInternalPath($destinationPath);
        
        $this->logger->info("FolderProtection DAV: beforeMove checking", [
            'sourceUri' => $sourcePath,
            'destUri' => $destinationPath,
            'sourceInternal' => $src,
            'destInternal' => $dest
        ]);
        
        // Bloquear se a ORIGEM está protegida
        if ($this->protectionChecker->isProtected($src)) {
            $this->logger->warning("FolderProtection DAV: Blocking move - source is protected: $src");
            throw new Locked('Cannot move protected folder: ' . basename($src));
        }
        
        // Bloquear se o DESTINO está dentro de uma pasta protegida
        if ($this->protectionChecker->isProtectedOrParentProtected($dest)) {
            $this->logger->warning("FolderProtection DAV: Blocking move - destination is protected: $dest");
            throw new Locked('Cannot move into protected folders');
        }
    }

    // Bloqueia cópias
    public function beforeCopy($sourcePath, $destinationPath) {
        $src = $this->getInternalPath($sourcePath);
        $dest = $this->getInternalPath($destinationPath);
        
        $this->logger->info("FolderProtection DAV: beforeCopy checking", [
            'sourceUri' => $sourcePath,
            'destUri' => $destinationPath,
            'sourceInternal' => $src,
            'destInternal' => $dest
        ]);
        
        // Bloquear se a ORIGEM está protegida
        if ($this->protectionChecker->isProtected($src)) {
            $this->logger->warning("FolderProtection DAV: Blocking copy - source is protected: $src");
            throw new Locked('Cannot copy protected folder: ' . basename($src));
        }
        
        // Bloquear se o DESTINO está dentro de uma pasta protegida
        if ($this->protectionChecker->isProtectedOrParentProtected($dest)) {
            $this->logger->warning("FolderProtection DAV: Blocking copy - destination is protected: $dest");
            throw new Locked('Cannot copy into protected folders');
        }
    }

    // Bloqueia escrita de conteúdo
    // public function beforeWriteContent($path, INode $node, $data, $modified) {
    //     $internalPath = $this->getInternalPath($path);
    //     $this->logger->debug("FolderProtection DAV: beforeWriteContent checking '$internalPath'");
    //     if ($this->protectionChecker->isProtected($internalPath) ||
    //         $this->protectionChecker->isProtectedOrParentProtected($internalPath)) {
    //         $this->logger->warning("FolderProtection DAV: Blocking write to protected path: $internalPath");
    //         throw new Locked('Cannot write to protected folders');
    //     }
    // }

    public function propPatch($path, \Sabre\DAV\PropPatch $propPatch) {
        $internalPath = $this->getInternalPath($path);
        if ($this->protectionChecker->isProtected($internalPath)) {
            $this->logger->debug("FolderProtection DAV: Blocking property update on '$internalPath'");
            $propPatch->setRemainingResultCode(403);
        }
    }

    public function beforeLock($uri, \Sabre\DAV\Locks\LockInfo $lock) {
        $path = $this->getInternalPath($uri);
        if ($lock->scope === \Sabre\DAV\Locks\LockInfo::EXCLUSIVE &&
            $this->protectionChecker->isProtected($path)) {
            $this->logger->warning("FolderProtection DAV: Blocking exclusive lock on protected path: $path");
            throw new Locked('Cannot lock protected folders for exclusive access');
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