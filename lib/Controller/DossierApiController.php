<?php

namespace OCA\Coordination\Controller;

use OCA\Coordination\Db\Dossier;
use OCA\Coordination\Db\DossierMapper;
use OCA\Coordination\Db\DossierChecklistMapper;
use OCA\Coordination\Db\DossierChecklist;
use OCA\Coordination\Service\DossierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCA\Coordination\Service\Access;
use OCA\Coordination\Service\Workflow;
use Throwable;
use Exception;

class DossierApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private DossierMapper $dossierMapper,
        private DossierChecklistMapper $checklistMapper,
        private DossierService $dossierService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IDBConnection $db,
        private Access $access
    ) {
        parent::__construct($appName, $request);
    }

    private function transaction(callable $work): void {
        $this->db->beginTransaction();
        try { $work(); $this->db->commit(); }
        catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    private function persist(Dossier $dossier, string $action, string $detail): void {
        $this->transaction(function () use ($dossier,$action,$detail) {
            $this->dossierMapper->update($dossier);
            $this->dossierService->addHistory((int)$dossier->getId(),$this->getCurrentUserId(),$action,$detail);
        });
    }

    private function getCurrentUserId(): string {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : 'anonymous';
    }

    private function userRoles(): array { return $this->access->roles(); }

    private function canManageDossiers(): bool {
        $roles = $this->userRoles();
        return $roles['admin'] || $roles['rcoord'];
    }

    private function canWrite(): bool {
        $roles = $this->userRoles();
        return $roles['admin'] || $roles['rcoord'] || $roles['coord'] || $roles['back'];
    }

    /** Responsable, admin, back-office et commerciaux voient tous les dossiers. */
    private function seesAllDossiers(): bool {
        $roles = $this->userRoles();
        return $roles['admin'] || $roles['rcoord'] || $roles['back'] || $roles['sales'];
    }

    /** Un coordinateur n'accède qu'aux dossiers qui lui sont attribués. */
    private function canAccessDossier(Dossier $dossier): bool {
        if ($this->seesAllDossiers()) {
            return true;
        }
        return trim((string)$dossier->getAssignedCoordinator()) === $this->getCurrentUserId();
    }

    /**
     * @NoAdminRequired
     */
    public function index(
        ?string $status = null,
        ?string $wilaya = null,
        ?string $assignedUser = null,
        ?string $search = null,
        int $limit = 200,
        int $offset = 0
    ): JSONResponse {
        if (!$this->access->canView()) return new JSONResponse(['success'=>false,'error'=>'Accès refusé'],403);
        if ($this->request->getParam('delete_id') !== null) return new JSONResponse(['success'=>false,'error'=>'La suppression exige une requête POST ou DELETE.'],405);
        $limit = max(1,min(100,$limit)); $offset = max(0,$offset);
        $detailId = (int)$this->request->getParam('detail_id', 0);
        if ($detailId > 0) {
            return $this->show($detailId);
        }

        try {
            if (!$this->seesAllDossiers()) {
                $assignedUser = $this->getCurrentUserId();
            }
            $dossiers = $this->dossierMapper->findFiltered($status, $wilaya, $assignedUser, $search, $limit + 1, $offset);
            $hasMore = count($dossiers) > $limit;
            $dossiers = array_slice($dossiers,0,$limit);
            return new JSONResponse([
                'success' => true,
                'data' => $dossiers,
                'count' => count($dossiers),
                'has_more' => $hasMore, 'next_offset' => $offset + count($dossiers),
            ]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getDetail(): JSONResponse {
        $id = (int)$this->request->getParam('id', 0);
        return $this->show($id);
    }

    /**
     * @NoAdminRequired
     */
    public function show($id = 0): JSONResponse {
        if (!$this->access->canView()) return new JSONResponse(['success'=>false,'error'=>'Accès refusé'],403);
        $id = (int)$id;
        if ($id <= 0) {
            $id = (int)$this->request->getParam('id', 0);
        }
        if ($id <= 0) {
            $id = (int)$this->request->getParam('detail_id', 0);
        }

        try {
            if ($id <= 0) {
                return new JSONResponse(['success' => false, 'error' => 'Identifiant de dossier invalide']);
            }
            $dossier = $this->dossierMapper->findById($id);
            if (!$dossier || !$this->canAccessDossier($dossier)) return new JSONResponse(['success'=>false,'error'=>'Dossier introuvable ou non autorisé'],404);
            $data = $this->dossierService->getDossierDetails($id);
            if (!$this->seesAllDossiers() && trim((string)($data['dossier']['assigned_coordinator'] ?? '')) !== $this->getCurrentUserId()) {
                return new JSONResponse(['success' => false, 'error' => 'Ce dossier ne vous est pas attribué']);
            }
            return new JSONResponse(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(): JSONResponse {
        if (!$this->canManageDossiers()) {
            return new JSONResponse(['success' => false, 'error' => 'Création réservée aux responsables et administrateurs']);
        }

        $body = $this->request->getParams();
        try {
            if (empty($body['client_name'])) {
                return new JSONResponse(['success' => false, 'error' => 'Le nom du client est obligatoire']);
            }
            $dossier = $this->dossierService->createDossier($body, $this->getCurrentUserId());
            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update($id = 0): JSONResponse {
        if (!$this->canManageDossiers()) {
            return new JSONResponse(['success' => false, 'error' => 'Modification réservée aux responsables et administrateurs']);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return new JSONResponse(['success' => false, 'error' => "Dossier #$id introuvable"]);
            }

            if (isset($body['case_number'])) $dossier->setCaseNumber($body['case_number']);
            if (isset($body['client_name'])) $dossier->setClientName($body['client_name']);
            if (isset($body['phone'])) $dossier->setPhone($body['phone']);
            if (isset($body['address'])) $dossier->setAddress($body['address']);
            if (isset($body['wilaya'])) $dossier->setWilaya($body['wilaya']);
            if (isset($body['commune'])) $dossier->setCommune($body['commune']);
            if (isset($body['bank'])) $dossier->setBank($body['bank']);

            if (isset($body['custom_data']) && is_array($body['custom_data'])) {
                $dossier->setCustomData(json_encode($body['custom_data'], JSON_UNESCAPED_UNICODE));
            }

            $dossier->setUpdatedAt(time());
            $this->persist($dossier,'update','Mise à jour des informations du dossier');

            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function updatePost($id = 0): JSONResponse {
        return $this->update($id);
    }

    /**
     * @NoAdminRequired
     */
    public function saveCoordinatorFields($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return new JSONResponse(['success' => false, 'error' => 'Accès non autorisé']);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return new JSONResponse(['success' => false, 'error' => "Dossier #$id introuvable"]);
            }
            if (!$this->canAccessDossier($dossier)) {
                return new JSONResponse(['success' => false, 'error' => 'Ce dossier ne vous est pas attribué']);
            }

            if (!Workflow::canCoordinate($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator())) return new JSONResponse(['success'=>false,'error'=>'Action réservée au coordinateur affecté'],403);
            $custom = json_decode((string)$dossier->getCustomData(), true);
            if (!is_array($custom)) {
                $custom = [];
            }

            if (isset($body['coordinator_note'])) {
                $note = trim((string)$body['coordinator_note']);
                if ($note === '') {
                    unset($custom['coordinator_note']);
                } else {
                    $custom['coordinator_note'] = $note;
                }
            }
            if (isset($body['phone_secondary'])) {
                $phone2 = trim((string)$body['phone_secondary']);
                if ($phone2 === '') {
                    unset($custom['phone_secondary']);
                } else {
                    $custom['phone_secondary'] = $phone2;
                }
            }

            $dossier->setCustomData(empty($custom) ? null : json_encode($custom, JSON_UNESCAPED_UNICODE));
            $dossier->setUpdatedAt(time());
            $this->persist($dossier,'update','Note et contact complémentaire du coordinateur');

            return new JSONResponse(['success' => true, 'custom_data' => $custom]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy($id = 0): JSONResponse {
        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        if ($id <= 0) {
            $id = (int)$this->request->getParam('delete_id', 0);
        }

        if (!$this->canManageDossiers()) {
            return new JSONResponse(['success' => false, 'error' => 'Suppression réservée exclusivement aux administrateurs et responsables CSM']);
        }

        try {
            $this->db->beginTransaction();
            try {
                foreach (['coord_checklists'=>'dossier_id','coord_histories'=>'dossier_id','coord_dossiers'=>'id'] as $table=>$column) {
                    $q = $this->db->getQueryBuilder();
                    $q->delete($table)->where($q->expr()->eq($column,$q->createNamedParameter($id)))->executeStatement();
                }
                $this->db->commit();
            } catch (Throwable $e) { $this->db->rollBack(); throw $e; }

            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroyPost($id = 0): JSONResponse {
        return $this->destroy($id);
    }

    private function findExistingIds(array $ids, ?string $wilaya = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('coord_dossiers');

        if (!empty($ids)) {
            $qb->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        } elseif ($wilaya !== null) {
            $qb->where($qb->expr()->eq('wilaya', $qb->createNamedParameter($wilaya)));
        } else {
            return [];
        }

        $res = $qb->executeQuery();
        $rows = $res->fetchAll();
        $res->closeCursor();

        $found = [];
        foreach ($rows as $row) {
            $found[] = (int)$row['id'];
        }
        return $found;
    }

    /**
     * @NoAdminRequired
     */
    public function assign($id = 0): JSONResponse {
        if (!$this->canManageDossiers()) {
            return new JSONResponse(['success' => false, 'error' => 'Attribution réservée aux responsables et administrateurs']);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $coord = $this->request->getParam('assigned_coordinator', null);
        if ($coord === null) {
            $body = $this->request->getParams();
            $coord = $body['assigned_coordinator'] ?? null;
        }

        if ($coord !== null && (!is_string($coord) || !$this->access->belongs($coord,'coordinator'))) return new JSONResponse(['success'=>false,'error'=>'Choisissez un coordinateur autorisé'],400);
        if ($id <= 0) {
            return new JSONResponse(['success' => false, 'error' => 'Identifiant de dossier invalide']);
        }

        try {
            if (empty($this->findExistingIds([$id]))) {
                return new JSONResponse(['success' => false, 'error' => "Dossier #$id introuvable"]);
            }

            $this->transaction(function () use ($id,$coord) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('coord_dossiers')
               ->set('assigned_coordinator', $qb->createNamedParameter($coord))
               ->set('updated_at', $qb->createNamedParameter(time()))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
            $qb->executeStatement();

            if ($coord !== null) {
                $qb2 = $this->db->getQueryBuilder();
                $qb2->update('coord_dossiers')
                    ->set('status', $qb2->createNamedParameter(DossierService::STATUS_ASSIGNED))
                    ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($id)))
                    ->andWhere($qb2->expr()->eq('status', $qb2->createNamedParameter(DossierService::STATUS_NEW)));
                $qb2->executeStatement();
            }

            $this->dossierService->addHistory($id, $this->getCurrentUserId(), 'assign', 'Attribution au coordinateur: ' . ($coord ?: 'Non assigné'));

            });

            return new JSONResponse(['success' => true, 'updated_count' => 1]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => 'Attribution impossible: ' . $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     *
     * Attribution en lot : UPDATE SQL direct, périmètre vérifié, aucune erreur masquée
     */
    public function batchAssign(): JSONResponse {
        if (!$this->canManageDossiers()) {
            return new JSONResponse(['success' => false, 'error' => 'Attribution réservée aux responsables et administrateurs']);
        }

        $body = $this->request->getParams();
        $ids = $body['ids'] ?? $this->request->getParam('ids', []);
        $wilaya = $body['wilaya'] ?? $this->request->getParam('wilaya', null);
        $coord = $body['assigned_coordinator'] ?? $this->request->getParam('assigned_coordinator', null);

        if (!is_string($coord) || !$this->access->belongs($coord,'coordinator')) return new JSONResponse(['success'=>false,'error'=>'Choisissez un coordinateur autorisé'],400);
        if (empty($coord)) {
            return new JSONResponse(['success' => false, 'error' => 'Veuillez sélectionner un coordinateur']);
        }

        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [];
        }

        $cleanIds = [];
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $value = (int)$id;
                if ($value > 0) {
                    $cleanIds[] = $value;
                }
            }
        }

        $wilayaFilter = (!empty($wilaya) && !in_array($wilaya, ['all', 'null'], true)) ? (string)$wilaya : null;

        if (empty($cleanIds) && $wilayaFilter === null) {
            return new JSONResponse(['success' => false, 'error' => 'Aucun dossier sélectionné']);
        }

        try {
            $targetIds = $this->findExistingIds($cleanIds, $wilayaFilter);

            if (empty($targetIds)) {
                $scope = !empty($cleanIds) ? 'ids: ' . implode(', ', $cleanIds) : 'wilaya: ' . $wilayaFilter;
                return new JSONResponse(['success' => false, 'error' => "Aucun dossier correspondant en base ($scope)"]);
            }

            $this->transaction(function () use ($targetIds,$coord) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('coord_dossiers')
               ->set('assigned_coordinator', $qb->createNamedParameter($coord))
               ->set('updated_at', $qb->createNamedParameter(time()))
               ->where($qb->expr()->in('id', $qb->createNamedParameter($targetIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();

            $qbStatus = $this->db->getQueryBuilder();
            $qbStatus->update('coord_dossiers')
                     ->set('status', $qbStatus->createNamedParameter(DossierService::STATUS_ASSIGNED))
                     ->where($qbStatus->expr()->eq('status', $qbStatus->createNamedParameter(DossierService::STATUS_NEW)))
                     ->andWhere($qbStatus->expr()->in('id', $qbStatus->createNamedParameter($targetIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $qbStatus->executeStatement();

            $userId = $this->getCurrentUserId();
            foreach ($targetIds as $dossierId) {
                $this->dossierService->addHistory($dossierId, $userId, 'batch_assign', "Attribution groupée au coordinateur: $coord");
            }

            });

            return new JSONResponse([
                'success' => true,
                'updated_count' => count($targetIds),
            ]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => 'Attribution impossible: ' . $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function setAppointment($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return new JSONResponse(['success' => false, 'error' => 'Action réservée aux coordinateurs, au back-office et aux responsables']);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return new JSONResponse(['success' => false, 'error' => "Dossier #$id introuvable"]);
            }
            if (!$this->canAccessDossier($dossier)) {
                return new JSONResponse(['success' => false, 'error' => 'Ce dossier ne vous est pas attribué']);
            }

            if (!Workflow::canCoordinate($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator())) return new JSONResponse(['success'=>false,'error'=>'Action réservée au coordinateur affecté'],403);
            if (!in_array($dossier->getStatus(),['assigned','appointment_set','rejected'],true)) return new JSONResponse(['success'=>false,'error'=>'Rendez-vous non modifiable à cette étape'],409);
            $date = $body['appointment_date'] ?? '';
            $time = $body['appointment_time'] ?? '';
            $notes = $body['appointment_notes'] ?? '';

            if (!is_string($date) || !is_string($time) || !Workflow::validAppointment($date,$time)) return new JSONResponse(['success'=>false,'error'=>'Renseignez une date et une heure de rendez-vous valides.'],400);
            $dossier->setAppointmentDate($date);
            $dossier->setAppointmentTime($time);
            $dossier->setAppointmentNotes($notes);
            $dossier->setStatus(DossierService::STATUS_APPOINTMENT_SET);
            $dossier->setUpdatedAt(time());
            $this->persist($dossier,'appointment',"RDV fixé le $date à $time. Consignes: $notes");

            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function updateChecklist($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return new JSONResponse(['success' => false, 'error' => 'Action réservée aux coordinateurs, au back-office et aux responsables']);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        $itemId = (int)($body['checklist_item_id'] ?? 0);
        $role = $body['role'] ?? 'coordinator';
        if (!isset($body['checked']) || !is_bool($body['checked']) || !is_string($role)) return new JSONResponse(['success'=>false,'error'=>'Contrôle invalide'],400);
        $checked = $body['checked'] ? 1 : 0;
        $notes = $body['notes'] ?? null;

        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return new JSONResponse(['success' => false, 'error' => "Dossier #$id introuvable"]);
            }
            if (!$this->canAccessDossier($dossier)) {
                return new JSONResponse(['success' => false, 'error' => 'Ce dossier ne vous est pas attribué']);
            }

            $error = Workflow::checklistError($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator(),$dossier->getStatus(),(string)$role);
            if ($error) return new JSONResponse(['success'=>false,'error'=>$error],403);
            $pieces = $this->dossierService->getDossierDetails($id)['checklist'];
            if (!array_filter($pieces,fn($p) => (int)$p['id'] === $itemId)) return new JSONResponse(['success'=>false,'error'=>'Pièce introuvable dans ce dossier'],404);
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->update('coord_checklists');

            if ($role === 'coordinator') {
                $qb->set('coordinator_checked', $qb->createNamedParameter($checked))
                   ->set('coordinator_checked_at', $qb->createNamedParameter($checked ? $now : null));
                if ($notes !== null) {
                    $qb->set('coordinator_notes', $qb->createNamedParameter($notes));
                }
            } elseif ($role === 'backoffice') {
                $qb->set('backoffice_checked', $qb->createNamedParameter($checked))
                   ->set('backoffice_checked_at', $qb->createNamedParameter($checked ? $now : null));
                if ($notes !== null) {
                    $qb->set('backoffice_notes', $qb->createNamedParameter($notes));
                }
            }

            $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('dossier_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $this->transaction(function () use ($qb,$id,$itemId,$role,$checked) {
                $qb->executeStatement();
                $this->dossierService->addHistory($id,$this->getCurrentUserId(),'checklist_update','Pièce '.$itemId.' · '.$role.' : '.($checked ? 'vérifiée' : 'à vérifier'));
            });

            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function changeStatus($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return new JSONResponse(['success' => false, 'error' => 'Action réservée aux coordinateurs, au back-office et aux responsables']);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        $newStatus = $body['status'] ?? '';
        $note = $body['note'] ?? '';

        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return new JSONResponse(['success' => false, 'error' => "Dossier #$id introuvable"]);
            }
            if (!$this->canAccessDossier($dossier)) {
                return new JSONResponse(['success' => false, 'error' => 'Ce dossier ne vous est pas attribué']);
            }

            $oldStatus = $dossier->getStatus();
            $pieces = $this->dossierService->getDossierDetails($id)['checklist'];
            $error = Workflow::transitionError($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator(),$oldStatus,(string)$newStatus,$pieces,$dossier->getAppointmentDate(),$dossier->getAppointmentTime(),(string)$note);
            if ($error) return new JSONResponse(['success'=>false,'error'=>$error],409);
            if ($oldStatus === $newStatus) return new JSONResponse(['success'=>true,'data'=>$dossier]);
            $dossier->setStatus($newStatus);
            $dossier->setUpdatedAt(time());
            $this->transaction(function () use ($dossier,$id,$oldStatus,$newStatus,$note) {
                if ($newStatus === DossierService::STATUS_COLLECTED) {
                    // A resubmission after rejection must receive a fresh conformity check.
                    $q = $this->db->getQueryBuilder();
                    $q->update('coord_checklists')
                        ->set('backoffice_checked',$q->createNamedParameter(0))
                        ->set('backoffice_checked_at',$q->createNamedParameter(null))
                        ->where($q->expr()->eq('dossier_id',$q->createNamedParameter($id)));
                    $q->executeStatement();
                }
                $this->dossierMapper->update($dossier);
                $this->dossierService->addHistory($id,$this->getCurrentUserId(),'status_change',"Statut: [$oldStatus] -> [$newStatus]. " . ($note ? "Remarque: $note" : ""));
            });

            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return new JSONResponse(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
