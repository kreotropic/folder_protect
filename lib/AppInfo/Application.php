<?php
declare(strict_types=1);

namespace OCA\FolderProtection\AppInfo;

use OC\Files\Filesystem;
use OCA\FolderProtection\ProtectionChecker;
use OCA\FolderProtection\StorageWrapper;
use OCA\FolderProtection\Listener\SabrePluginListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\Files\Storage\IStorage;
use OCP\Util;
use OC;
use Psr\Log\LoggerInterface;
use OCA\DAV\Events\SabrePluginAuthInitEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'folder_protection';

    /**
     * Application (AppInfo)
     *
     * Papel: inicializa o aplicativo dentro do framework do Nextcloud/ownCloud.
     * - Regista serviços (ex: `ProtectionChecker`).
     * - Regista listeners de eventos (ex: `SabrePluginAuthInitEvent` -> `SabrePluginListener`).
     * - Liga um hook para envolver storages com o `StorageWrapper`.
     *
     * Notas importantes:
     * - O `ProtectionChecker` é exposto como serviço e injeta `IDBConnection` + `ICacheFactory`.
     * - O hook `OC_Filesystem::preSetup` é usado para chamar `addStorageWrapper`; este método usa
     *   `Filesystem::addStorageWrapper` com prioridade negativa (-10) para garantir que o wrapper
     *   é adicionado cedo (prioridade menor = executado primeiro).
     * - O wrapper não é aplicado em ambiente CLI (`OC::$CLI`) nem para o mountPoint raiz ('/'),
     *   reduzindo potenciais efeitos colaterais em tarefas em background.
     */

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Register ProtectionChecker service
        $context->registerService(ProtectionChecker::class, function($c) {
            return new ProtectionChecker(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\ICacheFactory::class)
            );
        });

        // Register the SabreDAV plugin listener
        $context->registerEventListener(
            SabrePluginAuthInitEvent::class,
            SabrePluginListener::class
        );

            $context->registerService(\OCA\FolderProtection\Command\ListProtected::class, function($c) {
            return new \OCA\FolderProtection\Command\ListProtected(
                $c->get(\OCP\IDBConnection::class)
            );
        });
        
        $context->registerService(\OCA\FolderProtection\Command\Protect::class, function($c) {
            return new \OCA\FolderProtection\Command\Protect(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });
        
        $context->registerService(\OCA\FolderProtection\Command\Unprotect::class, function($c) {
            return new \OCA\FolderProtection\Command\Unprotect(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });
        
        $context->registerService(\OCA\FolderProtection\Command\CheckProtection::class, function($c) {
            return new \OCA\FolderProtection\Command\CheckProtection(
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });

        // Register storage wrapper via hook

        Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addStorageWrapper');

// Register admin settings
$context->registerService(\OCA\FolderProtection\Settings\AdminSettings::class, function($c) {
    return new \OCA\FolderProtection\Settings\AdminSettings();
});

$eventDispatcher->addListener('OCA\Files\Event\LoadAdditionalScriptsEvent', function() {
    Util::addScript('folder_protection', 'folder-protection-ui');
});


    }

    public function boot(IBootContext $context): void {
        $logger = $context->getServerContainer()->get(LoggerInterface::class);
        $logger->info('FolderProtection: Application boot completed', ['app' => self::APP_ID]);
        
        // Register frontend assets globally
        Util::addScript(self::APP_ID, 'files-integration');
        Util::addStyle(self::APP_ID, 'files-integration');
    }

    /**
     * @internal
     */
    public function addStorageWrapper(): void {
        error_log("FolderProtection: addStorageWrapper() called via hook");
        // Add wrapper with high priority (negative = first)
        Filesystem::addStorageWrapper('folder_protection', [$this, 'addStorageWrapperCallback'], -10);
    }

    /**
     * @internal
     */
    public function addStorageWrapperCallback(string $mountPoint, IStorage $storage): IStorage {
        error_log("FolderProtection: wrapper callback for mountPoint: $mountPoint");

        if (!OC::$CLI && $mountPoint !== '/') {
            $protectionChecker = $this->getContainer()->get(ProtectionChecker::class);
            return new StorageWrapper([
                'storage' => $storage,
                'protectionChecker' => $protectionChecker,
            ]);
        }

        return $storage;
    }
}
