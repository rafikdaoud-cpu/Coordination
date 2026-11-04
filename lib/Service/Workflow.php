<?php
namespace OCA\Coordination\Service;

/** Stateless rules are shared by endpoints and covered independently. */
class Workflow {
    /** Étapes où le coordinateur travaille encore sur les pièces et le RDV. */
    public const COORDINATOR_STAGE = ['assigned','appointment_set','rejected'];
    /** Statuts où l'attribution peut encore changer ; après collecte elle est figée. */
    public const REASSIGNABLE = ['new','assigned','appointment_set','rejected'];

    public static function canCoordinate(array $roles, string $uid, ?string $assigned): bool {
        return $roles['rcoord'] || ($roles['coord'] && $assigned === $uid);
    }
    public static function checklistError(array $roles, string $uid, ?string $assigned, string $status, string $side): ?string {
        if ($side === 'coordinator') {
            if (!self::canCoordinate($roles,$uid,$assigned)) return 'Action réservée au coordinateur affecté ou au responsable.';
            if (!in_array($status,self::COORDINATOR_STAGE,true)) return 'Les pièces du coordinateur ne sont plus modifiables à cette étape.';
        } elseif ($side === 'backoffice') {
            if (!$roles['back']) return 'Le contrôle de conformité est réservé au Back-Office.';
            if ($status !== 'collected') return 'Le dossier doit être collecté avant le contrôle Back-Office.';
        } else return 'Type de contrôle invalide.';
        return null;
    }
    public static function transitionError(array $roles, string $uid, ?string $assigned, string $old, string $new, array $pieces, ?string $date, ?string $time, string $note, bool $scanRequired = false): ?string {
        if ($new === 'collected') {
            if (!self::canCoordinate($roles,$uid,$assigned)) return 'Collecte réservée au coordinateur affecté ou au responsable.';
            if ($old === $new) return null;
            if ($old !== 'appointment_set' || !$date || !$time || !self::validAppointment($date,$time)) return 'Fixez et enregistrez le rendez-vous avant de transmettre le dossier.';
            $field = 'coordinator_checked';
        } elseif (in_array($new,['validated','rejected'],true)) {
            if (!$roles['back']) return 'Validation et rejet réservés au Back-Office ou au responsable.';
            if ($old === $new) return null;
            if ($old !== 'collected') return 'Le dossier doit être collecté avant la décision Back-Office.';
            if ($new === 'rejected') return trim($note) === '' ? 'Le motif du rejet est obligatoire.' : null;
            $field = 'backoffice_checked';
        } else return 'Transition de statut non autorisée.';
        if (!$pieces) return 'La checklist est absente : contactez le responsable.';
        foreach ($pieces as $p) if (!empty($p['is_mandatory']) && empty($p[$field])) return 'Toutes les pièces obligatoires doivent être vérifiées avant cette étape.';
        if ($new === 'collected' && $scanRequired) {
            foreach ($pieces as $p) if (!empty($p['is_mandatory']) && empty($p['scan_count'])) return 'Chaque pièce obligatoire doit être scannée en PDF avant la transmission au Back-Office.';
        }
        return null;
    }
    public static function validAppointment(string $date, string $time): bool {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time);
        return $d !== false && $d->format('Y-m-d H:i') === $date.' '.$time;
    }

    /** Coordinateur affecté, Back-Office, responsable et administrateur. Les commerciaux ne voient pas les pièces d'identité scannées. */
    public static function canViewScans(array $roles, string $uid, ?string $assigned): bool {
        return $roles['admin'] || $roles['rcoord'] || $roles['back'] || ($roles['coord'] && $assigned === $uid);
    }
    public static function scanUploadError(array $roles, string $uid, ?string $assigned, string $status): ?string {
        if (!self::canCoordinate($roles,$uid,$assigned)) return 'Scan réservé au coordinateur affecté ou au responsable.';
        if (!in_array($status,self::COORDINATOR_STAGE,true)) return 'Les scans ne sont plus modifiables après la transmission au Back-Office.';
        return null;
    }
    public static function scanDeleteError(array $roles, string $uid, ?string $assigned, string $status, string $uploadedBy): ?string {
        if ($roles['rcoord']) return null;
        if ($error = self::scanUploadError($roles,$uid,$assigned,$status)) return $error;
        return $uploadedBy === $uid ? null : 'Seul l’auteur du scan ou le responsable peut le supprimer.';
    }
}
