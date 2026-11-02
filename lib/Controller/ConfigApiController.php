<?php

namespace OCA\Coordination\Controller;

use OCA\Coordination\Service\DossierService;
use OCA\Coordination\Db\DossierConfigMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IRequest;
use OCA\Coordination\Service\Access;
use Exception;

class ConfigApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private DossierService $dossierService,
        private DossierConfigMapper $configMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private Access $access
    ) {
        parent::__construct($appName, $request);
    }

    private function isManager(): bool { return $this->access->canManage(); }

    /**
     * @NoAdminRequired
     */
    public function getConfig(): JSONResponse {
        $currentUser = $this->userSession->getUser();
        $userGroups = [];
        $userId = '';
        $displayName = '';

        if ($currentUser) {
            $userId = $currentUser->getUID();
            $displayName = $currentUser->getDisplayName();
            $userGroups = $this->groupManager->getUserGroupIds($currentUser);
        }

        $roles = $this->access->roles();
        if (!$this->access->canView()) return new JSONResponse(['success'=>false,'error'=>'Aucun rôle Coordination ne vous est attribué.'],403);
        $isAdmin = $roles['admin']; $isRcoordinateur = $roles['rcoord']; $isCoordinateur = $roles['coord'];
        $isBackoffice = $roles['back']; $isCommercial = $roles['sales'];
        $isReadOnly = !$isRcoordinateur && !$isCoordinateur && !$isBackoffice;

        return new JSONResponse([
            'success' => true,
            'current_user' => [
                'uid' => $userId,
                'display_name' => $displayName,
                'groups' => $userGroups,
                'is_admin' => $isAdmin,
                'is_rcoordinateur' => $isRcoordinateur,
                'is_coordinateur' => $isCoordinateur,
                'is_backoffice' => $isBackoffice,
                'is_commercial' => $isCommercial,
                'is_readonly' => $isReadOnly,
            ],
            'wilayas' => $this->dossierService->getAlgerianWilayas(),
            'document_templates' => $this->dossierService->getDocumentTemplates(),
            'custom_fields' => $this->dossierService->getCustomFields(),
            'banks' => $this->dossierService->getBanks(),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function saveBanks(): JSONResponse {
        if (!$this->isManager()) {
            return new JSONResponse(['success' => false, 'error' => 'Paramétrage réservé aux responsables et administrateurs']);
        }

        $banks = $this->request->getParam('banks', []);
        $clean = [];
        if (is_array($banks)) {
            foreach ($banks as $bank) {
                $name = trim((string)$bank);
                if ($name !== '' && !in_array($name, $clean, true)) {
                    $clean[] = $name;
                }
            }
        }

        $this->configMapper->setValue('banks', json_encode($clean, JSON_UNESCAPED_UNICODE));

        return new JSONResponse([
            'success' => true,
            'banks' => !empty($clean) ? $clean : $this->dossierService->getDefaultBanks(),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function saveCustomFields(): JSONResponse {
        if (!$this->isManager()) {
            return new JSONResponse(['success' => false, 'error' => 'Paramétrage réservé aux responsables et administrateurs']);
        }

        $fields = $this->request->getParam('custom_fields', []);
        $this->configMapper->setValue('custom_fields', json_encode($fields, JSON_UNESCAPED_UNICODE));
        return new JSONResponse(['success' => true]);
    }

    /**
     * @NoAdminRequired
     */
    public function saveDocumentTemplates(): JSONResponse {
        if (!$this->isManager()) {
            return new JSONResponse(['success' => false, 'error' => 'Paramétrage réservé aux responsables et administrateurs']);
        }

        $templates = $this->request->getParam('document_templates', []);
        if (!is_array($templates) || count($templates)>100) return new JSONResponse(['success'=>false,'error'=>'Liste de pièces invalide (100 maximum)'],400);
        $codes=[];
        foreach ($templates as $t) {
            if (!is_array($t) || !is_string($t['code'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$t['code']) || isset($codes[$t['code']]) || !is_string($t['label_fr'] ?? null) || trim($t['label_fr']) === '' || mb_strlen($t['label_fr'])>255 || !is_string($t['label_ar'] ?? '') || mb_strlen($t['label_ar'] ?? '')>255) return new JSONResponse(['success'=>false,'error'=>'Chaque pièce doit avoir un code unique et un libellé français valide.'],400);
            $codes[$t['code']]=true;
        }

        $templates = array_map(fn($t) => ['code'=>$t['code'],'label_fr'=>trim($t['label_fr']),'label_ar'=>trim($t['label_ar'] ?? ''),'mandatory'=>(bool)($t['mandatory'] ?? true)],$templates);
        $this->configMapper->setValue('document_templates', json_encode($templates, JSON_UNESCAPED_UNICODE));
        return new JSONResponse(['success' => true]);
    }

    /**
     * @NoAdminRequired
     */
    public function getUsersAndGroups(): JSONResponse {
        if (!$this->access->canView()) return new JSONResponse(['success'=>false,'error'=>'Accès refusé'],403);
        $people = [];
        foreach ($this->access->groupIds('coordinator') as $gid) {
            $group = $this->groupManager->get($gid);
            foreach ($group ? $group->getUsers() : [] as $u) $people[$u->getUID()] = ['uid'=>$u->getUID(),'display_name'=>$u->getDisplayName()];
        }
        // Include only assignee labels needed by app roles, never the full Nextcloud directory.
        return new JSONResponse(['success'=>true,'coordinators'=>array_values($people),'all_users'=>array_values($people)]);
    }
}
