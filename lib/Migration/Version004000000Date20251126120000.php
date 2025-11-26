<?php

declare(strict_types=1);

namespace OCA\FolderProtection\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Consolidated migration: creates the folder_protection table with complete schema.
 * 
 * This migration consolidates the initial setup:
 * - id: primary key (auto-increment)
 * - path: full path of protected folder (unique, indexed)
 * - file_id: optional file_id reference for DAV operations
 * - user_id: optional user who created the protection
 * - created_at: timestamp of protection creation
 * - created_by: username who created the protection (for display)
 * - reason: optional text explaining why the folder is protected
 * 
 * The table is idempotent: safe to run on systems that already have the table.
 */
class Version004000000Date20251126120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('folder_protection')) {
            $table = $schema->createTable('folder_protection');

            // Primary key: auto-incrementing ID
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);

            // Path: the full folder path (main identifier)
            $table->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
            ]);

            // Optional file_id: for integration with Nextcloud file operations
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => false,
            ]);

            // Optional user_id: user who initiated protection
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);

            // Timestamp: when protection was created
            $table->addColumn('created_at', Types::INTEGER, [
                'notnull' => true,
            ]);

            // Optional created_by: username for display (human-readable)
            $table->addColumn('created_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);

            // Optional reason: why the folder is protected
            $table->addColumn('reason', Types::TEXT, [
                'notnull' => false,
            ]);

            // Set primary key
            $table->setPrimaryKey(['id']);

            // Indexes for common queries
            $table->addUniqueIndex(['path'], 'folder_protection_path_idx');
            $table->addIndex(['file_id'], 'folder_protection_file_id_idx');
            $table->addIndex(['created_at'], 'folder_protection_created_at_idx');

            $output->info('Created folder_protection table with consolidated schema');
        } else {
            $output->info('folder_protection table already exists, skipping creation');
        }

        return $schema;
    }
}
