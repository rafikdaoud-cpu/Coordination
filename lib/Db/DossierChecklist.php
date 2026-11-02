<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\Entity;

class DossierChecklist extends Entity implements \JsonSerializable {
    protected $dossierId = 0;
    protected $documentCode = '';
    protected $documentLabelFr = '';
    protected $documentLabelAr = '';
    protected $isMandatory = 1;
    protected $coordinatorChecked = 0;
    protected $coordinatorNotes = null;
    protected $coordinatorCheckedAt = null;
    protected $backofficeChecked = 0;
    protected $backofficeNotes = null;
    protected $backofficeCheckedAt = null;

    public function jsonSerialize(): array {
        return [
            'id' => (int)$this->id,
            'dossier_id' => (int)$this->dossierId,
            'document_code' => (string)$this->documentCode,
            'document_label_fr' => (string)$this->documentLabelFr,
            'document_label_ar' => (string)$this->documentLabelAr,
            'is_mandatory' => (bool)$this->isMandatory,
            'coordinator_checked' => (bool)$this->coordinatorChecked,
            'coordinator_notes' => $this->coordinatorNotes,
            'coordinator_checked_at' => $this->coordinatorCheckedAt ? (int)$this->coordinatorCheckedAt : null,
            'backoffice_checked' => (bool)$this->backofficeChecked,
            'backoffice_notes' => $this->backofficeNotes,
            'backoffice_checked_at' => $this->backofficeCheckedAt ? (int)$this->backofficeCheckedAt : null,
        ];
    }
}
