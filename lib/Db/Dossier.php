<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\Entity;

class Dossier extends Entity implements \JsonSerializable {
    protected $caseNumber = null;
    protected $clientName = null;
    protected $phone = null;
    protected $address = null;
    protected $wilaya = null;
    protected $commune = null;
    protected $bank = null;
    protected $status = 'new';
    protected $assignedCoordinator = null;
    protected $appointmentDate = null;
    protected $appointmentTime = null;
    protected $appointmentNotes = null;
    protected $customData = null;
    protected $createdBy = null;
    protected $createdAt = 0;
    protected $updatedAt = 0;

    public function jsonSerialize(): array {
        $customDecoded = [];
        if (!empty($this->customData)) {
            $decoded = json_decode($this->customData, true);
            if (is_array($decoded)) {
                $customDecoded = $decoded;
            }
        }

        return [
            'id' => (int)$this->id,
            'case_number' => (string)$this->caseNumber,
            'client_name' => (string)$this->clientName,
            'phone' => (string)$this->phone,
            'address' => (string)$this->address,
            'wilaya' => (string)$this->wilaya,
            'commune' => (string)$this->commune,
            'bank' => (string)$this->bank,
            'status' => (string)$this->status,
            'assigned_coordinator' => (string)$this->assignedCoordinator,
            'appointment_date' => (string)$this->appointmentDate,
            'appointment_time' => (string)$this->appointmentTime,
            'appointment_notes' => (string)$this->appointmentNotes,
            'custom_data' => $customDecoded,
            'created_by' => (string)$this->createdBy,
            'created_at' => (int)$this->createdAt,
            'updated_at' => (int)$this->updatedAt,
        ];
    }
}
