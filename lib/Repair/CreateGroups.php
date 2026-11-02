<?php
namespace OCA\Coordination\Repair;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
class CreateGroups implements IRepairStep {
    public function __construct(private IGroupManager $groups) {}
    public function getName(): string { return 'Créer les groupes Coordination manquants'; }
    public function run(IOutput $output): void {
        foreach (['csm_coordinateur','csm_rcoordinateur','csm_backoffice'] as $gid) {
            if (!$this->groups->groupExists($gid)) $this->groups->createGroup($gid);
        }
    }
}
