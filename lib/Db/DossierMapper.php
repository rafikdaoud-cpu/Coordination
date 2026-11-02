<?php

namespace OCA\Coordination\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DossierMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'coord_dossiers', Dossier::class);
    }

    public function findById(int $id): ?Dossier {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_dossiers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $dossiers = $this->findEntities($qb);
        return $dossiers[0] ?? null;
    }

    public function findFiltered(
        ?string $status = null,
        ?string $wilaya = null,
        ?string $assignedUser = null,
        ?string $search = null,
        int $limit = 200,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_dossiers')
           ->orderBy('id', 'DESC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        if (!empty($status) && $status !== 'all') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        if (!empty($wilaya) && $wilaya !== 'all') {
            $qb->andWhere($qb->expr()->eq('wilaya', $qb->createNamedParameter($wilaya)));
        }

        if (!empty($assignedUser)) {
            $qb->andWhere($qb->expr()->eq('assigned_coordinator', $qb->createNamedParameter($assignedUser)));
        }

        if (!empty($search)) {
            $term = '%' . $search . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('case_number', $qb->createNamedParameter($term)),
                    $qb->expr()->like('client_name', $qb->createNamedParameter($term)),
                    $qb->expr()->like('phone', $qb->createNamedParameter($term)),
                    $qb->expr()->like('bank', $qb->createNamedParameter($term))
                )
            );
        }

        return $this->findEntities($qb);
    }
}
