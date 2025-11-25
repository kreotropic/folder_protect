<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Command;

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class ListProtected extends Command {
    
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        parent::__construct();
        $this->db = $db;
    }

    protected function configure(): void {
        $this->setName('folder-protection:list')
            ->setDescription('List all protected folders');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('folder_protection')
           ->orderBy('created_at', 'DESC');

        $result = $qb->executeQuery();
        $folders = [];
        
        while ($row = $result->fetch()) {
            $folders[] = [
                $row['id'],
                $row['path'],
                $row['user_id'] ?? $row['created_by'] ?? 'N/A',
                date('Y-m-d H:i:s', (int)$row['created_at']),
                $row['reason'] ?? 'N/A',
            ];
        }
        $result->closeCursor();

        if (empty($folders)) {
            $output->writeln('<info>No protected folders found.</info>');
            return 0;
        }

        $output->writeln(sprintf('<info>Found %d protected folder(s):</info>', count($folders)));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['ID', 'Path', 'Created By', 'Created At', 'Reason']);
        $table->setRows($folders);
        $table->render();

        return 0;
    }
}
