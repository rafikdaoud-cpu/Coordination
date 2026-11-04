<?php
namespace OCA\Coordination\Service;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/** Explicit groups; partial words such as resp/office never grant privileges. */
class Access {
    public function __construct(private IUserSession $session, private IGroupManager $groups, private IConfig $config) {}
    public function groupIds(string $role): array {
        $defaults = ['manager'=>['csm_rcoordinateur'], 'coordinator'=>['csm_coordinateur'], 'backoffice'=>['csm_backoffice'], 'sales'=>['callcrm_commercial','easycrm_commercial','csm_commercial']];
        $saved = $this->config->getAppValue('coordination','group_'.$role,'');
        if ($saved === '') return $defaults[$role] ?? [];
        $decoded = json_decode($saved,true);
        return is_array($decoded) ? array_values(array_filter($decoded,'is_string')) : [$saved];
    }
    public function belongs(string $uid, string $role): bool {
        foreach ($this->groupIds($role) as $gid) if ($this->groups->isInGroup($uid,$gid)) return true;
        return false;
    }
    public function roles(): array {
        $user = $this->session->getUser();
        $r = ['admin'=>false,'rcoord'=>false,'coord'=>false,'back'=>false,'sales'=>false];
        if (!$user) return $r;
        $uid = $user->getUID();
        $r['admin'] = $this->groups->isAdmin($uid);
        $r['rcoord'] = $r['admin'] || $this->belongs($uid,'manager');
        $r['coord'] = $r['rcoord'] || $this->belongs($uid,'coordinator');
        $r['back'] = $r['rcoord'] || $this->belongs($uid,'backoffice');
        $r['sales'] = $this->belongs($uid,'sales');
        return $r;
    }
    public function canView(): bool { return in_array(true,$this->roles(),true); }
    public function canManage(): bool { return $this->roles()['rcoord']; }
    /** occ config:app:set coordination scan_required --value=1 : scan PDF exigé pour chaque pièce obligatoire avant la collecte. */
    public function scanRequired(): bool { return in_array($this->config->getAppValue('coordination','scan_required','0'),['1','true','yes'],true); }
}
