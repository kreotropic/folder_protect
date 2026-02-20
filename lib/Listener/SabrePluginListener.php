<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\FolderProtection\DAV\ProtectionPlugin;
use OCA\FolderProtection\DAV\ProtectionPropertyPlugin;
use OCA\FolderProtection\DAV\LockPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

class SabrePluginListener implements IEventListener {
    private ProtectionPlugin $protectionPlugin;
    private ProtectionPropertyPlugin $propertyPlugin;
    private LockPlugin $lockPlugin;
    private LoggerInterface $logger;

    public function __construct(
        ProtectionPlugin $protectionPlugin,
        ProtectionPropertyPlugin $propertyPlugin,
        LockPlugin $lockPlugin,
        LoggerInterface $logger
    ) {
        $this->protectionPlugin = $protectionPlugin;
        $this->propertyPlugin = $propertyPlugin;
        $this->lockPlugin = $lockPlugin;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        if (!($event instanceof SabrePluginAuthInitEvent)) {
            return;
        }
        error_log("DEBUG_FP: SabrePluginListener handle called - Adding plugins to DAV server");

        $this->logger->info('FolderProtection: SabrePluginAuthInitEvent received, adding WebDAV plugins');

        try {
            $server = $event->getServer();

            // Adiciona os três plugins em ordem de prioridade:
            // 1. LockPlugin - Gerencia locks automáticos (prioridade mais alta)
            // 2. ProtectionPlugin - Bloqueia operações inválidas  
            // 3. ProtectionPropertyPlugin - Reporta propriedades ao cliente
            $server->addPlugin($this->lockPlugin);
            $server->addPlugin($this->protectionPlugin);
            $server->addPlugin($this->propertyPlugin);

            $this->logger->info('FolderProtection: All WebDAV plugins added successfully (Lock + Protection + Properties)');
        } catch (\Exception $e) {
            $this->logger->error('FolderProtection: Failed to add WebDAV plugins', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
