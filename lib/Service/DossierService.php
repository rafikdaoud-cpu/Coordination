<?php

namespace OCA\Coordination\Service;

use OCA\Coordination\Db\Dossier;
use OCA\Coordination\Db\DossierMapper;
use OCA\Coordination\Db\DossierChecklist;
use OCA\Coordination\Db\DossierChecklistMapper;
use OCA\Coordination\Db\DossierHistory;
use OCA\Coordination\Db\DossierHistoryMapper;
use OCA\Coordination\Db\DossierConfigMapper;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IDBConnection;
use Exception;

class DossierService {
    public const STATUS_NEW = 'new';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_APPOINTMENT_SET = 'appointment_set';
    public const STATUS_COLLECTED = 'collected';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private DossierMapper $dossierMapper,
        private DossierChecklistMapper $checklistMapper,
        private DossierHistoryMapper $historyMapper,
        private DossierConfigMapper $configMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private IDBConnection $db
    ) {
    }

    public function getAlgerianWilayas(): array {
        return [
            ['code' => '01', 'name' => '01 - Adrar', 'name_ar' => 'أدرار', 'full' => '01 - Adrar (أدرار)'],
            ['code' => '02', 'name' => '02 - Chlef', 'name_ar' => 'الشلف', 'full' => '02 - Chlef (الشلف)'],
            ['code' => '03', 'name' => '03 - Laghouat', 'name_ar' => 'الأغواط', 'full' => '03 - Laghouat (الأغواط)'],
            ['code' => '04', 'name' => '04 - Oum El Bouaghi', 'name_ar' => 'أم البواقي', 'full' => '04 - Oum El Bouaghi (أم البواقي)'],
            ['code' => '05', 'name' => '05 - Batna', 'name_ar' => 'باتنة', 'full' => '05 - Batna (باتنة)'],
            ['code' => '06', 'name' => '06 - Béjaïa', 'name_ar' => 'بجاية', 'full' => '06 - Béjaïa (بجاية)'],
            ['code' => '07', 'name' => '07 - Biskra', 'name_ar' => 'بسكرة', 'full' => '07 - Biskra (بسكرة)'],
            ['code' => '08', 'name' => '08 - Béchar', 'name_ar' => 'بشار', 'full' => '08 - Béchar (بشار)'],
            ['code' => '09', 'name' => '09 - Blida', 'name_ar' => 'البليدة', 'full' => '09 - Blida (البليدة)'],
            ['code' => '10', 'name' => '10 - Bouira', 'name_ar' => 'البويرة', 'full' => '10 - Bouira (البويرة)'],
            ['code' => '11', 'name' => '11 - Tamanrasset', 'name_ar' => 'تمنراست', 'full' => '11 - Tamanrasset (تمنراست)'],
            ['code' => '12', 'name' => '12 - Tébessa', 'name_ar' => 'تبسة', 'full' => '12 - Tébessa (تبسة)'],
            ['code' => '13', 'name' => '13 - Tlemcen', 'name_ar' => 'تلمسان', 'full' => '13 - Tlemcen (تلمسان)'],
            ['code' => '14', 'name' => '14 - Tiaret', 'name_ar' => 'تيارت', 'full' => '14 - Tiaret (تيارت)'],
            ['code' => '15', 'name' => '15 - Tizi Ouzou', 'name_ar' => 'تيزي وزو', 'full' => '15 - Tizi Ouzou (تيزي وزو)'],
            ['code' => '16', 'name' => '16 - Alger', 'name_ar' => 'الجزائر', 'full' => '16 - Alger (الجزائر)'],
            ['code' => '17', 'name' => '17 - Djelfa', 'name_ar' => 'الجلفة', 'full' => '17 - Djelfa (الجلفة)'],
            ['code' => '18', 'name' => '18 - Jijel', 'name_ar' => 'جيجل', 'full' => '18 - Jijel (جيجل)'],
            ['code' => '19', 'name' => '19 - Sétif', 'name_ar' => 'سطيف', 'full' => '19 - Sétif (سطيف)'],
            ['code' => '20', 'name' => '20 - Saïda', 'name_ar' => 'سعيدة', 'full' => '20 - Saïda (سعيدة)'],
            ['code' => '21', 'name' => '21 - Skikda', 'name_ar' => 'سكيكدة', 'full' => '21 - Skikda (سكيكدة)'],
            ['code' => '22', 'name' => '22 - Sidi Bel Abbès', 'name_ar' => 'سيدي بلعباس', 'full' => '22 - Sidi Bel Abbès (سيدي بلعباس)'],
            ['code' => '23', 'name' => '23 - Annaba', 'name_ar' => 'عنابة', 'full' => '23 - Annaba (عنابة)'],
            ['code' => '24', 'name' => '24 - Guelma', 'name_ar' => 'قالمة', 'full' => '24 - Guelma (قالمة)'],
            ['code' => '25', 'name' => '25 - Constantine', 'name_ar' => 'قسنطينة', 'full' => '25 - Constantine (قسنطينة)'],
            ['code' => '26', 'name' => '26 - Médéa', 'name_ar' => 'المدية', 'full' => '26 - Médéa (المدية)'],
            ['code' => '27', 'name' => '27 - Mostaganem', 'name_ar' => 'مستغانم', 'full' => '27 - Mostaganem (مستغانم)'],
            ['code' => '28', 'name' => '28 - M\'Sila', 'name_ar' => 'المسيلة', 'full' => '28 - M\'Sila (المسيلة)'],
            ['code' => '29', 'name' => '29 - Mascara', 'name_ar' => 'معسكر', 'full' => '29 - Mascara (معسكر)'],
            ['code' => '30', 'name' => '30 - Ouargla', 'name_ar' => 'ورقلة', 'full' => '30 - Ouargla (ورقلة)'],
            ['code' => '31', 'name' => '31 - Oran', 'name_ar' => 'وهران', 'full' => '31 - Oran (وهران)'],
            ['code' => '32', 'name' => '32 - El Bayadh', 'name_ar' => 'البيض', 'full' => '32 - El Bayadh (البيض)'],
            ['code' => '33', 'name' => '33 - Illizi', 'name_ar' => 'إيليزي', 'full' => '33 - Illizi (إيليزي)'],
            ['code' => '34', 'name' => '34 - Bordj Bou Arreridj', 'name_ar' => 'برج بوعريريج', 'full' => '34 - Bordj Bou Arreridj (برج بوعريريج)'],
            ['code' => '35', 'name' => '35 - Boumerdès', 'name_ar' => 'بومرداس', 'full' => '35 - Boumerdès (بومرداس)'],
            ['code' => '36', 'name' => '36 - El Tarf', 'name_ar' => 'الطارف', 'full' => '36 - El Tarf (الطارف)'],
            ['code' => '37', 'name' => '37 - Tindouf', 'name_ar' => 'تندوف', 'full' => '37 - Tindouf (تندوف)'],
            ['code' => '38', 'name' => '38 - Tissemsilt', 'name_ar' => 'تيسمسيلت', 'full' => '38 - Tissemsilt (تيسمسيلت)'],
            ['code' => '39', 'name' => '39 - El Oued', 'name_ar' => 'الوادي', 'full' => '39 - El Oued (الوادي)'],
            ['code' => '40', 'name' => '40 - Khenchela', 'name_ar' => 'خنشلة', 'full' => '40 - Khenchela (خنشلة)'],
            ['code' => '41', 'name' => '41 - Souk Ahras', 'name_ar' => 'سوق أهراس', 'full' => '41 - Souk Ahras (سوق أهراس)'],
            ['code' => '42', 'name' => '42 - Tipaza', 'name_ar' => 'تيبازة', 'full' => '42 - Tipaza (تيبازة)'],
            ['code' => '43', 'name' => '43 - Mila', 'name_ar' => 'ميلة', 'full' => '43 - Mila (ميلة)'],
            ['code' => '44', 'name' => '44 - Aïn Defla', 'name_ar' => 'عين الدفلى', 'full' => '44 - Aïn Defla (عين الدفلى)'],
            ['code' => '45', 'name' => '45 - Naâma', 'name_ar' => 'النعامة', 'full' => '45 - Naâma (النعامة)'],
            ['code' => '46', 'name' => '46 - Aïn Témouchent', 'name_ar' => 'عين تموشنت', 'full' => '46 - Aïn Témouchent (عين تموشنت)'],
            ['code' => '47', 'name' => '47 - Ghardaïa', 'name_ar' => 'غرداية', 'full' => '47 - Ghardaïa (غرداية)'],
            ['code' => '48', 'name' => '48 - Relizane', 'name_ar' => 'غليزان', 'full' => '48 - Relizane (غليزان)'],
            ['code' => '49', 'name' => '49 - El M\'Ghair', 'name_ar' => 'المغير', 'full' => '49 - El M\'Ghair (المغير)'],
            ['code' => '50', 'name' => '50 - El Meniaa', 'name_ar' => 'المنيعة', 'full' => '50 - El Meniaa (المنيعة)'],
            ['code' => '51', 'name' => '51 - Ouled Djellal', 'name_ar' => 'أولاد جلال', 'full' => '51 - Ouled Djellal (أولاد جلال)'],
            ['code' => '52', 'name' => '52 - Bordj Baji Mokhtar', 'name_ar' => 'برج باجي مختار', 'full' => '52 - Bordj Baji Mokhtar (برج باجي مختار)'],
            ['code' => '53', 'name' => '53 - Béni Abbès', 'name_ar' => 'بني عباس', 'full' => '53 - Béni Abbès (بني عباس)'],
            ['code' => '54', 'name' => '54 - Timimoun', 'name_ar' => 'تيميمون', 'full' => '54 - Timimoun (تيميمون)'],
            ['code' => '55', 'name' => '55 - Touggourt', 'name_ar' => 'تقرت', 'full' => '55 - Touggourt (تقرت)'],
            ['code' => '56', 'name' => '56 - Djanet', 'name_ar' => 'جانت', 'full' => '56 - Djanet (جانت)'],
            ['code' => '57', 'name' => '57 - In Salah', 'name_ar' => 'عين صالح', 'full' => '57 - In Salah (عين صالح)'],
            ['code' => '58', 'name' => '58 - In Guezzam', 'name_ar' => 'عين قزام', 'full' => '58 - In Guezzam (عين قزام)']
        ];
    }

    public function matchWilaya(string $rawInput): string {
        $clean = trim($rawInput);
        if (empty($clean)) return '';

        if (is_numeric($clean)) {
            $num = sprintf('%02d', (int)$clean);
            foreach ($this->getAlgerianWilayas() as $w) {
                if ($w['code'] === $num) return $w['full'];
            }
        }

        foreach ($this->getAlgerianWilayas() as $w) {
            if (stripos($clean, $w['name']) !== false || stripos($w['name'], $clean) !== false || $clean === $w['code']) {
                return $w['full'];
            }
        }

        return $clean;
    }

    public function getDefaultDocumentTemplates(): array {
        return [
            ['code' => 'fiche_familiale', 'label_fr' => 'Fiche familiale', 'label_ar' => 'البطاقة العائلية للحالة المدنية', 'mandatory' => true],
            ['code' => 'cni_recto', 'label_fr' => 'Carte d\'identité (Recto)', 'label_ar' => 'صورة بطاقة الهوية (الوجه الأمامي)', 'mandatory' => true],
            ['code' => 'cni_verso', 'label_fr' => 'Carte d\'identité (Verso)', 'label_ar' => 'صورة بطاقة الهوية (الوجه الخلفي)', 'mandatory' => true],
            ['code' => 'photo_identite', 'label_fr' => 'Photo d\'identité', 'label_ar' => 'صورة الهوية', 'mandatory' => true],
            ['code' => 'residence', 'label_fr' => 'Résidence', 'label_ar' => 'شهادة الإقامة', 'mandatory' => true],
            ['code' => 'carte_chifa', 'label_fr' => 'Carte Chifa', 'label_ar' => 'بطاقة الشفاء', 'mandatory' => true],
            ['code' => 'extrait_naissance', 'label_fr' => 'Extrait de naissance', 'label_ar' => 'شهادة الميلاد', 'mandatory' => true],
            ['code' => 'cheque_releve_ccp', 'label_fr' => 'Chèque CCP barré ou Relevé CCP', 'label_ar' => 'صك بريدي مشطوب أو كشف الحساب البريدي', 'mandatory' => true],
            ['code' => 'attestation_travail', 'label_fr' => 'Attestation de travail', 'label_ar' => 'شهادة العمل', 'mandatory' => true],
            ['code' => 'fiche_paie_1', 'label_fr' => 'Fiche de paie 1', 'label_ar' => 'كشف الراتب 1', 'mandatory' => true],
            ['code' => 'fiche_paie_2', 'label_fr' => 'Fiche de paie 2', 'label_ar' => 'كشف الراتب 2', 'mandatory' => true],
            ['code' => 'fiche_paie_3', 'label_fr' => 'Fiche de paie 3', 'label_ar' => 'كشف الراتب 3', 'mandatory' => true],
            ['code' => 'releve_emoluments', 'label_fr' => 'Relevé des émoluments', 'label_ar' => 'كشف الأجور', 'mandatory' => true],
        ];
    }

    public function getDocumentTemplates(): array {
        $saved = $this->configMapper->getValue('document_templates');
        if (!empty($saved)) {
            $decoded = json_decode($saved, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        return $this->getDefaultDocumentTemplates();
    }

    public function getCustomFields(): array {
        $saved = $this->configMapper->getValue('custom_fields');
        if (!empty($saved)) {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [
            ['name' => 'montant_credit', 'label' => 'Montant demandé (DZD)', 'type' => 'number', 'required' => false],
            ['name' => 'produit', 'label' => 'Type de produit / Financement', 'type' => 'text', 'required' => false],
            ['name' => 'notes_generales', 'label' => 'Remarques générales', 'type' => 'textarea', 'required' => false],
        ];
    }

    public function getDefaultBanks(): array {
        return [
            'BNA — Banque Nationale d\'Algérie',
            'BEA — Banque Extérieure d\'Algérie',
            'CPA — Crédit Populaire d\'Algérie',
            'BADR — Banque de l\'Agriculture et du Développement Rural',
            'BDL — Banque de Développement Local',
            'CNEP-Banque',
            'BNH — Banque Nationale de l\'Habitat',
            'Algérie Poste (CCP)',
            'AGB — Gulf Bank Algeria',
            'Al Baraka Bank Algeria',
            'Al Salam Bank Algeria',
            'Housing Bank Algeria',
            'Trust Bank Algeria',
            'Société Générale Algérie',
            'BNP Paribas El Djazaïr',
            'Natixis Algérie (Banxy)',
            'Arab Banking Corporation Algeria (ABC)',
            'Arab Bank PLC Algeria',
            'Fransabank El Djazaïr',
            'Citibank Algeria',
            'HSBC Algeria',
            'T.C. Ziraat Bankası Algeria',
        ];
    }

    public function getBanks(): array {
        $saved = $this->configMapper->getValue('banks');
        if (!empty($saved)) {
            $decoded = json_decode($saved, true);
            if (is_array($decoded) && !empty($decoded)) {
                $clean = [];
                foreach ($decoded as $bank) {
                    $name = trim((string)$bank);
                    if ($name !== '' && !in_array($name, $clean, true)) {
                        $clean[] = $name;
                    }
                }
                if (!empty($clean)) {
                    return $clean;
                }
            }
        }
        return $this->getDefaultBanks();
    }

    public function createDossier(array $data, ?string $userId = null): Dossier {
        $now = time();
        $user = $userId ?? ($this->userSession->getUser() ? $this->userSession->getUser()->getUID() : 'system');

        $dossier = new Dossier();
        $number = trim((string)($data['case_number'] ?? ''));
        $dossier->setCaseNumber($number !== '' ? $number : 'DOS-'.strtoupper(bin2hex(random_bytes(6))));
        $dossier->setClientName(trim($data['client_name'] ?? ''));
        $dossier->setPhone(trim($data['phone'] ?? ''));
        $dossier->setAddress(trim($data['address'] ?? ''));
        $dossier->setWilaya($this->matchWilaya($data['wilaya'] ?? ''));
        $dossier->setCommune(trim($data['commune'] ?? ''));
        $dossier->setBank(trim($data['bank'] ?? ''));
        $dossier->setStatus(self::STATUS_NEW);
        $dossier->setCreatedBy($user);
        $dossier->setCreatedAt($now);
        $dossier->setUpdatedAt($now);

        if (isset($data['custom_data']) && is_array($data['custom_data'])) {
            $dossier->setCustomData(json_encode($data['custom_data'], JSON_UNESCAPED_UNICODE));
        }

        if ($dossier->getClientName() === '') throw new ValidationException('Le nom du client est obligatoire.');
        if (mb_strlen($dossier->getCaseNumber()) > 64) throw new ValidationException('Numéro de dossier trop long (64 caractères maximum).');
        if ($this->caseNumberExists($dossier->getCaseNumber())) throw new DuplicateException('Ce numéro de dossier existe déjà.');
        $this->db->beginTransaction();
        try {
        $savedDossier = $this->dossierMapper->insert($dossier);
        $dossierId = (int)$savedDossier->getId();

        // Initialiser la checklist par requêtes directes sécurisées
        $templates = $this->getDocumentTemplates();
        foreach ($templates as $t) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('coord_checklists')
                   ->values([
                       'dossier_id' => $qb->createNamedParameter($dossierId),
                       'document_code' => $qb->createNamedParameter($t['code']),
                       'document_label_fr' => $qb->createNamedParameter($t['label_fr']),
                       'document_label_ar' => $qb->createNamedParameter($t['label_ar']),
                       'is_mandatory' => $qb->createNamedParameter(!empty($t['mandatory']) ? 1 : 0),
                       'coordinator_checked' => $qb->createNamedParameter(0),
                       'backoffice_checked' => $qb->createNamedParameter(0),
                   ]);
                $qb->executeStatement();
        }

        $this->addHistory($dossierId, $user, 'create', 'Création du dossier');

        $this->db->commit();
        return $savedDossier;
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** Vérification applicative : l'index historique n'est pas unique (voir REPRISE_IA). */
    public function caseNumberExists(string $caseNumber, ?int $exceptId = null): bool {
        $q = $this->db->getQueryBuilder();
        $q->select('id')->from('coord_dossiers')->where($q->expr()->eq('case_number',$q->createNamedParameter($caseNumber)))->setMaxResults(2);
        $r = $q->executeQuery(); $rows = $r->fetchAll(); $r->closeCursor();
        foreach ($rows as $row) if ($exceptId === null || (int)$row['id'] !== $exceptId) return true;
        return false;
    }

    public function addHistory(int $dossierId, string $userId, string $action, string $details): void {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('coord_histories')
               ->values([
                   'dossier_id' => $qb->createNamedParameter($dossierId),
                   'user_id' => $qb->createNamedParameter($userId),
                   'action' => $qb->createNamedParameter($action),
                   'details' => $qb->createNamedParameter($details),
                   'created_at' => $qb->createNamedParameter(time()),
               ]);
            $qb->executeStatement();
    }

    /** Read a dossier and initialize a missing legacy checklist after caller authorization. */
    public function getDossierDetails(int $id): array {
        // 1. Dossier
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('coord_dossiers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();

        if (!$row) {
            throw new Exception("Dossier #$id introuvable en base");
        }

        $customDecoded = [];
        if (!empty($row['custom_data'])) {
            $dec = json_decode($row['custom_data'], true);
            if (is_array($dec)) $customDecoded = $dec;
        }

        $dossierData = [
            'id' => (int)$row['id'],
            'case_number' => (string)$row['case_number'],
            'client_name' => (string)$row['client_name'],
            'phone' => (string)($row['phone'] ?? ''),
            'address' => (string)($row['address'] ?? ''),
            'wilaya' => (string)($row['wilaya'] ?? ''),
            'commune' => (string)($row['commune'] ?? ''),
            'bank' => (string)($row['bank'] ?? ''),
            'status' => (string)$row['status'],
            'assigned_coordinator' => (string)($row['assigned_coordinator'] ?? ''),
            'appointment_date' => (string)($row['appointment_date'] ?? ''),
            'appointment_time' => (string)($row['appointment_time'] ?? ''),
            'appointment_notes' => (string)($row['appointment_notes'] ?? ''),
            'custom_data' => $customDecoded,
            'created_by' => (string)($row['created_by'] ?? ''),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'revision' => (int)($row['revision'] ?? 0),
        ];

        // 2. Checklist
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select('*')
            ->from('coord_checklists')
            ->where($qb2->expr()->eq('dossier_id', $qb2->createNamedParameter($id)))
            ->orderBy('id', 'ASC');
        $res2 = $qb2->executeQuery();
        $checklistRows = $res2->fetchAll();
        $res2->closeCursor();

        // Auto-création de la checklist si elle manquait
        if (empty($checklistRows)) {
            $templates = $this->getDocumentTemplates();
            foreach ($templates as $t) {
                try {
                    $qbi = $this->db->getQueryBuilder();
                    $qbi->insert('coord_checklists')
                        ->values([
                            'dossier_id' => $qbi->createNamedParameter($id),
                            'document_code' => $qbi->createNamedParameter($t['code']),
                            'document_label_fr' => $qbi->createNamedParameter($t['label_fr']),
                            'document_label_ar' => $qbi->createNamedParameter($t['label_ar']),
                            'is_mandatory' => $qbi->createNamedParameter(!empty($t['mandatory']) ? 1 : 0),
                            'coordinator_checked' => $qbi->createNamedParameter(0),
                            'backoffice_checked' => $qbi->createNamedParameter(0),
                        ]);
                    $qbi->executeStatement();
                } catch (\Throwable $e) {}
            }

            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('*')
                ->from('coord_checklists')
                ->where($qb2->expr()->eq('dossier_id', $qb2->createNamedParameter($id)))
                ->orderBy('id', 'ASC');
            $res2 = $qb2->executeQuery();
            $checklistRows = $res2->fetchAll();
            $res2->closeCursor();
        }

        $formattedChecklist = array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'dossier_id' => (int)$c['dossier_id'],
                'document_code' => (string)$c['document_code'],
                'document_label_fr' => (string)$c['document_label_fr'],
                'document_label_ar' => (string)$c['document_label_ar'],
                'is_mandatory' => (bool)$c['is_mandatory'],
                'coordinator_checked' => (bool)$c['coordinator_checked'],
                'coordinator_notes' => $c['coordinator_notes'],
                'coordinator_checked_at' => $c['coordinator_checked_at'] ? (int)$c['coordinator_checked_at'] : null,
                'backoffice_checked' => (bool)$c['backoffice_checked'],
                'backoffice_notes' => $c['backoffice_notes'],
                'backoffice_checked_at' => $c['backoffice_checked_at'] ? (int)$c['backoffice_checked_at'] : null,
            ];
        }, $checklistRows);

        // 3. Historique
        $qb3 = $this->db->getQueryBuilder();
        $qb3->select('*')
            ->from('coord_histories')
            ->where($qb3->expr()->eq('dossier_id', $qb3->createNamedParameter($id)))
            ->orderBy('id', 'DESC');
        $res3 = $qb3->executeQuery();
        $historyRows = $res3->fetchAll();
        $res3->closeCursor();

        $formattedHistory = array_map(function($h) {
            return [
                'id' => (int)$h['id'],
                'dossier_id' => (int)$h['dossier_id'],
                'user_id' => (string)$h['user_id'],
                'action' => (string)$h['action'],
                'details' => $h['details'],
                'created_at' => (int)$h['created_at'],
            ];
        }, $historyRows);

        return [
            'dossier' => $dossierData,
            'checklist' => $formattedChecklist,
            'history' => $formattedHistory,
        ];
    }
}
