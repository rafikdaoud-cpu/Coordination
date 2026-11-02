<?php

namespace OCA\Coordination\Controller;

use OCA\Coordination\Vendor\SimpleXLSX;
use OCA\Coordination\Service\DossierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Coordination\Service\Access;
use Exception;

class ImportApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private DossierService $dossierService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private Access $access
    ) {
        parent::__construct($appName, $request);
    }

    private function getCurrentUserId(): string {
        $user = $this->userSession->getUser();
        return $user ? $user->getUID() : 'system';
    }

    private function canManageDossiers(): bool { return $this->access->canManage(); }

    /**
     * @NoAdminRequired
     */
    public function uploadXlsx(): JSONResponse {
        if (!$this->canManageDossiers()) {
            return new JSONResponse(['success' => false, 'error' => 'Import réservé aux responsables et administrateurs'], 403);
        }

        $uploadedFile = $this->request->getUploadedFile('file');
        if (!$uploadedFile || empty($uploadedFile['tmp_name'])) {
            return new JSONResponse(['success' => false, 'error' => 'Aucun fichier transmis'], 400);
        }

        if (($uploadedFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int)($uploadedFile['size'] ?? 0) > 10*1024*1024) return new JSONResponse(['success'=>false,'error'=>'Fichier refusé : import limité à 10 Mo.'],400);
        $tmpPath = $uploadedFile['tmp_name'];
        $xlsx = SimpleXLSX::parse($tmpPath);
        if (!$xlsx) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Impossible de lire le fichier Excel (.xlsx). Assurez-vous qu\'il est au format XLSX valide.'
            ], 400);
        }

        $rows = $xlsx->rows();
        if (empty($rows) || count($rows) < 2) {
            return new JSONResponse(['success' => false, 'error' => 'Le fichier Excel est vide ou ne contient aucune ligne de données.'], 400);
        }

        $headers = array_map(function($h) {
            return strtr(mb_strtolower(trim((string)$h)), ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
        }, $rows[0]);

        $colMap = [
            'case_number' => -1,
            'client_name' => -1,
            'phone' => -1,
            'address' => -1,
            'wilaya' => -1,
            'commune' => -1,
            'bank' => -1,
        ];

        foreach ($headers as $idx => $header) {
            if (preg_match('/(tel|phone|mobile|contact)/i',$header) && $colMap['phone'] === -1) $colMap['phone']=$idx;
            elseif (preg_match('/(nom|client|prenom|customer|name)/i',$header) && $colMap['client_name'] === -1) $colMap['client_name']=$idx;
            elseif (preg_match('/(adresse|address|rue|localite)/i',$header) && $colMap['address'] === -1) $colMap['address']=$idx;
            elseif (preg_match('/(wilaya|province|region)/i',$header) && $colMap['wilaya'] === -1) $colMap['wilaya']=$idx;
            elseif (preg_match('/(commune|ville|city|daira)/i',$header) && $colMap['commune'] === -1) $colMap['commune']=$idx;
            elseif (preg_match('/(banque|bank|bancaire|etablissement)/i',$header) && $colMap['bank'] === -1) $colMap['bank']=$idx;
            elseif (preg_match('/(dossier|case_number|^numero$|^num$|^code$|^id$)/i',$header) && $colMap['case_number'] === -1) $colMap['case_number']=$idx;
        }

        if ($colMap['client_name'] < 0) return new JSONResponse(['success'=>false,'error'=>'Colonne Nom du client manquante : corrigez les en-têtes avant l’import.'],400);
        if (count($rows) > 2001) return new JSONResponse(['success'=>false,'error'=>'Import limité à 2 000 lignes par fichier.'],400);
        $userId = $this->getCurrentUserId();
        $importedCount = 0;
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            if (empty(array_filter($row))) {
                continue;
            }

            $clientName = ($colMap['client_name'] !== -1 && isset($row[$colMap['client_name']])) ? trim($row[$colMap['client_name']]) : '';
            if (empty($clientName)) {
                $errors[] = "Ligne " . ($i + 1) . " ignorée (Nom du client vide)";
                continue;
            }

            $caseNumber = ($colMap['case_number'] !== -1 && isset($row[$colMap['case_number']])) ? trim($row[$colMap['case_number']]) : '';
            if (empty($caseNumber)) {
                $caseNumber = 'DOS-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            }

            $phone = ($colMap['phone'] !== -1 && isset($row[$colMap['phone']])) ? trim($row[$colMap['phone']]) : '';
            $address = ($colMap['address'] !== -1 && isset($row[$colMap['address']])) ? trim($row[$colMap['address']]) : '';
            $wilaya = ($colMap['wilaya'] !== -1 && isset($row[$colMap['wilaya']])) ? trim($row[$colMap['wilaya']]) : '';
            $commune = ($colMap['commune'] !== -1 && isset($row[$colMap['commune']])) ? trim($row[$colMap['commune']]) : '';
            $bank = ($colMap['bank'] !== -1 && isset($row[$colMap['bank']])) ? trim($row[$colMap['bank']]) : '';

            $customData = [];
            foreach ($headers as $colIdx => $colHeader) {
                if (!in_array($colIdx, $colMap) && !empty($colHeader) && isset($row[$colIdx]) && $row[$colIdx] !== '') {
                    $customData[$colHeader] = trim($row[$colIdx]);
                }
            }

            try {
                $this->dossierService->createDossier([
                    'case_number' => $caseNumber,
                    'client_name' => $clientName,
                    'phone' => $phone,
                    'address' => $address,
                    'wilaya' => $wilaya,
                    'commune' => $commune,
                    'bank' => $bank,
                    'custom_data' => $customData,
                ], $userId);

                $importedCount++;
            } catch (Exception $e) {
                $errors[] = "Ligne " . ($i + 1) . ": " . $e->getMessage();
            }
        }

        return new JSONResponse([
            'success' => true,
            'imported_count' => $importedCount,
            'errors' => $errors,
        ]);
    }
}
