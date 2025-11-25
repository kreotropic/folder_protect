<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Command;

use OCA\FolderProtection\ProtectionChecker;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Unprotect extends Command {
    
    private IDBConnection $db;
    private ProtectionChecker $protectionChecker;

    public function __construct(IDBConnection $db, ProtectionChecker $protectionChecker) {
        parent::__construct();
        $this->db = $db;
        $this->protectionChecker = $protectionChecker;
    }

    protected function configure(): void {
        $this->setName('folder-protection:unprotect')
            ->setDescription('Remove protection from a folder')
            ->addArgument('identifier', InputArgument::REQUIRED, 'Path or ID of protected folder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $identifier = $input->getArgument('identifier');

        // Check if identifier is numeric (ID) or path
        if (is_numeric($identifier)) {
            return $this->unprotectById((int)$identifier, $input, $output);
        } else {
            return $this->unprotectByPath($identifier, $input, $output);
        }
    }

    private function unprotectById(int $id, InputInterface $input, OutputInterface $output): int {
        // Get folder info first
        $qb = $this->db->getQueryBuilder();
        $qb->select('path')
           ->from('folder_protection')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            $output->writeln(sprintf('<error>No protected folder found with ID: %d</error>', $id));
            return 1;
        }

        $path = $row['path'];

        // Ask for confirmation
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            sprintf('Remove protection from "%s"? [y/N] ', $path),
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('<comment>Cancelled.</comment>');
            return 0;
        }

        // Delete
        $qb = $this->db->getQueryBuilder();
        $qb->delete('folder_protection')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        
        $affected = $qb->executeStatement();

        if ($affected > 0) {
            $output->writeln(sprintf('<info>✓ Successfully removed protection from: %s</info>', $path));
            return 0;
        }

        $output->writeln('<error>Failed to remove protection.</error>');
        return 1;
    }

    private function unprotectByPath(string $path, InputInterface $input, OutputInterface $output): int {
        $path = $this->protectionChecker->normalizePath($path);

        if (!$this->protectionChecker->isProtected($path)) {
            $output->writeln(sprintf('<error>Folder "%s" is not protected!</error>', $path));
            return 1;
        }

        // Ask for confirmation
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            sprintf('Remove protection from "%s"? [y/N] ', $path),
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('<comment>Cancelled.</comment>');
            return 0;
        }

        // Delete
        $qb = $this->db->getQueryBuilder();
        $qb->delete('folder_protection')
           ->where($qb->expr()->eq('path', $qb->createNamedParameter($path)));
        
        $affected = $qb->executeStatement();

        if ($affected > 0) {
            $output->writeln(sprintf('<info>✓ Successfully removed protection from: %s</info>', $path));
            return 0;
        }

        $output->writeln('<error>Failed to remove protection.</error>');
        return 1;
    }
}
