<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class DossierConfigMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'coord_configs', DossierConfig::class);
    }

    public function getValue(string $key, ?string $default = null): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_configs')
           ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));

        try {
            $entity = $this->findEntity($qb);
            return $entity->getConfigValue() ?? $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    public function setValue(string $key, string $value): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_configs')
           ->where($qb->expr()->eq('config_key', $qb->createNamedParameter($key)));

        try {
            $entity = $this->findEntity($qb);
            $entity->setConfigValue($value);
            $this->update($entity);
        } catch (\Exception $e) {
            $entity = new DossierConfig();
            $entity->setConfigKey($key);
            $entity->setConfigValue($value);
            $this->insert($entity);
        }
    }
}
