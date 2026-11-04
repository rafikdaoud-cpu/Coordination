<?php

namespace OCA\Coordination\Controller;

use OCA\Coordination\Db\Dossier;
use OCA\Coordination\Db\DossierMapper;
use OCA\Coordination\Service\Access;
use OCA\Coordination\Service\ConflictException;
use OCA\Coordination\Service\DossierLock;
use OCA\Coordination\Service\DossierService;
use OCA\Coordination\Service\ScanService;
use OCA\Coordination\Service\Workflow;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scans PDF des pièces : le coordinateur affecté (ou le responsable) les dépose
 * tant que le dossier est à son étape ; Back-Office, responsable et coordinateur
 * affecté les consultent et téléchargent. Les commerciaux n'y ont pas accès.
 */
class ScanApiController extends Controller {
    use ApiResponses;

    public function __construct(
        string $appName,
        IRequest $request,
        private DossierMapper $dossierMapper,
        private DossierService $dossierService,
        private ScanService $scans,
        private DossierLock $lock,
        private Access $access,
        private IUserSession $userSession,
        private IDBConnection $db,
        private LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    private function uid(): string {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : '';
    }

    /** Dossier visible côté scans, sinon 404 (on ne révèle pas son existence). */
    private function dossier(int $id): Dossier|JSONResponse {
        if (!$this->access->canView()) return $this->error('Accès refusé', 403);
        $dossier = $id > 0 ? $this->dossierMapper->findById($id) : null;
        if ($dossier === null || !Workflow::canViewScans($this->access->roles(), $this->uid(), $dossier->getAssignedCoordinator())) {
            return $this->error('Dossier introuvable ou non autorisé', 404);
        }
        return $dossier;
    }

    private function transaction(callable $work): void {
        $this->db->beginTransaction();
        try { $work(); $this->db->commit(); }
        catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /**
     * @NoAdminRequired
     */
    public function upload($id = 0, $itemId = 0): JSONResponse {
        $id = (int)$id; $itemId = (int)$itemId;
        try {
            $dossier = $this->dossier($id);
            if ($dossier instanceof JSONResponse) return $dossier;
            $uid = $this->uid();
            if ($error = Workflow::scanUploadError($this->access->roles(), $uid, $dossier->getAssignedCoordinator(), (string)$dossier->getStatus())) return $this->error($error, 403);
            $piece = $this->scans->piece($id, $itemId);
            if (!$piece) return $this->error('Pièce introuvable dans ce dossier', 404);

            $file = $this->request->getUploadedFile('file');
            $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return $this->error('Scan refusé par le serveur : fichier trop volumineux.', 413);
            if ($code !== UPLOAD_ERR_OK || empty($file['tmp_name'])) return $this->error('Aucun scan reçu : vérifiez la connexion et recommencez.', 400);
            $size = (int)($file['size'] ?? 0);
            if ($error = ScanService::validatePdf($file['tmp_name'], $size)) return $this->error($error, $size > ScanService::MAX_BYTES ? 413 : 400);

            $content = (string)file_get_contents($file['tmp_name']);
            $pages = ScanService::countPages($content);
            $label = (string)$piece['document_label_fr'];
            $original = (string)($file['name'] ?? 'scan.pdf');
            $status = (string)$dossier->getStatus();

            // Fichier écrit d'abord ; en cas d'échec de la transaction, il est supprimé.
            $name = $this->scans->writeFile($id, $itemId, $content);
            $scan = null;
            try {
                $this->transaction(function () use ($id,$itemId,$name,$original,$size,$pages,$uid,$label,&$scan) {
                    $this->lock->claim($id, Workflow::COORDINATOR_STAGE);
                    if ($this->scans->countForPiece($itemId) >= ScanService::MAX_PER_PIECE) throw new ConflictException(ScanService::MAX_PER_PIECE . ' scans maximum par pièce : supprimez un ancien scan.');
                    $scan = $this->scans->insertRow($id, $itemId, $name, $original, $size, $pages, $uid);
                    $this->dossierService->addHistory($id, $uid, 'scan_upload', 'Scan PDF ajouté · ' . $label . ($pages ? " ($pages page(s))" : ''));
                });
            } catch (Throwable $e) {
                try { $this->scans->removeFile($id, $name); } catch (Throwable $ignored) { $this->logger->warning('Coordination : scan orphelin', ['app'=>'coordination','exception'=>$ignored]); }
                throw $e;
            }
            return new JSONResponse(['success' => true, 'scan' => $scan], 201);
        } catch (Throwable $e) {
            return $this->failure($e, 'enregistrement du scan');
        }
    }

    /**
     * Ouverture (inline) ou téléchargement (?download=1) du PDF.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function file($id = 0, $scanId = 0): Response {
        $id = (int)$id; $scanId = (int)$scanId;
        try {
            $dossier = $this->dossier($id);
            if ($dossier instanceof JSONResponse) return $dossier;
            $scan = $this->scans->find($id, $scanId);
            if (!$scan) return $this->error('Scan introuvable', 404);
            $content = $this->scans->content($id, (string)$scan['file_name']);
            $piece = $this->scans->piece($id, (int)$scan['checklist_id']);
            $base = trim((string)preg_replace(['/[^A-Za-z0-9.-]+/', '/_+/'], '_', $dossier->getCaseNumber() . '_' . ($piece['document_code'] ?? 'piece') . '_' . $scanId), '_.');
            $download = (string)$this->request->getParam('download', '') === '1';
            return new DataDisplayResponse($content, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $base . '.pdf"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (NotFoundException $e) {
            $this->logger->error('Coordination : fichier de scan manquant', ['app'=>'coordination','exception'=>$e,'dossier'=>$id,'scan'=>$scanId]);
            return $this->error('Fichier du scan introuvable sur le serveur. Prévenez l’administrateur.', 404);
        } catch (Throwable $e) {
            return $this->failure($e, 'lecture du scan');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy($id = 0, $scanId = 0): JSONResponse {
        $id = (int)$id; $scanId = (int)$scanId;
        try {
            $dossier = $this->dossier($id);
            if ($dossier instanceof JSONResponse) return $dossier;
            $scan = $this->scans->find($id, $scanId);
            if (!$scan) return $this->error('Scan introuvable', 404);
            $roles = $this->access->roles(); $uid = $this->uid();
            if ($error = Workflow::scanDeleteError($roles, $uid, $dossier->getAssignedCoordinator(), (string)$dossier->getStatus(), (string)$scan['uploaded_by'])) return $this->error($error, 403);
            $this->transaction(function () use ($id,$scanId,$uid,$roles) {
                $this->lock->claim($id, $roles['rcoord'] ? null : Workflow::COORDINATOR_STAGE);
                $this->scans->deleteRow($scanId);
                $this->dossierService->addHistory($id, $uid, 'scan_delete', 'Scan PDF supprimé (n° ' . $scanId . ')');
            });
            try { $this->scans->removeFile($id, (string)$scan['file_name']); }
            catch (Throwable $e) { $this->logger->warning('Coordination : fichier de scan non effacé', ['app'=>'coordination','exception'=>$e]); }
            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            return $this->failure($e, 'suppression du scan');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroyPost($id = 0, $scanId = 0): JSONResponse {
        return $this->destroy($id, $scanId);
    }
}
