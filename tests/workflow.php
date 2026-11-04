<?php
namespace OCP {
 interface IUserSession {} interface IGroupManager {} interface IConfig {} interface IRequest {} interface IDBConnection {}
}
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_INT=1, PARAM_INT_ARRAY=101, PARAM_STR_ARRAY=102; } }
namespace Psr\Log { interface LoggerInterface {} }
namespace OCP\AppFramework { class Controller { protected $request; public function __construct($name,$request) { $this->request=$request; } } }
namespace OCP\AppFramework\Http { class JSONResponse { public function __construct(public $data,public $status=200) {} } }
namespace OCA\Coordination\Db {
 class Dossier {
  public array $v=['id'=>1,'status'=>'assigned','assignedCoordinator'=>'coord','appointmentDate'=>'2026-11-03','appointmentTime'=>'10:00','caseNumber'=>'DOS-1','revision'=>3];
  public function __call($method,$args) { $k=lcfirst(substr($method,3)); if(str_starts_with($method,'set')) $this->v[$k]=$args[0]; else return $this->v[$k]??null; }
 }
 class DossierMapper {
  public int $updates=0; public array $filter=[];
  public function __construct(public Dossier $d) {}
  public function findById($id) { return $id===1 ? $this->d : null; }
  public function update($d) { $this->updates++; }
  public ?\Throwable $fail=null;
  public function findFiltered(...$f) { if($this->fail) throw $this->fail; $this->filter=$f; return array_fill(0,35,$this->d); }
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
  public function caseNumberExists($n,$except=null) { return $n==='DUP'; }
 }
 /** Double : nombre de scans par pièce, configurable par test. */
 class ScanService {
  public array $counts=[]; public array $removed=[];
  public function countForPiece($itemId) { return $this->counts[$itemId]??0; }
  public function attach($id,$pieces) { foreach($pieces as &$p) $p['scan_count']=$this->counts[$p['id']]??0; return $pieces; }
  public function deleteRowsForDossier($id) {} public function removeFolder($id) { $this->removed[]=$id; }
 }
}
namespace {
 foreach(['PublicException','ConflictException','ValidationException','DuplicateException','Access','Workflow','DossierLock'] as $c) require_once __DIR__."/../lib/Service/$c.php";
 require_once __DIR__.'/../lib/Controller/ApiResponses.php'; require __DIR__.'/../lib/Controller/DossierApiController.php';
 class Logger implements \Psr\Log\LoggerInterface { public array $logs=[]; public function error($m,$c=[]) { $this->logs[]=[$m,$c]; } public function warning($m,$c=[]) { $this->logs[]=[$m,$c]; } }
 class User { public function __construct(public string $uid) {} public function getUID() { return $this->uid; } }
 class Session implements \OCP\IUserSession { public string $uid='coord'; public function getUser() { return new User($this->uid); } }
 class Config implements \OCP\IConfig { public array $v=[]; public function getAppValue($app,$key,$default) { return $this->v[$key]??$default; } }
 class Groups implements \OCP\IGroupManager {
  public function isAdmin($uid) { return $uid==='admin'; }
  public function isInGroup($uid,$gid) { return in_array($uid, ['csm_coordinateur'=>['coord','other'],'csm_rcoordinateur'=>['manager'],'csm_backoffice'=>['back'],'callcrm_commercial'=>['sales'],'support_resp_visiteurs'=>['outsider']][$gid]??[],true); }
 }
 class Request implements \OCP\IRequest { public array $p=[]; public array $upload=[]; public function getUploadedFile($name) { return $this->upload; } public function getParam($k,$d=null) { return $this->p[$k]??$d; } public function getParams() { return $this->p; } }
 class Database implements \OCP\IDBConnection {
  public int $writes=0; public array $operations=[]; public ?int $affected=null; public $dossier=null;
  public function beginTransaction() {} public function commit() {} public function rollBack() {} public function getQueryBuilder() { return new Query($this); }
  public function escapeLikeParameter($v) { return addcslashes($v,'\\%_'); }
 }
 class Result { public function __construct(private array $rows) {} public function fetchAll() { return $this->rows; } public function fetch() { return $this->rows[0]??false; } public function closeCursor() {} }
 class Query {
  public string $table=''; public array $sets=[]; public array $conds=[];
  public function __construct(private Database $db) {}
  public function update($table) { $this->table=$table; return $this; }
  public function set($key,$value) { $this->sets[$key]=$value; return $this; }
  public function expr() { return $this; }
  public function eq($a,$b) { return [$a,$b]; }
  public function in($a,$b) { return [$a,$b]; }
  public function where($c) { $this->conds[]=$c; return $this; }
  public function andWhere($c) { $this->conds[]=$c; return $this; }
  public function createNamedParameter($v,$type=null) { return $v; }
  public function createFunction($f) { return 'FN:'.$f; }
  public function executeQuery() { return new Result([['id'=>1]]); }
  /** Applique les conditions statut/révision du dossier simulé, comme le ferait la base. */
  public function executeStatement() {
   if ($this->db->affected!==null && $this->table==='coord_dossiers') { $this->db->operations[]=['table'=>$this->table,'sets'=>$this->sets,'refused'=>true]; return $this->db->affected; }
   if ($this->table==='coord_dossiers' && $this->db->dossier) {
    foreach($this->conds as [$col,$val]) {
     if ($col==='status' && !in_array($this->db->dossier->getStatus(),(array)$val,true)) return 0;
     if ($col==='revision' && (int)$this->db->dossier->getRevision()!==(int)$val) return 0;
    }
   }
   $this->db->writes++; $this->db->operations[]=['table'=>$this->table,'sets'=>$this->sets]; return 1;
  }
  public function __call($m,$a) { return $this; }
 }
 $session=new Session();$groups=new Groups();$config=new Config();$request=new Request();$db=new Database();
 $dossier=new \OCA\Coordination\Db\Dossier();$mapper=new \OCA\Coordination\Db\DossierMapper($dossier);$service=new \OCA\Coordination\Service\DossierService();
 $access=new \OCA\Coordination\Service\Access($session,$groups,$config);
 $db->dossier=$dossier;$scans=new \OCA\Coordination\Service\ScanService();$logger=new Logger();
 $ctrl=new \OCA\Coordination\Controller\DossierApiController('coordination',$request,$mapper,new \OCA\Coordination\Db\DossierChecklistMapper(),$service,$groups,$session,$db,$access,new \OCA\Coordination\Service\DossierLock($db),$scans,$logger);
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
 $service->pieces[0]['coordinator_checked']=true;$r=$ctrl->changeStatus(1);check($r->status===409&&str_contains($r->data['error'],'scannée'),'validated pieces without PDF scan block collection');
 $scans->counts[1]=1;check($ctrl->changeStatus(1)->data['success'],'scanned and validated mandatory pieces permit collection');$scans->counts=[];
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
 $W='\OCA\Coordination\Service\Workflow';$config->v=[];
 // --- 1.2.0 : attribution, concurrence, erreurs, scans ---
 $session->uid='manager';$dossier->setStatus('collected');$dossier->setAssignedCoordinator('coord');
 $request->p=['ids'=>[1],'assigned_coordinator'=>'other'];$r=$ctrl->batchAssign();
 check($r->data['success']&&$r->data['updated_count']===0&&$r->data['skipped_count']===1,'batch assignment skips dossiers already sent to back-office');
 $request->p=['wilaya'=>'16 - Alger','assigned_coordinator'=>'other'];$r=$ctrl->batchAssign();
 check($r->data['updated_count']===0,'wilaya assignment never reassigns collected or validated dossiers');
 $request->p=['assigned_coordinator'=>'other'];check($ctrl->assign(1)->status===409,'single reassignment of a collected dossier refused');
 $dossier->setStatus('assigned');$db->operations=[];$request->p=['assigned_coordinator'=>null];$r=$ctrl->assign(1);
 check($r->data['success']&&in_array('new',array_column($db->operations,'sets')[0],true),'removing the coordinator resets status to new');
 $dossier->setAssignedCoordinator('coord');$request->p=['ids'=>[1],'assigned_coordinator'=>'other'];$r=$ctrl->batchAssign();
 check($r->data['updated_count']===1,'batch assignment still updates dossiers in progress');
 $dossier->setAssignedCoordinator('coord');
 $request->p=['client_name'=>'Nouveau nom','revision'=>2];$before=$mapper->updates;$r=$ctrl->update(1);
 check($r->status===409&&$mapper->updates===$before,'stale revision cannot overwrite a newer edit');
 $request->p=['client_name'=>'Nouveau nom','revision'=>3];check($ctrl->update(1)->data['success'],'current revision accepted');
 $request->p=['case_number'=>'DUP'];check($ctrl->update(1)->status===409,'renaming to an existing dossier number refused');
 $request->p=['client_name'=>'  '];check($ctrl->update(1)->status===400,'client name cannot be blanked');
 $session->uid='back';$dossier->setStatus('collected');$service->pieces[0]['backoffice_checked']=true;$db->affected=0;$before=$mapper->updates;
 $request->p=['status'=>'validated'];$r=$ctrl->changeStatus(1);
 check($r->status===409&&$mapper->updates===$before,'concurrent back-office decision detected and refused');
 $request->p=['checklist_item_id'=>1,'role'=>'backoffice','checked'=>false];check($ctrl->updateChecklist(1)->status===409,'checklist write refused when dossier changed meanwhile');
 $db->affected=null;
 // Parcours : RDV -> scan -> validation de la pièce
 $session->uid='coord';$dossier->setStatus('assigned');$request->p=['checklist_item_id'=>1,'role'=>'coordinator','checked'=>true];
 $r=$ctrl->updateChecklist(1);check($r->status===403&&str_contains($r->data['error'],'rendez-vous'),'pieces cannot be validated before the appointment');
 $dossier->setStatus('rejected');check($ctrl->updateChecklist(1)->status===403,'after rejection a new appointment comes first');
 $dossier->setStatus('appointment_set');$r=$ctrl->updateChecklist(1);check($r->status===409&&str_contains($r->data['error'],'Scannez'),'piece cannot be validated before it is scanned');
 $scans->counts[1]=1;check($ctrl->updateChecklist(1)->data['success'],'scanned piece can be validated after the appointment');
 $request->p=['checklist_item_id'=>1,'role'=>'coordinator','checked'=>false];$scans->counts=[];check($ctrl->updateChecklist(1)->data['success'],'a piece can always be unchecked');
 $dossier->setStatus('rejected');$request->p=['appointment_date'=>'2026-11-05','appointment_time'=>'09:30'];check($ctrl->setAppointment(1)->data['success'],'rejected dossier gets a new appointment');
 $config->v=[];$scans->counts=[];
 $session->uid='manager';$mapper->fail=new \RuntimeException('SQLSTATE[42S02] secret table coord_dossiers');$r=$ctrl->index();
 check($r->status===500&&!str_contains($r->data['error'],'SQLSTATE')&&count($logger->logs)===1,'technical errors are logged, not shown to users');
 $mapper->fail=null;
 $request->p=[];check($ctrl->destroy(1)->data['success']&&$scans->removed===[1],'dossier deletion also removes its scans');
 $roles=fn($u)=>(function() use($u,$session,$access){$session->uid=$u;return $access->roles();})();
 check(!$W::canViewScans($roles('sales'),'sales','coord'),'sales cannot view scanned identity documents');
 check($W::canViewScans($roles('back'),'back','coord')&&$W::canViewScans($roles('coord'),'coord','coord')&&!$W::canViewScans($roles('other'),'other','coord'),'back-office and assigned coordinator view scans, other coordinators do not');
 check($W::scanUploadError($roles('coord'),'coord','coord','appointment_set')===null&&$W::scanUploadError($roles('coord'),'coord','coord','collected')!==null,'coordinator scans until the dossier is sent');
 check($W::scanUploadError($roles('coord'),'coord','coord','assigned')===$W::STAGE_ERROR,'no scan before the appointment is set');
 check($W::scanUploadError($roles('back'),'back','coord','appointment_set')!==null,'back-office cannot add coordinator scans');
 check($W::scanDeleteError($roles('coord'),'coord','coord','assigned','manager')!==null&&$W::scanDeleteError($roles('manager'),'manager','coord','validated','coord')===null,'only author or manager deletes a scan');
 echo "$n checks passed\n";
}
