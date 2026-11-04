<?php
/** Scans PDF : vrai ScanService + ScanApiController, base SQLite, AppData en mémoire. */
namespace OCP { interface IUserSession {} interface IGroupManager {} interface IConfig {} interface IRequest {} interface IDBConnection {} }
namespace OCP\Files { class NotFoundException extends \Exception {} }
namespace OCP\Files\SimpleFS { interface ISimpleFolder {} }
namespace OCP\Files\AppData { interface IAppDataFactory {} }
namespace OCP\DB\QueryBuilder { interface IQueryBuilder { const PARAM_INT=1, PARAM_INT_ARRAY=101, PARAM_STR_ARRAY=102; } }
namespace Psr\Log { interface LoggerInterface {} }
namespace OCP\AppFramework { class Controller { protected $request; public function __construct($name,$request) { $this->request=$request; } } }
namespace OCP\AppFramework\Http {
 class Response { public function __construct(public $status=200, public array $headers=[]) {} }
 class JSONResponse extends Response { public function __construct(public $data,$status=200) { parent::__construct($status); } }
 class DataDisplayResponse extends Response { public function __construct(public $data,$status=200,array $headers=[]) { parent::__construct($status,$headers); } }
}
namespace OCA\Coordination\Db {
 class Dossier { public function __construct(public array $v) {} public function __call($m,$a) { $k=strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',substr($m,3))); return $this->v[$k]??null; } }
 class DossierMapper { public function __construct(private $db) {} public function findById($id) { $q=$this->db->pdo->prepare('SELECT * FROM coord_dossiers WHERE id=?');$q->execute([$id]);$r=$q->fetch();return $r?new Dossier($r):null; } }
}
namespace OCA\Coordination\Service {
 class DossierService { public function __construct(private $db) {} public function addHistory($id,$uid,$action,$details) { $this->db->pdo->prepare('INSERT INTO coord_histories(dossier_id,user_id,action,details)VALUES(?,?,?,?)')->execute([$id,$uid,$action,$details]); } }
}
namespace {
 foreach(['PublicException','ConflictException','ValidationException','DuplicateException','Access','Workflow','DossierLock','ScanService'] as $c) require_once __DIR__."/../lib/Service/$c.php";
 require_once __DIR__.'/../lib/Controller/ApiResponses.php'; require_once __DIR__.'/../lib/Controller/ScanApiController.php';

