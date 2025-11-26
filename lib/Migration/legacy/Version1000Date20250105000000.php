<?php
namespace OCA\FolderProtection\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20250105000000 extends SimpleMigrationStep {
    
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('folder_protection')) {
            $table = $schema->createTable('folder_protection');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('path', 'string', [
                'notnull' => true,
                'length' => 4000,
            ]);
            $table->addColumn('file_id', 'bigint', [
                'notnull' => false,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('created_at', 'integer', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['path'], 'fp_path_index');
            $table->addIndex(['file_id'], 'fp_file_id_index');
        }

        return $schema;
    }
}
