<?php

namespace OCA\Coordination\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261027160000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // 1. Table coord_dossiers
        if (!$schema->hasTable('coord_dossiers')) {
            $table = $schema->createTable('coord_dossiers');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('case_number', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('client_name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('phone', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('address', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('wilaya', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('commune', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('bank', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'default' => 'new',
                'length' => 64,
            ]);
            $table->addColumn('assigned_coordinator', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('appointment_date', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('appointment_time', Types::STRING, [
                'notnull' => false,
                'length' => 16,
            ]);
            $table->addColumn('appointment_notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('custom_data', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['case_number'], 'coord_case_idx');
            $table->addIndex(['status'], 'coord_status_idx');
            $table->addIndex(['wilaya'], 'coord_wilaya_idx');
            $table->addIndex(['assigned_coordinator'], 'coord_coord_idx');
        }

        // 2. Table coord_checklists
        if (!$schema->hasTable('coord_checklists')) {
            $table = $schema->createTable('coord_checklists');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('dossier_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('document_code', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('document_label_fr', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('document_label_ar', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('is_mandatory', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
            ]);
            $table->addColumn('coordinator_checked', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('coordinator_notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('coordinator_checked_at', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('backoffice_checked', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('backoffice_notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('backoffice_checked_at', Types::BIGINT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['dossier_id'], 'coord_chk_dossier_idx');
        }

        // 3. Table coord_histories
        if (!$schema->hasTable('coord_histories')) {
            $table = $schema->createTable('coord_histories');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('dossier_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('action', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('details', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['dossier_id'], 'coord_hist_dossier_idx');
        }

        // 4. Table coord_configs
        if (!$schema->hasTable('coord_configs')) {
            $table = $schema->createTable('coord_configs');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('config_key', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('config_value', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['config_key'], 'coord_cfg_key_uniq');
        }

        return $schema;
    }
}
