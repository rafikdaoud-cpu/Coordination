<?php
namespace OCA\Coordination\Service;

use OCP\IDBConnection;

/**
 * Contrôle de concurrence optimiste. À appeler DANS la transaction d'écriture :
 * l'UPDATE conditionnel incrémente la révision et pose le verrou de ligne jusqu'au
 * commit. Si le statut ou la révision attendus ne correspondent plus, rien n'est
 * modifié et une ConflictException est levée.
 */
class DossierLock {
    public function __construct(private IDBConnection $db) {}

    /** @param string[]|null $statuses statuts acceptés, null = tout statut */
    public function claim(int $id, ?array $statuses = null, ?int $revision = null): void {
        $q = $this->db->getQueryBuilder();
        $q->update('coord_dossiers')
          ->set('revision', $q->createFunction('revision + 1'))
          ->where($q->expr()->eq('id', $q->createNamedParameter($id)));
        if ($statuses !== null) {
            $q->andWhere($q->expr()->in('status', $q->createNamedParameter(array_values($statuses), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
        }
        if ($revision !== null) {
            $q->andWhere($q->expr()->eq('revision', $q->createNamedParameter($revision)));
        }
        if ((int)$q->executeStatement() !== 1) {
            throw new ConflictException('Le dossier a été modifié entre-temps par un autre utilisateur. Rechargez la fiche puis recommencez.');
        }
    }
}
