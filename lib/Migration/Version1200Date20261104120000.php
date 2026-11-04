<?php

namespace OCA\Coordination\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 1.2.0 : numéro de révision des dossiers (contrôle de concurrence) et table des
 * scans PDF de pièces. Additive uniquement : aucune donnée existante n'est modifiée.
 */
class Version1200Date20261104120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('coord_dossiers')) {
            $table = $schema->getTable('coord_dossiers');
            if (!$table->hasColumn('revision')) {
                $table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0]);
                $changed = true;
            }
        }

        if (!$schema->hasTable('coord_scans')) {
            $table = $schema->createTable('coord_scans');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 11]);
            $table->addColumn('dossier_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('checklist_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('original_name', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('pages', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('uploaded_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('uploaded_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['dossier_id'], 'coord_scan_dossier_idx');
            $table->addIndex(['checklist_id'], 'coord_scan_piece_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
