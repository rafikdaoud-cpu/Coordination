<?php
namespace OCA\Coordination\Service;

/** Stateless rules are shared by endpoints and covered independently. */
class Workflow {
    public static function canCoordinate(array $roles, string $uid, ?string $assigned): bool {
        return $roles['rcoord'] || ($roles['coord'] && $assigned === $uid);
    }
    public static function checklistError(array $roles, string $uid, ?string $assigned, string $status, string $side): ?string {
        if ($side === 'coordinator') {
            if (!self::canCoordinate($roles,$uid,$assigned)) return 'Action réservée au coordinateur affecté ou au responsable.';
            if (!in_array($status,['assigned','appointment_set','rejected'],true)) return 'Les pièces du coordinateur ne sont plus modifiables à cette étape.';
        } elseif ($side === 'backoffice') {
            if (!$roles['back']) return 'Le contrôle de conformité est réservé au Back-Office.';
            if ($status !== 'collected') return 'Le dossier doit être collecté avant le contrôle Back-Office.';
        } else return 'Type de contrôle invalide.';
        return null;
    }
    public static function transitionError(array $roles, string $uid, ?string $assigned, string $old, string $new, array $pieces, ?string $date, ?string $time, string $note): ?string {
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
        return null;
    }
    public static function validAppointment(string $date, string $time): bool {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i',$date.' '.$time);
        return $d !== false && $d->format('Y-m-d H:i') === $date.' '.$time;
    }
}
