<?php
namespace OCA\FolderProtection\Migration;

use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\ISchemaWrapper;

class Version001000000Date20240101120000 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
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
            $table->addIndex(['created_at'], 'folder_protection_created_idx');
        }

        return $schema;
    }
}
