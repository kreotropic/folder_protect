<?php
declare(strict_types=1);

namespace OCA\FolderProtection\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Canonical migration representing the current schema used in production.
 *
 * Creates `folder_protection` table with the superset of columns observed
 * in existing installations (including file_id, user_id, created_by, reason).
 */
class Version003000000Date20251126000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('folder_protection')) {
            $table = $schema->createTable('folder_protection');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);

            $table->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
            ]);

            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => false,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);

            $table->addColumn('reason', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['path'], 'folder_protection_path_idx');
            $table->addIndex(['file_id'], 'folder_protection_file_id_idx');
        }

        return $schema;
    }

}
