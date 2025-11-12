<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Command;

use OCA\FolderProtection\ProtectionChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckProtection extends Command {
    
    private ProtectionChecker $protectionChecker;

    public function __construct(ProtectionChecker $protectionChecker) {
        parent::__construct();
        $this->protectionChecker = $protectionChecker;
    }

    protected function configure(): void {
        $this->setName('folder-protection:check')
            ->setDescription('Check if a folder is protected')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $path = $input->getArgument('path');
        $path = $this->protectionChecker->normalizePath($path);

        $isProtected = $this->protectionChecker->isProtected($path);
        $isParentProtected = $this->protectionChecker->isProtectedOrParentProtected($path);

        $output->writeln(sprintf('Path: <info>%s</info>', $path));
        $output->writeln('');

        if ($isProtected) {
            $output->writeln('<error>✗ This folder is PROTECTED</error>');
            $output->writeln('  Cannot be moved, copied, or deleted');
        } elseif ($isParentProtected) {
            $output->writeln('<comment>⚠ A parent folder is PROTECTED</comment>');
            $output->writeln('  Operations on this path may be restricted');
        } else {
            $output->writeln('<info>✓ This folder is NOT protected</info>');
        }

        return 0;
    }
}
