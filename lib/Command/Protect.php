<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Command;

use OCA\FolderProtection\ProtectionChecker;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Protect extends Command {
    
    private IDBConnection $db;
    private ProtectionChecker $protectionChecker;

    public function __construct(IDBConnection $db, ProtectionChecker $protectionChecker) {
        parent::__construct();
        $this->db = $db;
        $this->protectionChecker = $protectionChecker;
    }

    protected function configure(): void {
        $this->setName('folder-protection:protect')
            ->setDescription('Add protection to a folder')
            ->addArgument('path', InputArgument::REQUIRED, 'Path to protect (e.g., /files/folder_name)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User who created the protection', 'admin')
            ->addOption('reason', 'r', InputOption::VALUE_REQUIRED, 'Reason for protection', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $path = $input->getArgument('path');
        $user = $input->getOption('user');
        $reason = $input->getOption('reason');

        // Normalize path
        $path = $this->protectionChecker->normalizePath($path);

        // Check if already protected
        if ($this->protectionChecker->isProtected($path)) {
            $output->writeln(sprintf('<error>Folder "%s" is already protected!</error>', $path));
            return 1;
        }

        // Insert into database
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('folder_protection')
               ->values([
                   'path' => $qb->createNamedParameter($path),
                   'user_id' => $qb->createNamedParameter($user),
                   'created_by' => $qb->createNamedParameter($user),
                   'created_at' => $qb->createNamedParameter(time()),
                   'reason' => $qb->createNamedParameter($reason),
               ]);
            $qb->executeStatement();

            $output->writeln(sprintf('<info>✓ Successfully protected folder: %s</info>', $path));
            if ($reason) {
                $output->writeln(sprintf('  Reason: %s', $reason));
            }
            return 0;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error protecting folder: %s</error>', $e->getMessage()));
            return 1;
        }
    }
}
