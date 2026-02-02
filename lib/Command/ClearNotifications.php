<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Command;

use OCA\FolderProtection\ProtectionChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ClearNotifications extends Command {
    private ProtectionChecker $protectionChecker;

    public function __construct(ProtectionChecker $protectionChecker) {
        parent::__construct();
        $this->protectionChecker = $protectionChecker;
    }

    protected function configure() {
        $this
            ->setName('folder-protection:clear-notifications')
            ->setDescription('Clears the notification rate-limit cache to allow testing notifications immediately');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $this->protectionChecker->clearCache();
        $output->writeln('<info>Notification cache (and protection cache) cleared successfully.</info>');
        return 0;
    }
}