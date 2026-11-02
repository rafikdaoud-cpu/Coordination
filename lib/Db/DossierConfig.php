<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\Entity;

class DossierConfig extends Entity implements \JsonSerializable {
    protected $configKey = '';
    protected $configValue = null;

    public function jsonSerialize(): array {
        return [
            'id' => (int)$this->id,
            'key' => (string)$this->configKey,
            'value' => (string)$this->configValue,
        ];
    }
}
