<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class DossierHistoryMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'coord_histories', DossierHistory::class);
    }

    public function findByDossier(int $dossierId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_histories')
           ->where($qb->expr()->eq('dossier_id', $qb->createNamedParameter($dossierId)))
           ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }
}