 /** Sous-ensemble du QueryBuilder Nextcloud traduit en SQL SQLite. */
 class SqlQuery {
  private string $kind='',$table='';private array $cols=[],$vals=[],$sets=[],$conds=[],$params=[];private string $order='';
  public function __construct(private SqliteDb $db) {}
  public function select(...$c){$this->kind='select';$this->cols=$c;return $this;} public function from($t){$this->table=$t;return $this;}
  public function insert($t){$this->kind='insert';$this->table=$t;return $this;} public function values($v){$this->vals=$v;return $this;}
  public function update($t){$this->kind='update';$this->table=$t;return $this;} public function set($k,$v){$this->sets[$k]=$v;return $this;}
  public function delete($t){$this->kind='delete';$this->table=$t;return $this;}
  public function where($c){$this->conds=[$c];return $this;} public function andWhere($c){$this->conds[]=$c;return $this;}
  public function orderBy($c,$d='ASC'){$this->order=" ORDER BY $c $d";return $this;} public function setMaxResults($n){return $this;}
  public function expr(){return $this;} public function eq($a,$b){return "$a = $b";} public function in($a,$b){return "$a IN ($b)";}
  public function createFunction($f){return $f;}
  public function createNamedParameter($v,$t=null){ if(is_array($v)) return implode(',',array_map(fn($x)=>$this->createNamedParameter($x),$v)); $k=':p'.count($this->params);$this->params[$k]=$v;return $k; }
  private function sql(){ $w=$this->conds?' WHERE '.implode(' AND ',$this->conds):'';
   return match($this->kind){'select'=>'SELECT '.implode(',',$this->cols).' FROM '.$this->table.$w.$this->order,'insert'=>'INSERT INTO '.$this->table.'('.implode(',',array_keys($this->vals)).')VALUES('.implode(',',$this->vals).')','update'=>'UPDATE '.$this->table.' SET '.implode(',',array_map(fn($k,$v)=>"$k = $v",array_keys($this->sets),$this->sets)).$w,'delete'=>'DELETE FROM '.$this->table.$w}; }
  private function run(){$q=$this->db->pdo->prepare($this->sql());$q->execute($this->params);return $q;}
  public function executeQuery(){return new class($this->run()){public function __construct(private $s){} public function fetch(){return $this->s->fetch(\PDO::FETCH_ASSOC);} public function fetchAll(){return $this->s->fetchAll(\PDO::FETCH_ASSOC);} public function closeCursor(){$this->s->closeCursor();}};}
  public function executeStatement(){return $this->run()->rowCount();} public function getLastInsertId(){return (int)$this->db->pdo->lastInsertId();}
 }
 class SqliteDb implements \OCP\IDBConnection {
  public \PDO $pdo;
  public function __construct(){$this->pdo=new \PDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$this->pdo->exec(
   'CREATE TABLE coord_dossiers(id INTEGER PRIMARY KEY,case_number TEXT,status TEXT,assigned_coordinator TEXT,revision INTEGER DEFAULT 0);
    CREATE TABLE coord_checklists(id INTEGER PRIMARY KEY,dossier_id INTEGER,document_code TEXT,document_label_fr TEXT,coordinator_checked INTEGER DEFAULT 0,coordinator_checked_at INTEGER);
    CREATE TABLE coord_histories(dossier_id INTEGER,user_id TEXT,action TEXT,details TEXT);
    CREATE TABLE coord_scans(id INTEGER PRIMARY KEY AUTOINCREMENT,dossier_id INTEGER,checklist_id INTEGER,file_name TEXT,original_name TEXT,size INTEGER,pages INTEGER,uploaded_by TEXT,uploaded_at INTEGER);
    INSERT INTO coord_dossiers VALUES(1,"DOS/1 é","appointment_set","coord",0),(2,"DOS-2","assigned","other",0);
    INSERT INTO coord_checklists VALUES(10,1,"cni","CNI",0,NULL),(11,1,"res","Résidence",0,NULL),(20,2,"cni","CNI",0,NULL);');}
  public function getQueryBuilder(){return new SqlQuery($this);}
  public function beginTransaction(){$this->pdo->beginTransaction();} public function commit(){$this->pdo->commit();} public function rollBack(){$this->pdo->rollBack();}
  public function count($sql){return (int)$this->pdo->query($sql)->fetchColumn();}
 }
 class MemFile { public function __construct(private MemFolder $f,private string $n) {} public function getContent(){return $this->f->files[$this->n];} public function delete(){unset($this->f->files[$this->n]);} }
 class MemFolder implements \OCP\Files\SimpleFS\ISimpleFolder {
  public array $files=[]; public function __construct(private MemRoot $root,private string $name) {}
  public function newFile($n,$c){$this->files[$n]=$c;return new MemFile($this,$n);} public function fileExists($n){return isset($this->files[$n]);}
  public function getFile($n){if(!isset($this->files[$n]))throw new \OCP\Files\NotFoundException($n);return new MemFile($this,$n);} public function delete(){unset($this->root->folders[$this->name]);}
 }
 class MemRoot { public array $folders=[]; public function getFolder($n){if(!isset($this->folders[$n]))throw new \OCP\Files\NotFoundException($n);return $this->folders[$n];} public function newFolder($n){return $this->folders[$n]=new MemFolder($this,$n);} }
 class AppDataFactory implements \OCP\Files\AppData\IAppDataFactory { public MemRoot $root; public function __construct(){$this->root=new MemRoot();} public function get($app){return $this->root;} }
 class User { public function __construct(public string $uid) {} public function getUID() { return $this->uid; } }
 class Session implements \OCP\IUserSession { public string $uid='coord'; public function getUser() { return new User($this->uid); } }
 class Config implements \OCP\IConfig { public function getAppValue($a,$k,$d) { return $d; } }
 class Groups implements \OCP\IGroupManager {
  public function isAdmin($uid) { return false; }
  public function isInGroup($uid,$gid) { return in_array($uid,['csm_coordinateur'=>['coord','other'],'csm_rcoordinateur'=>['manager'],'csm_backoffice'=>['back'],'callcrm_commercial'=>['sales']][$gid]??[],true); }
 }
 class Request implements \OCP\IRequest { public array $p=[]; public array $upload=[]; public function getUploadedFile($n) { return $this->upload; } public function getParam($k,$d=null) { return $this->p[$k]??$d; } }
 class Logger implements \Psr\Log\LoggerInterface { public array $logs=[]; public function error($m,$c=[]) { $this->logs[]=$m; } public function warning($m,$c=[]) { $this->logs[]=$m; } }

 $db=new SqliteDb();$appData=new AppDataFactory();$session=new Session();$request=new Request();$logger=new Logger();
 $access=new \OCA\Coordination\Service\Access($session,new Groups(),new Config());
 $scans=new \OCA\Coordination\Service\ScanService($db,$appData);
 $ctrl=new \OCA\Coordination\Controller\ScanApiController('coordination',$request,new \OCA\Coordination\Db\DossierMapper($db),new \OCA\Coordination\Service\DossierService($db),$scans,new \OCA\Coordination\Service\DossierLock($db),$access,$session,$db,$logger);
 $n=0;function check($ok,$label){global $n;if(!$ok)throw new \RuntimeException($label);$n++;echo "PASS $label\n";}
 $tmp=[];function upload(string $content,string $name='scan.pdf'){global $request,$tmp;$f=tempnam(sys_get_temp_dir(),'coord-scan-');file_put_contents($f,$content);$tmp[]=$f;$request->upload=['tmp_name'=>$f,'name'=>$name,'size'=>strlen($content),'error'=>UPLOAD_ERR_OK];}
 $pdf="%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n2 0 obj << /Type /Pages /Count 2 >> endobj\n3 0 obj << /Type /Page >> endobj\n4 0 obj << /Type /Page >> endobj\ntrailer << >>\n%%EOF\n";
 $files=fn()=>array_sum(array_map(fn($f)=>count($f->files),$appData->root->folders));
 try {
  upload($pdf,'CNI recto-verso.pdf');$r=$ctrl->upload(1,10);
  check($r->status===201&&$r->data['scan']['pages']===2,'assigned coordinator uploads a PDF scan for a piece');
  check($files()===1&&$db->count('SELECT count(*) FROM coord_scans')===1,'PDF stored in private app data with its metadata row');
  check($db->count("SELECT count(*) FROM coord_histories WHERE action='scan_upload'")===1,'scan upload recorded in dossier history');
  check($db->count('SELECT revision FROM coord_dossiers WHERE id=1')===1,'scan upload takes the dossier write lock');
  $scanId=$r->data['scan']['id'];
  upload('<html><script>alert(1)</script></html>%%EOF','faux.pdf');$r=$ctrl->upload(1,10);check($r->status===400&&$files()===1,'non-PDF content refused even with a .pdf name');
  upload("%PDF-1.4\n1 0 obj","coupe.pdf");check($ctrl->upload(1,10)->status===400,'truncated PDF refused');
  upload($pdf);check($ctrl->upload(1,20)->status===404,'piece of another dossier refused');
  $request->upload=['error'=>UPLOAD_ERR_INI_SIZE];check($ctrl->upload(1,10)->status===413,'server upload size limit reported clearly');
  $session->uid='other';upload($pdf);check($ctrl->upload(1,10)->status===404&&$ctrl->file(1,$scanId)->status===404,'other coordinator neither uploads nor sees scans');
  $session->uid='back';upload($pdf);check($ctrl->upload(1,10)->status===403,'back-office cannot add coordinator scans');
  $request->p=[];$r=$ctrl->file(1,$scanId);
  check($r instanceof \OCP\AppFramework\Http\DataDisplayResponse&&$r->data===$pdf&&$r->headers['Content-Type']==='application/pdf'&&str_starts_with($r->headers['Content-Disposition'],'inline'),'back-office opens the PDF in the browser');
  check(str_contains($r->headers['Content-Disposition'],'filename="DOS_1_cni_'.$scanId.'.pdf"'),'download name built from dossier and piece, sanitized');
  $request->p=['download'=>'1'];check(str_starts_with($ctrl->file(1,$scanId)->headers['Content-Disposition'],'attachment'),'back-office downloads the PDF');
  check($ctrl->file(1,999)->status===404&&$ctrl->file(2,$scanId)->status===404,'scan id must belong to the dossier');
  $session->uid='sales';check($ctrl->file(1,$scanId)->status===404,'commercial cannot open scanned documents');
  $session->uid='back';check($ctrl->destroy(1,$scanId)->status===403,'back-office cannot delete a coordinator scan');
  $session->uid='coord';$db->pdo->exec('INSERT INTO coord_scans(dossier_id,checklist_id,file_name,size,pages,uploaded_by,uploaded_at) VALUES '.implode(',',array_fill(0,9,"(1,10,'x.pdf',1,1,'coord',0)")));
  upload($pdf);$r=$ctrl->upload(1,10);check($r->status===409&&$files()===1,'per-piece limit enforced without leaving an orphan file');
  $db->pdo->exec("DELETE FROM coord_scans WHERE file_name='x.pdf'");
  $db->pdo->exec('UPDATE coord_checklists SET coordinator_checked=1 WHERE id=10');
  $r=$ctrl->destroy(1,$scanId);check($r->data['success']&&$files()===0&&$db->count('SELECT count(*) FROM coord_scans')===0,'author deletes scan: row and file removed');
  check($db->count('SELECT coordinator_checked FROM coord_checklists WHERE id=10')===0,'deleting the last scan of a validated piece puts it back to verify');
  upload($pdf);check($ctrl->upload(2,20)->status===404,'coordinator cannot scan a dossier assigned to someone else');
  $db->pdo->exec("UPDATE coord_dossiers SET status='assigned' WHERE id=1");upload($pdf);$r=$ctrl->upload(1,10);check($r->status===403&&str_contains($r->data['error'],'rendez-vous'),'no scan before the appointment');
  $db->pdo->exec("UPDATE coord_dossiers SET status='appointment_set' WHERE id=1");
  upload($pdf);$scanId=$ctrl->upload(1,11)->data['scan']['id'];
  $db->pdo->exec("UPDATE coord_dossiers SET status='collected'");
  upload($pdf);check($ctrl->upload(1,11)->status===403,'no new scan after transmission to back-office');
  check($ctrl->destroy(1,$scanId)->status===403,'coordinator cannot remove a scan once transmitted');
  $att=$scans->attach(1,[['id'=>10],['id'=>11]]);check($att[0]['scan_count']===0&&$att[1]['scan_count']===1&&!isset($att[1]['scans'][0]['file_name']),'checklist exposes scans without internal file names');
  $session->uid='manager';$db->pdo->exec("UPDATE coord_dossiers SET status='validated'");check($ctrl->destroy(1,$scanId)->data['success'],'manager can remove a scan at any stage');
  $session->uid='coord';$db->pdo->exec("UPDATE coord_dossiers SET status='appointment_set'");
  $db->pdo->exec('DROP TABLE coord_histories');upload($pdf);$r=$ctrl->upload(1,10);
  check($r->status===500&&$files()===0&&$db->count('SELECT count(*) FROM coord_scans')===0&&!str_contains($r->data['error'],'coord_histories')&&count($logger->logs)===1,'failed transaction rolls back row, deletes file, hides SQL error');
  echo "$n scan checks passed\n";
 } finally { array_map('unlink',$tmp); }
}
