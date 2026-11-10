<?php
declare(strict_types=1);
function cfg():array{static $c=null;return $c??=require __DIR__.'/../config.php';}
function db():PDO{static $p=null;if($p)return $p;$c=cfg();$dsn="mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>5,PDO::MYSQL_ATTR_INIT_COMMAND=>'SET SESSION wait_timeout=20, interactive_timeout=20'];$last=null;foreach([0,300000,700000,1500000,2500000] as $wait){if($wait)usleep($wait);try{return $p=new PDO($dsn,$c['db_user'],$c['db_pass'],$opt);}catch(PDOException $e){$last=$e;$code=(int)($e->errorInfo[1]??$e->getCode());if(!in_array($code,[2002,2003,2006,2013,1040,1226],true))throw $e;}}throw $last;}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function t(string $k):string{global $L;return $L[$k]??$k;}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function check_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??''))throw new RuntimeException('Invalid security token. Refresh the page.');}
function user():?array{return $_SESSION['user']??null;}
function require_login():void{if(!user()){header('Location: login.php');exit;}}
function is_admin():bool{return (bool)(user()['is_admin']??false);}
function can(string $module,string $action,string $sector=''):bool{if(!user())return false;if(is_admin())return true;$q=db()->prepare('SELECT sectors FROM user_permissions WHERE user_id=? AND module=? AND action=?');$q->execute([user()['id'],$module,$action]);$s=$q->fetchColumn();if($s===false)return false;if($sector==='')return true;if($s==='*')return true;return in_array($sector,array_map('trim',explode(',',$s)),true);}
function audit(string $action,string $module,?int $id=null,string $details=''):void{$q=db()->prepare('INSERT INTO audit_logs(user_id,action,module,record_id,details,ip_address) VALUES(?,?,?,?,?,?)');$q->execute([user()['id']??null,$action,$module,$id,$details,$_SERVER['REMOTE_ADDR']??'']);}
function redirect(string $p):never{header('Location: ?page='.rawurlencode($p));exit;}
function pv(string $k,string $d=''):string{return trim((string)($_POST[$k]??$d));}
function age_years(?string $d):string{if(!$d)return '';try{return (string)(new DateTime($d))->diff(new DateTime())->y;}catch(Throwable $e){return '';}}
function family_size(string $hh):int{$q=db()->prepare("SELECT COUNT(*) FROM profiles WHERE hh_id=? AND sector<>'R' AND archived_at IS NULL AND (social_status IS NULL OR social_status NOT IN ('Shahid','Dead','Missing','Detained','Absent'))");$q->execute([$hh]);return (int)$q->fetchColumn();}
function sectors():array{return ['A','B','C','D','E','S','S1','R','AS','BS','CS','DS'];}
function modules():array{return ['profiles'=>'Profiles','statistics'=>'Statistics','family'=>'Family Members','beneficiaries'=>'BNFs','finance'=>'Finance','aids'=>'Aids','candidates'=>'Sector Candidates','hr'=>'HR','storage'=>'Storge','reports'=>'Export Reports','users'=>'Users','audit'=>'Audit Log','archive'=>'Archived Records','data_tools'=>'Import / Export','tickets'=>'Complaints & Suggestions','requests'=>'Institution Requests','allocations'=>'Sector Allocations'];}
function fields():array{$sel=fn($n,$l,$o)=>['name'=>$n,'label'=>$l,'type'=>'select','options'=>$o];$f=fn($n,$l,$t='text')=>['name'=>$n,'label'=>$l,'type'=>$t];return [
'profiles'=>['table'=>'profiles','perm'=>'profiles','fields'=>[$sel('sector','Sector',array_combine(sectors(),sectors())),$f('tent','Tent'),$sel('tent_type','Tent Type',['Building Part'=>'Building Part','Collected'=>'Collected','Original'=>'Original']),$sel('tent_condition','Tent Condition',['Worn out'=>'Worn out','Fair'=>'Fair','Good'=>'Good']),$f('national_id','ID'),$f('full_name','Name'),$sel('gender','Gender',['Male'=>'Male','Female'=>'Female']),$f('birth_date','Birth Date','date'),$sel('social_status','Social Status',array_combine(['Married','Single','Shahid','Dead','Missing','Widow','Divorced','Detained','Absent','Abroad','Abandoned'],['Married','Single','Shahid','Dead','Missing','Widow','Divorced','Detained','Absent','Abroad','Abandoned'])),$sel('family_member','F. Member',['Husband'=>'Husband','Wife'=>'Wife','Daughter'=>'Daughter','Son'=>'Son']),$f('death_date','Death Date','date'),$sel('is_household_head','HH',['1'=>'YES','0'=>'NO']),$f('hh_id','HHID'),$sel('hospitality','Hospitality',['Host'=>'Host','Guest'=>'Guest']),$f('partner_name','Partner Name'),$sel('motherhood','Motherhood',[''=>'','Breast feeder'=>'Breast feeder','Pregnant'=>'Pregnant','Pregnant+Breast feeder'=>'Pregnant+Breast feeder']),$f('war_injury','War Injury','textarea'),$f('disability','Disability','textarea'),$f('chronic_diseases','Chronic Diseases','textarea'),$sel('house_status','House Status',['Full Damage'=>'Full Damage','Healthy'=>'Healthy','Partial Damage'=>'Partial Damage','No Place'=>'No Place']),$f('region','Region'),$f('phone','Phone'),$f('other_phone','Other Phone'),$f('original_address','Original Address','textarea'),$f('nearest_place','Nearest Place'),$f('shelter_address','Shelter Address'),$f('fc','FC'),$f('fc_no','FC No'),$f('job_position','Job Position'),$f('note','Note','textarea'),$f('added_at','A/R Date','date')]],
'beneficiaries'=>['table'=>'beneficiaries','perm'=>'beneficiaries','fields'=>[$sel('distribution_type','Distribution Type',['NORMAL'=>'Individual / Household','FULL'=>'FULL – All Camp (Admin only)']),$sel('sector','Sector',array_combine(sectors(),sectors())),$sel('allocation_sector','Count assistance under',['A'=>'A','B'=>'B','C'=>'C','D'=>'D','S'=>'S']),$f('tent','Tent'),$f('beneficiary_name',"Beneficiary's Name"),$f('national_id','ID'),$sel('category','Category',['Logistics'=>'Logistics','Financial'=>'Financial','Food'=>'Food']),$f('package','Package'),$f('amount','Amount','number'),$f('delivery_date','Delivery Date','date'),$f('donor','Donor'),$f('members','Members','number'),$f('phone','Phone'),$f('note','Note','textarea')]],
'finance'=>['table'=>'finance','perm'=>'finance','fields'=>[$sel('flow','Flow',['Expenses'=>'Expenses','Income'=>'Income']),$f('transaction_date','Date','date'),$f('category','Category'),$f('item','Item'),$f('amount','Amount','number'),$f('note','Note','textarea')]],
'aids'=>['table'=>'aids','perm'=>'aids','fields'=>[$f('aid_date','Date','date'),$f('package','Package'),$f('quantity','Quantity','number'),$f('unit','Unit'),$f('donor','Donor'),$f('note','Note','textarea')]],
'hr'=>['table'=>'employees','perm'=>'hr','fields'=>[$sel('sector','Sector',[''=>'Not assigned']+array_combine(sectors(),sectors())),$f('job_position','Job Position'),$f('full_name','Name'),$f('national_id','ID'),$f('gender','Gender'),$f('age','Age','number'),$f('social_status','Social Status'),$f('phone','Phone'),$f('committee_name','Committee')]],
'storage'=>['table'=>'warehouse_items','perm'=>'storage','fields'=>[$f('item_code','Item Code'),$f('item_date','Date','date'),$f('category','Category'),$f('item_name','Item'),$f('in_qty','In Qty','number'),$f('unit','Unit'),$f('out_qty','Out Qty','number'),$f('note','Note','textarea')]]];}
function allowed_profile_sectors(string $action='view'):array{$out=[];foreach(sectors() as $s)if(can('profiles',$action,$s))$out[]=$s;return $out;}
function allowed_module_sectors(string $module,string $action='view'):array{$out=[];foreach(sectors() as $s)if(can($module,$action,$s))$out[]=$s;return $out;}
function profile_sector_sql(string $alias=''):array{$s=allowed_profile_sectors();if(is_admin())return ['',[]];if(!$s)return [' AND 1=0',[]];$col=$alias?' '.$alias.'.sector':' sector';return [' AND'.$col.' IN ('.implode(',',array_fill(0,count($s),'?')).')',$s];}
function module_records(string $page, int|string $limit=300):array{
 $d=fields()[$page];$table=$d['table'];$args=[];$where='';$allowedSort=['id','sector','tent','national_id','full_name','birth_date','social_status','added_at','job_position','age','created_at'];
 if($page==='profiles'){
  $where=" WHERE archived_at IS NULL AND sector<>'R'";[$x,$a]=profile_sector_sql();$where.=$x;$args=$a;
  $sf=trim((string)($_GET['sector_filter']??''));if($sf!==''&&in_array($sf,sectors(),true)){$where.=' AND sector=?';$args[]=$sf;}
  $hf=(string)($_GET['head_filter']??'all');if($hf==='yes')$where.=' AND is_household_head=1';elseif($hf==='no')$where.=' AND is_household_head=0';
  $q=trim((string)($_GET['q']??''));if($q!==''){$where.=' AND (full_name LIKE ? OR national_id LIKE ? OR hh_id LIKE ? OR tent LIKE ?)';array_push($args,"%$q%","%$q%","%$q%","%$q%");}
 }elseif($page==='beneficiaries'){
  $s=allowed_module_sectors('beneficiaries');if(!is_admin()){$where=$s?' WHERE sector IN ('.implode(',',array_fill(0,count($s),'?')).')':' WHERE 1=0';$args=$s;}
  $q=trim((string)($_GET['q']??''));if($q!==''){$where.=($where?' AND ':' WHERE ').'(beneficiary_name LIKE ? OR national_id LIKE ? OR package LIKE ? OR tent LIKE ?)';array_push($args,"%$q%","%$q%","%$q%","%$q%");}
  $cat=trim((string)($_GET['category_filter']??''));if($cat!==''){$where.=($where?' AND ':' WHERE ').'category=?';$args[]=$cat;}
  $dt=trim((string)($_GET['distribution_filter']??''));if(in_array($dt,['NORMAL','FULL'],true)){$where.=($where?' AND ':' WHERE ').'distribution_type=?';$args[]=$dt;}
 }elseif($page==='hr'){
  $s=allowed_module_sectors('hr');if(!is_admin()){$where=$s?' WHERE (sector IN ('.implode(',',array_fill(0,count($s),'?')).') OR sector IS NULL OR sector=\'\')':' WHERE 1=0';$args=$s;}
  $q=trim((string)($_GET['q']??''));if($q!==''){$where.=($where?' AND ':' WHERE ').'(full_name LIKE ? OR national_id LIKE ? OR job_position LIKE ? OR committee_name LIKE ?)';array_push($args,"%$q%","%$q%","%$q%","%$q%");}
 }
 $sort=(string)($_GET['sort']??'id');if(!in_array($sort,$allowedSort,true))$sort='id';$dir=strtolower((string)($_GET['dir']??'desc'))==='asc'?'ASC':'DESC';
 $sql="SELECT * FROM {$table}{$where} ORDER BY {$sort} {$dir}, id DESC";if($limit!=='all'){$n=max(1,min(10000,(int)$limit));$sql.=' LIMIT '.$n;}
 $q=db()->prepare($sql);$q->execute($args);return $q->fetchAll();
}
require_once __DIR__.'/v5.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/filters.php';
require_once __DIR__.'/charts.php';
