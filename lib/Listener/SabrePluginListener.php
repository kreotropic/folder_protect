<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\FolderProtection\DAV\ProtectionPlugin;
use OCA\FolderProtection\ProtectionChecker;
use OCA\FolderProtection\DAV\ProtectionPropertyPlugin; 
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

class SabrePluginListener implements IEventListener {
    private ProtectionChecker $protectionChecker;
    private LoggerInterface $logger;

    public function __construct(
        ProtectionChecker $protectionChecker,
        LoggerInterface $logger
    ) {
        $this->protectionChecker = $protectionChecker;
        $this->logger = $logger;
    }

public function handle(Event $event): void {
    if (!($event instanceof SabrePluginAuthInitEvent)) {
        return;
    }

    $this->logger->info('FolderProtection: SabrePluginAuthInitEvent received, adding WebDAV plugins');

    try {
        $server = $event->getServer();
        
        // Plugin 1: Bloqueia operações (JÁ EXISTIA)
        $protectionPlugin = new ProtectionPlugin($this->protectionChecker, $this->logger);
        $server->addPlugin($protectionPlugin);
        
        // Plugin 2: Responde a PROPFIND (NOVO)
        $propertyPlugin = new ProtectionPropertyPlugin($this->protectionChecker, $this->logger);
        $server->addPlugin($propertyPlugin);
        
        $this->logger->info('FolderProtection: Both WebDAV plugins added successfully (Protection + Properties)');
    } catch (\Exception $e) {
        $this->logger->error('FolderProtection: Failed to add WebDAV plugins', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
}
