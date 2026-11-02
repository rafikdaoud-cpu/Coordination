<?php
namespace OCP { interface IGroupManager {} interface IUserManager {} interface IUserSession {} interface IDBConnection {} }
namespace OCA\Coordination\Db {
 class Dossier { public array $v=[];public function __call($m,$a){$k=lcfirst(substr($m,3));if(str_starts_with($m,'set'))$this->v[$k]=$a[0];else return $this->v[$k]??null;} }
 class DossierMapper {
  public function __construct(private $db){}
  public function insert($d){$q=$this->db->pdo->prepare('INSERT INTO coord_dossiers(case_number,client_name)VALUES(?,?)');$q->execute([$d->getCaseNumber(),$d->getClientName()]);$d->setId((int)$this->db->pdo->lastInsertId());return $d;}
 }
 class DossierChecklistMapper {} class DossierHistoryMapper {}
 class DossierConfigMapper {public function getValue($key){return $key==='document_templates'?json_encode([['code'=>'cni','label_fr'=>'CNI','label_ar'=>'هوية','mandatory'=>true],['code'=>'res','label_fr'=>'Résidence','label_ar'=>'إقامة','mandatory'=>true]]):null;}}
}
namespace {
 require __DIR__.'/../lib/Service/DossierService.php';
 class DB implements \OCP\IDBConnection {
  public $pdo;public int $queries=0;
  public function __construct(){$this->pdo=new \PDO('sqlite::memory:');$this->pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$this->pdo->exec('CREATE TABLE coord_dossiers(id INTEGER PRIMARY KEY AUTOINCREMENT,case_number TEXT,client_name TEXT);CREATE TABLE coord_checklists(dossier_id INTEGER,document_code TEXT,document_label_fr TEXT,document_label_ar TEXT,is_mandatory INTEGER,coordinator_checked INTEGER,backoffice_checked INTEGER);CREATE TABLE coord_histories(dossier_id INTEGER,user_id TEXT,action TEXT,details TEXT,created_at INTEGER)');}
  public function getQueryBuilder(){$this->queries++;return new Q($this->pdo);}
  public function beginTransaction(){$this->pdo->beginTransaction();}public function commit(){$this->pdo->commit();}public function rollBack(){$this->pdo->rollBack();}
 }
 class Q {
  private $kind='',$table='',$cols='*',$where='';private array $v=[],$p=[];
  public function __construct(private $db){}public function expr(){return $this;}public function eq($a,$b){return "$a=$b";}
  public function createNamedParameter($v){$key=':p'.count($this->p);$this->p[$key]=$v;return $key;}
  public function select($c){$this->kind='select';$this->cols=$c;return $this;}public function from($t){$this->table=$t;return $this;}public function where($w){$this->where=$w;return $this;}public function setMaxResults($n){return $this;}
  public function insert($t){$this->kind='insert';$this->table=$t;return $this;}public function values($v){$this->v=$v;return $this;}
  private function run(){$sql=$this->kind==='insert'?'INSERT INTO '.$this->table.'('.implode(',',array_keys($this->v)).')VALUES('.implode(',',$this->v).')':'SELECT '.$this->cols.' FROM '.$this->table.' WHERE '.$this->where;$q=$this->db->prepare($sql);$q->execute($this->p);return $q;}
  public function executeQuery(){return $this->run();}public function executeStatement(){return $this->run()->rowCount();}
 }
 $db=new DB();$groups=new class implements \OCP\IGroupManager{};$users=new class implements \OCP\IUserManager{};$session=new class implements \OCP\IUserSession{};
 $service=new \OCA\Coordination\Service\DossierService(new \OCA\Coordination\Db\DossierMapper($db),new \OCA\Coordination\Db\DossierChecklistMapper(),new \OCA\Coordination\Db\DossierHistoryMapper(),new \OCA\Coordination\Db\DossierConfigMapper(),$groups,$users,$session,$db);
 $n=0;function verify($ok,$label){global $n;if(!$ok)throw new \RuntimeException($label);$n++;echo "PASS $label\n";}
 verify($db->queries===0,'service construction has no schema or group side effects');
 $d=$service->createDossier(['client_name'=>'Test','case_number'=>'DOS-1'],'manager');
 verify($db->pdo->query('SELECT count(*) FROM coord_checklists')->fetchColumn()==2,'creation persists all required pieces');
 verify($db->pdo->query('SELECT count(*) FROM coord_histories')->fetchColumn()==1,'creation persists audit record');
 $d=$service->createDossier(['client_name'=>'Test sans référence','case_number'=>''],'manager');verify(str_starts_with($d->getCaseNumber(),'DOS-')&&strlen($d->getCaseNumber())>8,'empty reference is generated safely');
 try{$service->createDossier(['client_name'=>'Autre','case_number'=>'DOS-1'],'manager');throw new \RuntimeException('Expected duplicate');}catch(\DomainException $e){}
 verify($db->pdo->query('SELECT count(*) FROM coord_dossiers')->fetchColumn()==2,'duplicate explicit reference rejected');
 $db->pdo->exec("CREATE TRIGGER fail_piece BEFORE INSERT ON coord_checklists BEGIN SELECT RAISE(ABORT,'piece failed');END");
 try{$service->createDossier(['client_name'=>'Rollback','case_number'=>'DOS-3'],'manager');throw new \RuntimeException('Expected failure');}catch(\PDOException $e){}
 verify($db->pdo->query('SELECT count(*) FROM coord_dossiers')->fetchColumn()==2&&$db->pdo->query('SELECT count(*) FROM coord_histories')->fetchColumn()==2,'piece failure rolls back entire creation, including dossier');
 echo "$n atomic creation checks passed\n";
}
