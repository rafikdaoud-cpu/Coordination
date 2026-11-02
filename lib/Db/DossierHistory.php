<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\Entity;

class DossierHistory extends Entity implements \JsonSerializable {
    protected $dossierId = 0;
    protected $userId = '';
    protected $action = '';
    protected $details = null;
    protected $createdAt = 0;

    public function jsonSerialize(): array {
        return [
            'id' => (int)$this->id,
            'dossier_id' => (int)$this->dossierId,
            'user_id' => (string)$this->userId,
            'action' => (string)$this->action,
            'details' => $this->details,
            'created_at' => (int)$this->createdAt,
        ];
    }
}
