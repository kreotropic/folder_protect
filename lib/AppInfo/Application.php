<?php
declare(strict_types=1);

namespace OCA\FolderProtection\AppInfo;

use OC\Files\Filesystem;
use OCA\FolderProtection\ProtectionChecker;
use OCA\FolderProtection\StorageWrapper;
use OCA\FolderProtection\DAV\ProtectionPlugin;
use OCA\FolderProtection\DAV\ProtectionPropertyPlugin;
use OCA\FolderProtection\DAV\LockPlugin;
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

/**
 * Application
 *
 * Classe principal da aplicação FolderProtection que integra com o framework Nextcloud.
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
class Application extends App implements IBootstrap {
    public const APP_ID = 'folder_protection';

    /**
     * Construtor da aplicação.
     * Chama o construtor pai com o ID da aplicação e parâmetros de URL.
     */
    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    /**
     * Regista serviços, listeners e hooks necessários para o funcionamento da aplicação.
     *
     * Explicação leiga:
     * - "Serviço": um objeto que está disponível em todo o código (como um assistente central).
     * - "Listener": um código que "ouve" eventos do sistema e reage (ex: quando o DAV inicia).
     * - "Hook": uma ação disparada em pontos específicos (ex: antes de o filesystem ser configurado).
     *
     * Aqui registamos:
     * 1. ProtectionChecker: o serviço que verifica se uma pasta está protegida.
     * 2. SabrePluginListener: reage a eventos do WebDAV/DAV.
     * 3. Comandos CLI: ferramentas de linha de comando para gerenciar proteções.
     * 4. AdminSettings: configurações da página de admin.
     * 5. Hook: adicionará o StorageWrapper quando o filesystem se inicializar.
     */
    public function register(IRegistrationContext $context): void {
        // Regista o ProtectionChecker como serviço singleton
        $context->registerService(ProtectionChecker::class, function ($c) {
            return new ProtectionChecker(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\ICacheFactory::class)
            );
        });

        // Regista o ProtectionPlugin (Lógica DAV)
        $context->registerService(ProtectionPlugin::class, function ($c) {
            return new ProtectionPlugin(
                $c->get(ProtectionChecker::class),
                $c->get(LoggerInterface::class)
            );
        });

        // Regista o ProtectionPropertyPlugin (Propriedades DAV)
        $context->registerService(ProtectionPropertyPlugin::class, function ($c) {
            return new ProtectionPropertyPlugin(
                $c->get(ProtectionChecker::class),
                $c->get(LoggerInterface::class)
            );
        });

        // Regista o LockPlugin (Gerência automática de locks WebDAV)
        $context->registerService(LockPlugin::class, function ($c) {
            return new LockPlugin(
                $c->get(ProtectionChecker::class),
                $c->get(LoggerInterface::class)
            );
        });

        // Regista o listener para eventos DAV
        $context->registerEventListener(
            SabrePluginAuthInitEvent::class,
            SabrePluginListener::class
        );

        // Regista os comandos CLI
        $context->registerService(\OCA\FolderProtection\Command\ListProtected::class, function ($c) {
            return new \OCA\FolderProtection\Command\ListProtected(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\FolderProtection\Command\Protect::class, function ($c) {
            return new \OCA\FolderProtection\Command\Protect(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });

        $context->registerService(\OCA\FolderProtection\Command\Unprotect::class, function ($c) {
            return new \OCA\FolderProtection\Command\Unprotect(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });

        $context->registerService(\OCA\FolderProtection\Command\CheckProtection::class, function ($c) {
            return new \OCA\FolderProtection\Command\CheckProtection(
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });

        $context->registerService(\OCA\FolderProtection\Command\ClearNotifications::class, function ($c) {
            return new \OCA\FolderProtection\Command\ClearNotifications(
                $c->get(\OCA\FolderProtection\ProtectionChecker::class)
            );
        });

        // Regista as definições de admin
        $context->registerService(\OCA\FolderProtection\Settings\AdminSettings::class, function ($c) {
            return new \OCA\FolderProtection\Settings\AdminSettings();
        });

        // Regista o Notifier para notificações
        $context->registerService(\OCA\FolderProtection\Notification\Notifier::class, function ($c) {
            return new \OCA\FolderProtection\Notification\Notifier(
                $c->get(\OCP\L10N\IFactory::class)
            );
        });

        // Regista o hook que irá adicionar o StorageWrapper
        Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addStorageWrapper');
    }

    /**
     * Inicializa a aplicação após o boot do Nextcloud.
     *
     * Nesta fase:
     * - Carrega a script da UI (interface de utilizador).
     * - Regista mensagens de log para debugging.
     */
    public function boot(IBootContext $context): void {
        error_log("DEBUG_FP: Application boot started for folder_protection");
        $logger = $context->getServerContainer()->get(LoggerInterface::class);
        $logger->info('FolderProtection: Application boot completed', ['app' => self::APP_ID]);
        
        // ✅ Carregar script SEMPRE (não apenas em Files)
        \OCP\Util::addScript(self::APP_ID, 'folder-protection-ui');
        
        $logger->debug('FolderProtection: UI script registered globally');
    }

    /**
     * Hook chamado quando o filesystem é configurado.
     * Adiciona o StorageWrapper com prioridade negativa (-10) para garantir
     * que seja executado cedo no pipeline.
     *
     * @internal
     */
    public function addStorageWrapper(): void {
        // Adiciona wrapper com prioridade negativa (prioritário)
        Filesystem::addStorageWrapper('folder_protection', [$this, 'addStorageWrapperCallback'], -10);
    }

    /**
     * Callback que encapsula (wrap) cada storage do filesystem.
     *
     * Explicação leiga:
     * - Cada "mountPoint" é um local onde ficheiros estão armazenados.
     * - Colocamos o StorageWrapper "em volta" desse storage.
     * - Não aplicamos em ambiente CLI nem na raiz '/' para evitar problemas.
     *
     * @internal
     */
    public function addStorageWrapperCallback(string $mountPoint, IStorage $storage): IStorage {
        // Só aplica o wrapper se não estamos em CLI e não é a raiz
        if (!OC::$CLI && $mountPoint !== '/') {
            $protectionChecker = $this->getContainer()->get(ProtectionChecker::class);
            return new StorageWrapper([
                'storage' => $storage,
                'protectionChecker' => $protectionChecker,
            ]);
        }

        return $storage;
    }

    /**
     * Returns the list of commands for this app.
     *
     * @return array
     */
    public function getCommands(): array {
        return [
            \OCA\FolderProtection\Command\ListProtected::class,
            \OCA\FolderProtection\Command\Protect::class,
            \OCA\FolderProtection\Command\Unprotect::class,
            \OCA\FolderProtection\Command\CheckProtection::class,
            \OCA\FolderProtection\Command\ClearNotifications::class,
        ];
    }
}
