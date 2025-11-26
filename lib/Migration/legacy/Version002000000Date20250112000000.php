<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add missing columns to folder_protection table:
 * - created_by (copy of user_id for compatibility)
 * - reason (optional field for protection reason)
 */
class Version002000000Date20250112000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('folder_protection')) {
            $table = $schema->getTable('folder_protection');
            $hasChanges = false;

            // Add 'created_by' column if it doesn't exist (alias for user_id)
            if (!$table->hasColumn('created_by')) {
                $output->info('Adding created_by column to folder_protection table');
                $table->addColumn('created_by', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $hasChanges = true;
            }

            // Add 'reason' column if it doesn't exist
            if (!$table->hasColumn('reason')) {
                $output->info('Adding reason column to folder_protection table');
                $table->addColumn('reason', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                $hasChanges = true;
            }

            if ($hasChanges) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Copy data from user_id to created_by for compatibility
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $connection = \OC::$server->getDatabaseConnection();
        
        try {
            // Copy user_id to created_by for existing records
            $sql = "UPDATE *PREFIX*folder_protection SET created_by = user_id WHERE created_by IS NULL AND user_id IS NOT NULL";
            $connection->executeStatement($sql);
            $output->info('Copied user_id data to created_by column');
        } catch (\Exception $e) {
            $output->warning('Could not copy user_id to created_by: ' . $e->getMessage());
        }
    }
}
