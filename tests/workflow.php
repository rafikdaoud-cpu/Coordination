<?php
namespace OCP {
 interface IUserSession {} interface IGroupManager {} interface IConfig {} interface IRequest {} interface IDBConnection {}
}
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_INT=1, PARAM_INT_ARRAY=101; } }
namespace OCP\AppFramework { class Controller { protected $request; public function __construct($name,$request) { $this->request=$request; } } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public $data,public $status=200) {} } }
namespace OCA\Coordination\Db {
 class Dossier {
  public array $v=['id'=>1,'status'=>'assigned','assignedCoordinator'=>'coord','appointmentDate'=>'2026-11-03','appointmentTime'=>'10:00'];
  public function __call($method,$args) { $k=lcfirst(substr($method,3)); if(str_starts_with($method,'set')) $this->v[$k]=$args[0]; else return $this->v[$k]??null; }
 }
 class DossierMapper {
  public int $updates=0; public array $filter=[];
  public function __construct(public Dossier $d) {}
  public function findById($id) { return $id===1 ? $this->d : null; }
  public function update($d) { $this->updates++; }
  public function findFiltered(...$f) { $this->filter=$f; return array_fill(0,35,$this->d); }
 }
 class DossierChecklistMapper {}
}
namespace OCA\Coordination\Service {
 class DossierService {
  const STATUS_NEW='new', STATUS_ASSIGNED='assigned', STATUS_APPOINTMENT_SET='appointment_set', STATUS_COLLECTED='collected';
  public int $reads=0; public array $history=[]; public array $created=[];
  public function createDossier($data,$actor) { $this->created[]=$data; return $data; }
  public array $pieces=[['id'=>1,'is_mandatory'=>true,'coordinator_checked'=>false,'backoffice_checked'=>false]];
  public function getDossierDetails($id) { $this->reads++; return ['dossier'=>['assigned_coordinator'=>'coord'],'checklist'=>$this->pieces]; }
  public function addHistory(...$args) { $this->history[]=$args; }
 }
}
namespace {
 require __DIR__.'/../lib/Service/Access.php'; require __DIR__.'/../lib/Service/Workflow.php'; require __DIR__.'/../lib/Controller/DossierApiController.php';
 class User { public function __construct(public string $uid) {} public function getUID() { return $this->uid; } }
 class Session implements \OCP\IUserSession { public string $uid='coord'; public function getUser() { return new User($this->uid); } }
 class Config implements \OCP\IConfig { public array $v=[]; public function getAppValue($app,$key,$default) { return $this->v[$key]??$default; } }
 class Groups implements \OCP\IGroupManager {
  public function isAdmin($uid) { return $uid==='admin'; }
  public function isInGroup($uid,$gid) { return in_array($uid, ['csm_coordinateur'=>['coord','other'],'csm_rcoordinateur'=>['manager'],'csm_backoffice'=>['back'],'callcrm_commercial'=>['sales'],'support_resp_visiteurs'=>['outsider']][$gid]??[],true); }
 }
 class Request implements \OCP\IRequest { public array $p=[]; public array $upload=[]; public function getUploadedFile($name) { return $this->upload; } public function getParam($k,$d=null) { return $this->p[$k]??$d; } public function getParams() { return $this->p; } }
 class Database implements \OCP\IDBConnection {
  public int $writes=0; public array $operations=[]; public function beginTransaction() {} public function commit() {} public function rollBack() {} public function getQueryBuilder() { return new Query($this); }
 }
 class Query {
  public string $table=''; public array $sets=[];
  public function __construct(private Database $db) {}
  public function update($table) { $this->table=$table; return $this; }
  public function set($key,$value) { $this->sets[$key]=$value; return $this; }
  public function expr() { return $this; }
  public function eq($a,$b) { return [$a,$b]; }
  public function createNamedParameter($v,$type=null) { return $v; }
  public function executeStatement() { $this->db->writes++; $this->db->operations[]=['table'=>$this->table,'sets'=>$this->sets]; return 1; }
  public function __call($m,$a) { return $this; }
 }
 $session=new Session();$groups=new Groups();$config=new Config();$request=new Request();$db=new Database();
 $dossier=new \OCA\Coordination\Db\Dossier();$mapper=new \OCA\Coordination\Db\DossierMapper($dossier);$service=new \OCA\Coordination\Service\DossierService();
 $access=new \OCA\Coordination\Service\Access($session,$groups,$config);
 $ctrl=new \OCA\Coordination\Controller\DossierApiController('coordination',$request,$mapper,new \OCA\Coordination\Db\DossierChecklistMapper(),$service,$groups,$session,$db,$access);
 $n=0;function check($ok,$label){global $n;if(!$ok)throw new \RuntimeException($label);$n++;echo "PASS $label\n";}
 foreach(['admin','manager','coord','back','sales'] as $uid){$session->uid=$uid;check($access->canView(),"$uid recognized explicitly");}
 $session->uid='outsider';check(!$access->canManage()&&!$access->canView(),'partial group name does not grant manager rights');
 check($ctrl->index()->status===403,'unregistered user denied list');
 check($ctrl->show(1)->status===403,'unregistered user denied details');
 $session->uid='admin';$request->p=['delete_id'=>1];check($ctrl->index()->status===405 && $db->writes===0,'GET cannot delete dossiers');
 $session->uid='coord';$request->p=[];$list=$ctrl->index(limit:1000);
 check($mapper->filter[2]==='coord','coordinateur list forced to own assignment');
 check($mapper->filter[4]===101,'page limit bounded to 100 plus lookahead');
 check($list->data['count']===35,'bounded list response reports actual count');
 $list=$ctrl->index(limit:30);check($list->data['has_more']&&$list->data['next_offset']===30,'pagination does not silently truncate remaining dossiers');
 $session->uid='other';check(!$ctrl->show(1)->data['success']&&$service->reads===0,'denied detail does not invoke auto-repair/read service');
 $session->uid='coord';$request->p=['checklist_item_id'=>1,'role'=>'backoffice','checked'=>true];
 check(!$ctrl->updateChecklist(1)->data['success']&&$db->writes===0,'coordinateur cannot impersonate back-office through request body');
 $request->p=['status'=>'validated'];check(!$ctrl->changeStatus(1)->data['success']&&$mapper->updates===0,'coordinateur cannot validate dossier through API');
 $request->p=['status'=>'anything'];check(!$ctrl->changeStatus(1)->data['success'],'unrecognized status cannot be stored');
 $request->p=['appointment_date'=>'2026-02-30','appointment_time'=>'10:00'];check(!$ctrl->setAppointment(1)->data['success'],'invalid calendar date rejected');
 $request->p=['appointment_date'=>'2026-11-03','appointment_time'=>'25:00'];check(!$ctrl->setAppointment(1)->data['success'],'invalid time rejected');
 $request->p=['appointment_date'=>'2026-11-03','appointment_time'=>'10:00'];check($ctrl->setAppointment(1)->data['success'],'coordinateur can set own valid appointment');
 $request->p=['status'=>'collected'];check(!$ctrl->changeStatus(1)->data['success'],'incomplete mandatory pieces block collection');
 $service->pieces[0]['coordinator_checked']=true;check($ctrl->changeStatus(1)->data['success'],'checked mandatory pieces permit collection');
 check(in_array(['table'=>'coord_checklists','sets'=>['backoffice_checked'=>0,'backoffice_checked_at'=>null]],$db->operations,true),'resubmission clears prior conformity approval before a new back-office decision');
 $session->uid='back';$request->p=['checklist_item_id'=>1,'role'=>'coordinator','checked'=>true];check(!$ctrl->updateChecklist(1)->data['success'],'back-office cannot alter coordinateur check');
 $request->p=['checklist_item_id'=>999,'role'=>'backoffice','checked'=>true];check($ctrl->updateChecklist(1)->status===404,'piece must belong to targeted dossier');
 $request->p=['checklist_item_id'=>1,'role'=>'backoffice','checked'=>true];check($ctrl->updateChecklist(1)->data['success'],'back-office can check collected dossier');
 $request->p=['status'=>'validated'];check(!$ctrl->changeStatus(1)->data['success'],'incomplete conformity checks block validation');
 $service->pieces[0]['backoffice_checked']=true;check($ctrl->changeStatus(1)->data['success'],'back-office validates only after mandatory conformity checks');
 check(!$ctrl->updateChecklist(1)->data['success'],'validated checklist is frozen');
 $dossier->setStatus('collected');$request->p=['status'=>'rejected','note'=>''];check(!$ctrl->changeStatus(1)->data['success'],'rejection requires explanation');
 $request->p=['status'=>'rejected','note'=>'Document illisible'];check($ctrl->changeStatus(1)->data['success'],'back-office can reject with reason');
 $session->uid='sales';$request->p=['status'=>'collected'];check(!$ctrl->changeStatus(1)->data['success'],'commercial remains read-only');
 check($access->roles()['sales']&&!$access->roles()['coord'],'CRM sales membership does not grant coordination writes');
 $config->v['group_coordinator']='other_exact_group';$session->uid='coord';check(!$access->roles()['coord'],'explicit configured group replaces default mapping');
 check(\OCA\Coordination\Service\Workflow::validAppointment('2028-02-29','09:15'),'valid leap day accepted');
 echo "$n checks passed\n";
}
