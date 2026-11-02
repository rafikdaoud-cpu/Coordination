<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class DossierChecklistMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'coord_checklists', DossierChecklist::class);
    }

    public function findByDossier(int $dossierId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_checklists')
           ->where($qb->expr()->eq('dossier_id', $qb->createNamedParameter($dossierId)))
           ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    public function deleteByDossier(int $dossierId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('coord_checklists')
           ->where($qb->expr()->eq('dossier_id', $qb->createNamedParameter($dossierId)));
        $qb->executeStatement();
    }
}
