<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\FolderProtection\DAV\ProtectionPlugin;
use OCA\FolderProtection\ProtectionChecker;
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

        $this->logger->info('FolderProtection: SabrePluginAuthInitEvent received, adding WebDAV plugin');

        try {
            $server = $event->getServer();
            $plugin = new ProtectionPlugin($this->protectionChecker, $this->logger);
            $server->addPlugin($plugin);
            
            $this->logger->info('FolderProtection: WebDAV plugin added successfully');
        } catch (\Exception $e) {
            $this->logger->error('FolderProtection: Failed to add WebDAV plugin', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
