<?php

namespace OCA\Coordination\Controller;

use OCA\Coordination\Db\Dossier;
use OCA\Coordination\Db\DossierMapper;
use OCA\Coordination\Db\DossierChecklistMapper;
use OCA\Coordination\Service\Access;
use OCA\Coordination\Service\ConflictException;
use OCA\Coordination\Service\DossierLock;
use OCA\Coordination\Service\DossierService;
use OCA\Coordination\Service\DuplicateException;
use OCA\Coordination\Service\ScanService;
use OCA\Coordination\Service\ValidationException;
use OCA\Coordination\Service\Workflow;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class DossierApiController extends Controller {
    use ApiResponses;

    public function __construct(
        string $appName,
        IRequest $request,
        private DossierMapper $dossierMapper,
        private DossierChecklistMapper $checklistMapper,
        private DossierService $dossierService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IDBConnection $db,
        private Access $access,
        private DossierLock $lock,
        private ScanService $scans,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    private function transaction(callable $work): void {
        $this->db->beginTransaction();
        try { $work(); $this->db->commit(); }
        catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /**
     * Écriture verrouillée : la révision est incrémentée (statut/révision attendus
     * vérifiés) puis l'entité et l'historique sont écrits dans la même transaction.
     */
    private function persist(Dossier $dossier, string $action, string $detail, ?array $statuses = null, ?int $revision = null): void {
        $this->transaction(function () use ($dossier,$action,$detail,$statuses,$revision) {
            $this->lock->claim((int)$dossier->getId(), $statuses, $revision);
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

    /** Charge un dossier accessible, ou renvoie la réponse d'erreur à retourner. */
    private function loadAccessible(int $id): Dossier|JSONResponse {
        if ($id <= 0) return $this->error('Identifiant de dossier invalide', 400);
        $dossier = $this->dossierMapper->findById($id);
        if ($dossier === null || !$this->canAccessDossier($dossier)) return $this->error('Dossier introuvable ou non autorisé', 404);
        return $dossier;
    }

    /** Pièces enrichies du nombre de scans, pour les règles de transition. */
    private function piecesWithScans(int $id): array {
        return $this->scans->attach($id, $this->dossierService->getDossierDetails($id)['checklist']);
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
        if (!$this->access->canView()) return $this->error('Accès refusé',403);
        if ($this->request->getParam('delete_id') !== null) return $this->error('La suppression exige une requête POST ou DELETE.',405);
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
            return $this->failure($e, 'liste des dossiers');
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
        if (!$this->access->canView()) return $this->error('Accès refusé',403);
        $id = (int)$id;
        if ($id <= 0) $id = (int)$this->request->getParam('id', 0);
        if ($id <= 0) $id = (int)$this->request->getParam('detail_id', 0);

        try {
            $dossier = $this->loadAccessible($id);
            if ($dossier instanceof JSONResponse) return $dossier;
            $data = $this->dossierService->getDossierDetails($id);
            if (!$this->seesAllDossiers() && trim((string)($data['dossier']['assigned_coordinator'] ?? '')) !== $this->getCurrentUserId()) {
                return $this->error('Ce dossier ne vous est pas attribué',404);
            }
            $roles = $this->userRoles();
            $data['can_view_scans'] = Workflow::canViewScans($roles,$this->getCurrentUserId(),$dossier->getAssignedCoordinator());
            if ($data['can_view_scans']) $data['checklist'] = $this->scans->attach($id, $data['checklist']);
            return new JSONResponse(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return $this->failure($e, 'lecture du dossier');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(): JSONResponse {
        if (!$this->canManageDossiers()) {
            return $this->error('Création réservée aux responsables et administrateurs',403);
        }

        $body = $this->request->getParams();
        try {
            if (empty($body['client_name'])) {
                return $this->error('Le nom du client est obligatoire',400);
            }
            $dossier = $this->dossierService->createDossier($body, $this->getCurrentUserId());
            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return $this->failure($e, 'création du dossier');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update($id = 0): JSONResponse {
        if (!$this->canManageDossiers()) {
            return $this->error('Modification réservée aux responsables et administrateurs',403);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return $this->error("Dossier #$id introuvable",404);
            }

            if (isset($body['case_number'])) {
                $number = trim((string)$body['case_number']);
                if ($number === '' || mb_strlen($number) > 64) throw new ValidationException('Le numéro de dossier est obligatoire (64 caractères maximum).');
                if ($number !== (string)$dossier->getCaseNumber() && $this->dossierService->caseNumberExists($number, $id)) throw new DuplicateException('Ce numéro de dossier est déjà utilisé par un autre dossier.');
                $dossier->setCaseNumber($number);
            }
            if (isset($body['client_name'])) {
                if (trim((string)$body['client_name']) === '') throw new ValidationException('Le nom du client est obligatoire.');
                $dossier->setClientName(trim((string)$body['client_name']));
            }
            if (isset($body['phone'])) $dossier->setPhone($body['phone']);
            if (isset($body['address'])) $dossier->setAddress($body['address']);
            if (isset($body['wilaya'])) $dossier->setWilaya($body['wilaya']);
            if (isset($body['commune'])) $dossier->setCommune($body['commune']);
            if (isset($body['bank'])) $dossier->setBank($body['bank']);

            if (isset($body['custom_data']) && is_array($body['custom_data'])) {
                $dossier->setCustomData(json_encode($body['custom_data'], JSON_UNESCAPED_UNICODE));
            }

            $dossier->setUpdatedAt(time());
            // Révision fournie par la fiche ouverte : refuse d'écraser une modification plus récente.
            $revision = isset($body['revision']) && is_numeric($body['revision']) ? (int)$body['revision'] : null;
            $this->persist($dossier,'update','Mise à jour des informations du dossier',null,$revision);

            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return $this->failure($e, 'modification du dossier');
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
            return $this->error('Accès non autorisé',403);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        try {
            $dossier = $this->loadAccessible($id);
            if ($dossier instanceof JSONResponse) return $dossier;

            if (!Workflow::canCoordinate($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator())) return $this->error('Action réservée au coordinateur affecté',403);
            $custom = json_decode((string)$dossier->getCustomData(), true);
            if (!is_array($custom)) {
                $custom = [];
            }

            foreach (['coordinator_note' => 2000, 'phone_secondary' => 32] as $key => $max) {
                if (!isset($body[$key])) continue;
                $value = trim((string)$body[$key]);
                if (mb_strlen($value) > $max) throw new ValidationException("Champ trop long ($max caractères maximum).");
                if ($value === '') unset($custom[$key]); else $custom[$key] = $value;
            }

            $dossier->setCustomData(empty($custom) ? null : json_encode($custom, JSON_UNESCAPED_UNICODE));
            $dossier->setUpdatedAt(time());
            $this->persist($dossier,'update','Note et contact complémentaire du coordinateur');

            return new JSONResponse(['success' => true, 'custom_data' => $custom]);
        } catch (Throwable $e) {
            return $this->failure($e, 'note du coordinateur');
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
            return $this->error('Suppression réservée exclusivement aux administrateurs et responsables CSM',403);
        }

        try {
            $this->transaction(function () use ($id) {
                $this->scans->deleteRowsForDossier($id);
                foreach (['coord_checklists'=>'dossier_id','coord_histories'=>'dossier_id','coord_dossiers'=>'id'] as $table=>$column) {
                    $q = $this->db->getQueryBuilder();
                    $q->delete($table)->where($q->expr()->eq($column,$q->createNamedParameter($id)))->executeStatement();
                }
            });
            // Les fichiers sont effacés après le commit : un échec ici laisse au pire des PDF orphelins, jamais un dossier sans ses scans.
            try { $this->scans->removeFolder($id); }
            catch (Throwable $e) { $this->logger->warning('Coordination : scans du dossier supprimé non effacés', ['app'=>'coordination','exception'=>$e,'dossier'=>$id]); }

            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->failure($e, 'suppression du dossier');
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
     * Attribue un dossier encore modifiable. Retourne false si son statut ne le
     * permet plus (collecté ou validé). Un retrait d'attribution le remet en « nouveau ».
     */
    private function assignOne(int $id, ?string $coord, string $action, string $detail): bool {
        $q = $this->db->getQueryBuilder();
        $q->update('coord_dossiers')
          ->set('assigned_coordinator', $q->createNamedParameter($coord))
          ->set('updated_at', $q->createNamedParameter(time()))
          ->set('revision', $q->createFunction('revision + 1'))
          ->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
          ->andWhere($q->expr()->in('status', $q->createNamedParameter(Workflow::REASSIGNABLE, IQueryBuilder::PARAM_STR_ARRAY)));
        if ($coord === null) $q->set('status', $q->createNamedParameter(DossierService::STATUS_NEW));
        if ((int)$q->executeStatement() !== 1) return false;

        if ($coord !== null) {
            $s = $this->db->getQueryBuilder();
            $s->update('coord_dossiers')
              ->set('status', $s->createNamedParameter(DossierService::STATUS_ASSIGNED))
              ->where($s->expr()->eq('id', $s->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
              ->andWhere($s->expr()->eq('status', $s->createNamedParameter(DossierService::STATUS_NEW)));
            $s->executeStatement();
        }
        $this->dossierService->addHistory($id, $this->getCurrentUserId(), $action, $detail);
        return true;
    }

    /**
     * @NoAdminRequired
     */
    public function assign($id = 0): JSONResponse {
        if (!$this->canManageDossiers()) {
            return $this->error('Attribution réservée aux responsables et administrateurs',403);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $coord = $this->request->getParam('assigned_coordinator', null);
        if ($coord === '') $coord = null;

        if ($coord !== null && (!is_string($coord) || !$this->access->belongs($coord,'coordinator'))) return $this->error('Choisissez un coordinateur autorisé',400);
        if ($id <= 0) {
            return $this->error('Identifiant de dossier invalide',400);
        }

        try {
            $dossier = $this->dossierMapper->findById($id);
            if ($dossier === null) {
                return $this->error("Dossier #$id introuvable",404);
            }
            // Rien à faire : évite une ligne d'historique et un refus inutiles.
            if ((string)$dossier->getAssignedCoordinator() === (string)$coord) return new JSONResponse(['success'=>true,'updated_count'=>0]);

            $done = false;
            $this->transaction(function () use ($id,$coord,&$done) {
                $done = $this->assignOne($id, $coord, 'assign', $coord === null ? 'Attribution retirée : dossier remis à « Nouveau »' : 'Attribution au coordinateur: ' . $coord);
            });
            if (!$done) return $this->error('Dossier déjà transmis au Back-Office : son attribution est figée.',409);

            return new JSONResponse(['success' => true, 'updated_count' => 1]);
        } catch (Throwable $e) {
            return $this->failure($e, 'attribution');
        }
    }

    /**
     * @NoAdminRequired
     *
     * Attribution en lot : seuls les dossiers encore modifiables changent de coordinateur.
     */
    public function batchAssign(): JSONResponse {
        if (!$this->canManageDossiers()) {
            return $this->error('Attribution réservée aux responsables et administrateurs',403);
        }

        $body = $this->request->getParams();
        $ids = $body['ids'] ?? $this->request->getParam('ids', []);
        $wilaya = $body['wilaya'] ?? $this->request->getParam('wilaya', null);
        $coord = $body['assigned_coordinator'] ?? $this->request->getParam('assigned_coordinator', null);

        if (!is_string($coord) || $coord === '' || !$this->access->belongs($coord,'coordinator')) return $this->error('Choisissez un coordinateur autorisé',400);

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
        $cleanIds = array_values(array_unique($cleanIds));
        if (count($cleanIds) > 500) return $this->error('500 dossiers maximum par attribution groupée.',400);

        $wilayaFilter = (!empty($wilaya) && is_string($wilaya) && !in_array($wilaya, ['all', 'null'], true)) ? $wilaya : null;

        if (empty($cleanIds) && $wilayaFilter === null) {
            return $this->error('Aucun dossier sélectionné',400);
        }

        try {
            $targetIds = $this->findExistingIds($cleanIds, $wilayaFilter);

            if (empty($targetIds)) {
                return $this->error('Aucun dossier correspondant en base',404);
            }

            $updated = 0;
            $this->transaction(function () use ($targetIds,$coord,&$updated) {
                foreach ($targetIds as $dossierId) {
                    if ($this->assignOne($dossierId, $coord, 'batch_assign', "Attribution groupée au coordinateur: $coord")) $updated++;
                }
            });

            return new JSONResponse([
                'success' => true,
                'updated_count' => $updated,
                'skipped_count' => count($targetIds) - $updated,
            ]);
        } catch (Throwable $e) {
            return $this->failure($e, 'attribution groupée');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function setAppointment($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return $this->error('Action réservée aux coordinateurs, au back-office et aux responsables',403);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        try {
            $dossier = $this->loadAccessible($id);
            if ($dossier instanceof JSONResponse) return $dossier;

            if (!Workflow::canCoordinate($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator())) return $this->error('Action réservée au coordinateur affecté',403);
            if (!in_array($dossier->getStatus(),Workflow::COORDINATOR_STAGE,true)) return $this->error('Rendez-vous non modifiable à cette étape',409);
            $date = $body['appointment_date'] ?? '';
            $time = $body['appointment_time'] ?? '';
            $notes = $body['appointment_notes'] ?? '';

            if (!is_string($date) || !is_string($time) || !Workflow::validAppointment($date,$time)) return $this->error('Renseignez une date et une heure de rendez-vous valides.',400);
            if (!is_string($notes) || mb_strlen($notes) > 2000) return $this->error('Consignes trop longues (2 000 caractères maximum).',400);
            $dossier->setAppointmentDate($date);
            $dossier->setAppointmentTime($time);
            $dossier->setAppointmentNotes($notes);
            $dossier->setStatus(DossierService::STATUS_APPOINTMENT_SET);
            $dossier->setUpdatedAt(time());
            $this->persist($dossier,'appointment',"RDV fixé le $date à $time. Consignes: $notes",Workflow::COORDINATOR_STAGE);

            return new JSONResponse(['success' => true, 'data' => $dossier]);
        } catch (Throwable $e) {
            return $this->failure($e, 'enregistrement du rendez-vous');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function updateChecklist($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return $this->error('Action réservée aux coordinateurs, au back-office et aux responsables',403);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        $itemId = (int)($body['checklist_item_id'] ?? 0);
        $role = $body['role'] ?? 'coordinator';
        if (!isset($body['checked']) || !is_bool($body['checked']) || !is_string($role)) return $this->error('Contrôle invalide',400);
        $checked = $body['checked'] ? 1 : 0;
        $notes = $body['notes'] ?? null;
        if ($notes !== null && (!is_string($notes) || mb_strlen($notes) > 2000)) return $this->error('Remarque invalide (2 000 caractères maximum).',400);

        try {
            $dossier = $this->loadAccessible($id);
            if ($dossier instanceof JSONResponse) return $dossier;

            $status = (string)$dossier->getStatus();
            $error = Workflow::checklistError($this->userRoles(),$this->getCurrentUserId(),$dossier->getAssignedCoordinator(),$status,$role);
            if ($error) return $this->error($error,403);
            $pieces = $this->dossierService->getDossierDetails($id)['checklist'];
            if (!array_filter($pieces,fn($p) => (int)$p['id'] === $itemId)) return $this->error('Pièce introuvable dans ce dossier',404);
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->update('coord_checklists');

            if ($role === 'coordinator') {
                $qb->set('coordinator_checked', $qb->createNamedParameter($checked))
                   ->set('coordinator_checked_at', $qb->createNamedParameter($checked ? $now : null));
                if ($notes !== null) {
                    $qb->set('coordinator_notes', $qb->createNamedParameter($notes));
                }
            } else {
                $qb->set('backoffice_checked', $qb->createNamedParameter($checked))
                   ->set('backoffice_checked_at', $qb->createNamedParameter($checked ? $now : null));
                if ($notes !== null) {
                    $qb->set('backoffice_notes', $qb->createNamedParameter($notes));
                }
            }

            $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('dossier_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $this->transaction(function () use ($qb,$id,$itemId,$role,$checked,$status) {
                // Le statut lu plus haut doit être encore celui du dossier (ex. pas de case BO cochée après une validation concurrente).
                $this->lock->claim($id, [$status]);
                $qb->executeStatement();
                $this->dossierService->addHistory($id,$this->getCurrentUserId(),'checklist_update','Pièce '.$itemId.' · '.$role.' : '.($checked ? 'vérifiée' : 'à vérifier'));
            });

            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->failure($e, 'contrôle de pièce');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function changeStatus($id = 0): JSONResponse {
        if (!$this->canWrite()) {
            return $this->error('Action réservée aux coordinateurs, au back-office et aux responsables',403);
        }

        $id = (int)$id ?: (int)$this->request->getParam('id', 0);
        $body = $this->request->getParams();
        $newStatus = (string)($body['status'] ?? '');
        $note = (string)($body['note'] ?? '');
        if (mb_strlen($note) > 2000) return $this->error('Remarque trop longue (2 000 caractères maximum).',400);

        try {
            $dossier = $this->loadAccessible($id);
            if ($dossier instanceof JSONResponse) return $dossier;

            $oldStatus = (string)$dossier->getStatus();
            $roles = $this->userRoles(); $uid = $this->getCurrentUserId();
            $check = fn(array $pieces) => Workflow::transitionError($roles,$uid,$dossier->getAssignedCoordinator(),$oldStatus,$newStatus,$pieces,$dossier->getAppointmentDate(),$dossier->getAppointmentTime(),$note,$this->access->scanRequired());
            $error = $check($this->piecesWithScans($id));
            if ($error) return $this->error($error,409);
            if ($oldStatus === $newStatus) return new JSONResponse(['success'=>true,'data'=>$dossier]);
            $this->transaction(function () use ($dossier,$id,$oldStatus,$newStatus,$note,$check) {
                // Verrou pris, on revérifie les pièces : une case décochée entre-temps ne peut pas passer.
                $this->lock->claim($id, [$oldStatus]);
                if ($error = $check($this->piecesWithScans($id))) throw new ConflictException($error);
                $dossier->setStatus($newStatus);
                $dossier->setUpdatedAt(time());
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
            return $this->failure($e, 'changement de statut');
        }
    }
}
