<?php
// Import/export controller. Exports and templates are real .xlsx files (dt_send_csv is overridden in app/v6.php); imports read .xlsx and are transactional.
$dataToolsError=''; $dataToolsNotice=''; $dataToolsStats=null;
if(!can('data_tools','view')){http_response_code(403);exit('Import / Export access denied');}
$dataSets=[
 'profiles'=>['table'=>'profiles','perm'=>'profiles','date'=>'added_at','title'=>'Profiles','headers'=>['Sector','Tent','Tent Type','Tent Condition','ID','Name','Gender','Birth Date','Social Status','F. Member','Death Date','HH','HH ID','Hospitality','Partner Name','Motherhood','War Injury','Disability','Chronic Diseases','House Status','Region','Phone','Other Phone','Original Address','Nearest Place','Shelter Address','FC','FC No','Job Position','Note','A/R Date']],
 'beneficiaries'=>['table'=>'beneficiaries','perm'=>'beneficiaries','date'=>'delivery_date','title'=>'BNFs','headers'=>['Distribution Type','Sector','Tent','Beneficiary Name','ID','Category','Package','Amount','Delivery Date','Donor','Members','Phone','Note']],
 'finance'=>['table'=>'finance','perm'=>'finance','date'=>'transaction_date','title'=>'Finance','headers'=>['Flow','Date','Category','Item','Amount','Note']],
 'aids'=>['table'=>'aids','perm'=>'aids','date'=>'aid_date','title'=>'Aids','headers'=>['Date','Package','Quantity','Unit','Donor','Note']],
 'hr'=>['table'=>'employees','perm'=>'hr','date'=>'created_at','title'=>'HR','headers'=>['Sector','Job Position','Name','ID','Gender','Age','Social Status','Phone','Committee']],
 'storage'=>['table'=>'warehouse_items','perm'=>'storage','date'=>'item_date','title'=>'Storage','headers'=>['Item Code','Date','Category','Item','In Qty','Unit','Out Qty','Note']],
];
if (!function_exists('dt_norm')) { function dt_norm($v){$v=trim((string)$v);$v=preg_replace('/^\xEF\xBB\xBF/','',$v);return strtolower(preg_replace('/\s+/u',' ',$v));} }
if (!function_exists('dt_date')) { function dt_date($v){$v=trim((string)$v);if($v==='')return null;if(is_numeric($v)&&$v>20000&&$v<80000){$d=new DateTime('1899-12-30');$d->modify('+'.(int)$v.' days');return $d->format('Y-m-d');}foreach(['Y-m-d','Y-m-d H:i:s','d/m/Y','m/d/Y','d-m-Y'] as $f){$d=DateTime::createFromFormat($f,$v);if($d)return $d->format('Y-m-d');}$t=strtotime($v);return $t?date('Y-m-d',$t):null;} }
if (!function_exists('dt_csv_headers')) { function dt_csv_headers($headers){$out=[];foreach($headers as $h){$out[]=dt_norm($h);}return $out;} }
if (!function_exists('dt_send_csv')) { function dt_send_csv($filename,$headers,$rows){while(ob_get_level())ob_end_clean();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$filename.'"');$o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,$headers);foreach($rows as $r)fputcsv($o,$r);fclose($o);exit;} }
if (!function_exists('dt_allowed_rows')) { function dt_allowed_rows(string $module,string $sector):bool{return is_admin()||can($module,'view',$sector);} }

/* ---------- V5.2: tolerant CSV / XLSX reader ---------- */
if (!function_exists('dt_clean_cell')) { function dt_clean_cell($v){ $v=(string)$v; $v=preg_replace('/^\xEF\xBB\xBF/','',$v); $v=str_replace(["\xC2\xA0","\xE2\x80\x8B","\xE2\x80\x8C","\xE2\x80\x8D","\xE2\x80\x8E","\xE2\x80\x8F","\xE2\x80\xAA","\xE2\x80\xAB","\xE2\x80\xAC","\xEF\xBB\xBF"],' ',$v); return trim(preg_replace('/\s+/u',' ',$v)); } }
if (!function_exists('dt_to_utf8')) { function dt_to_utf8(string $raw):string{
    if(strncmp($raw,"\xFF\xFE",2)===0)return mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
    if(strncmp($raw,"\xFE\xFF",2)===0)return mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16BE');
    if(mb_check_encoding($raw,'UTF-8'))return $raw;
    return mb_convert_encoding($raw,'UTF-8','Windows-1256');
} }
/* ---- zip container reader (ZipArchive when present, otherwise pure PHP + gzinflate) ---- */
if (!function_exists('dt_zip_index')) { function dt_zip_index(string $bin):array{
    $n=strlen($bin);$e=strrpos($bin,"PK\x05\x06");if($e===false||$e+22>$n)throw new RuntimeException('ملف Excel تالف أو ليس بصيغة xlsx.');
    $h=unpack('vdisk/vcd/ventries/vtotal/Vsize/Voffset',substr($bin,$e+4,16));$p=(int)$h['offset'];$out=[];
    for($i=0;$i<(int)$h['total'];$i++){
        if($p+46>$n||substr($bin,$p,4)!=="PK\x01\x02")break;
        $c=unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen',substr($bin,$p+4,42));
        $name=substr($bin,$p+46,(int)$c['nlen']);$out[$name]=['m'=>(int)$c['method'],'cs'=>(int)$c['csize'],'us'=>(int)$c['usize'],'off'=>(int)unpack('V',substr($bin,$p+42,4))[1]];
        $p+=46+(int)$c['nlen']+(int)$c['elen']+(int)$c['clen'];
    }
    return $out;
} }
if (!function_exists('dt_zip_get')) { function dt_zip_get(string $bin,array $idx,string $name):?string{
    if(!isset($idx[$name]))return null;$f=$idx[$name];$o=$f['off'];if(substr($bin,$o,4)!=="PK\x03\x04")return null;
    $l=unpack('vnlen/velen',substr($bin,$o+26,4));$d=substr($bin,$o+30+(int)$l['nlen']+(int)$l['elen'],$f['cs']);
    if($f['m']===0)return $d;
    if($f['m']===8){if(!function_exists('gzinflate'))throw new RuntimeException('إضافة zlib غير متوفرة في PHP.');$r=@gzinflate($d);return $r===false?null:$r;}
    return null;
} }
if (!function_exists('dt_xml_text')) { function dt_xml_text(string $x):string{return html_entity_decode($x,ENT_QUOTES|ENT_XML1,'UTF-8');} }
if (!function_exists('dt_xml_attrs')) { function dt_xml_attrs(string $tag):array{$a=[];if(preg_match_all('/([A-Za-z_:][\w:.-]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/',$tag,$m,PREG_SET_ORDER))foreach($m as $x)$a[$x[1]]=$x[3]!==''?$x[3]:($x[4]??'');return $a;} }
if (!function_exists('dt_si_text')) { function dt_si_text(string $si):string{$si=preg_replace('#<rPh\b.*?</rPh>#s','',$si);$t='';if(preg_match_all('#<t\b[^>]*>(.*?)</t>#s',$si,$m))foreach($m[1] as $x)$t.=dt_xml_text($x);return $t;} }
if (!function_exists('dt_read_xlsx')) { function dt_read_xlsx(string $path,string $wantTitle):array{
    $bin=file_get_contents($path);if($bin===false||$bin==='')throw new RuntimeException('تعذّر فتح ملف Excel.');
    $idx=dt_zip_index($bin);
    $get=function(string $name)use($bin,$idx){return dt_zip_get($bin,$idx,$name);};
    $shared=[];$ss=$get('xl/sharedStrings.xml');
    if($ss!==null&&preg_match_all('#<si\b[^>]*>(.*?)</si>#s',$ss,$m))foreach($m[1] as $si)$shared[]=dt_si_text($si);
    $wb=(string)$get('xl/workbook.xml');$rels=(string)$get('xl/_rels/workbook.xml.rels');$map=[];
    if(preg_match_all('#<Relationship\b[^>]*>#',$rels,$m))foreach($m[0] as $tag){$a=dt_xml_attrs($tag);if(isset($a['Id'],$a['Target']))$map[$a['Id']]=$a['Target'];}
    $target=null;$first=null;
    if(preg_match_all('#<sheet\b[^>]*>#',$wb,$m))foreach($m[0] as $tag){$a=dt_xml_attrs($tag);$rid=$a['r:id']??null;$tg=$rid!==null?($map[$rid]??null):null;if(!$tg)continue;$tg=ltrim($tg,'/');if(strpos($tg,'xl/')!==0)$tg='xl/'.$tg;if($first===null)$first=$tg;if($target===null&&strcasecmp(trim(dt_xml_text($a['name']??'')),$wantTitle)===0)$target=$tg;}
    $target=$target?:($first?:'xl/worksheets/sheet1.xml');
    $xml=$get($target);if($xml===null)throw new RuntimeException('لم أجد الورقة داخل ملف Excel.');
    $rows=[];
    if(preg_match_all('#<row\b[^>]*?(?:/>|>(.*?)</row>)#s',$xml,$rm)){
        foreach($rm[1] as $rowXml){
            $cells=[];$max=-1;
            if($rowXml!==''&&preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s',$rowXml,$cm,PREG_SET_ORDER)){
                foreach($cm as $c){
                    $a=dt_xml_attrs($c[1]);$ref=$a['r']??'';$t=$a['t']??'';$body=$c[2]??'';$col=0;
                    if(preg_match('/^([A-Z]+)/',$ref,$mm)){foreach(str_split($mm[1]) as $ch)$col=$col*26+(ord($ch)-64);$col--;}else $col=$max+1;
                    $val='';
                    if($t==='inlineStr'){$val=dt_si_text($body);}
                    elseif(preg_match('#<v\b[^>]*>(.*?)</v>#s',$body,$vm)){$raw=dt_xml_text($vm[1]);
                        if($t==='s')$val=$shared[(int)$raw]??'';
                        elseif($t==='b')$val=$raw==='1'?'TRUE':'FALSE';
                        elseif($t==='e')$val='';
                        else $val=$raw;}
                    $cells[$col]=$val;if($col>$max)$max=$col;
                }
            }
            $line=[];for($i=0;$i<=$max;$i++)$line[]=$cells[$i]??'';$rows[]=$line;
        }
    }
    if(!$rows)throw new RuntimeException('ملف Excel لا يحتوي بيانات في الورقة المطلوبة.');
    return $rows;
} }
if (!function_exists('dt_read_rows')) { function dt_read_rows(string $path,string $sheetTitle):array{
    $head=file_get_contents($path,false,null,0,4);
    if(strncmp((string)$head,'PK',2)===0)return dt_read_xlsx($path,$sheetTitle);
    $raw=file_get_contents($path);if($raw===false||$raw==='')throw new RuntimeException('الملف فارغ');
    $raw=dt_to_utf8($raw);$raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
    $lines=preg_split('/\r\n|\n|\r/',$raw,40);$best=','; $bestN=-1;
    foreach([',',';',"\t",'|'] as $d){$n=0;foreach(array_slice($lines,0,15) as $ln)$n+=substr_count($ln,$d);if($n>$bestN){$bestN=$n;$best=$d;}}
    $fh=fopen('php://temp','r+');fwrite($fh,$raw);rewind($fh);$rows=[];
    while(($r=fgetcsv($fh,0,$best))!==false)$rows[]=$r;
    fclose($fh);return $rows;
} }
if (!function_exists('dt_flow_value')) { function dt_flow_value($v):?string{
    $n=mb_strtolower(dt_clean_cell($v));if($n==='')return null;
    $inc=['income','incomes','in','incoming','revenue','receipt','received','donation','داخل','الداخل','وارد','واردات','إيراد','ايراد','إيرادات','ايرادات','دخل','قبض'];
    $exp=['expenses','expense','expenditure','out','outgoing','payment','paid','spending','طالع','الطالع','صادر','صادرات','مصروف','مصروفات','مصاريف','صرف','خارج'];
    if(in_array($n,$inc,true))return 'Income';if(in_array($n,$exp,true))return 'Expenses';
    return null;
} }
if(isset($_GET['template']) && isset($dataSets[$_GET['template']])){$key=$_GET['template'];$d=$dataSets[$key];if(!can($d['perm'],'create')){http_response_code(403);exit('Import permission denied');}$sample=array_fill(0,count($d['headers']),'');dt_send_csv('Yarmouk_'.$key.'_template.xlsx',$d['headers'],[$sample]);}
if(isset($_GET['export_data']) && isset($dataSets[$_GET['export_data']])){$key=$_GET['export_data'];$d=$dataSets[$key];if(!can($d['perm'],'export')){http_response_code(403);exit('Export permission denied');}$from=dt_date($_GET['from']??'');$to=dt_date($_GET['to']??'');if(!$from||!$to||$from>$to){http_response_code(400);exit('Valid From and To dates are required');}$table=$d['table'];$date=$d['date'];$where=" WHERE DATE($date) BETWEEN ? AND ?";$args=[$from,$to];if(in_array($key,['profiles','beneficiaries','hr'],true)&&!is_admin()){$allowed=allowed_module_sectors($d['perm'],'view');if(!$allowed){$where.=' AND 1=0';}else{$sectorCol=$key==='beneficiaries'?'allocation_sector':'sector';$where.=' AND '.$sectorCol.' IN ('.implode(',',array_fill(0,count($allowed),'?')).')';$args=array_merge($args,$allowed);}}if($key==='profiles')$where.=" AND sector<>'R' AND archived_at IS NULL";
 $cols=['profiles'=>['sector','tent','tent_type','tent_condition','national_id','full_name','gender','birth_date','social_status','family_member','death_date','is_household_head','hh_id','hospitality','partner_name','motherhood','war_injury','disability','chronic_diseases','house_status','region','phone','other_phone','original_address','nearest_place','shelter_address','fc','fc_no','job_position','note','added_at'],'beneficiaries'=>['distribution_type','sector','allocation_sector','tent','beneficiary_name','national_id','category','package','amount','delivery_date','donor','members','phone','note'],'finance'=>['flow','transaction_date','category','item','amount','note'],'aids'=>['aid_date','package','quantity','unit','donor','note'],'hr'=>['sector','job_position','full_name','national_id','gender','age','social_status','phone','committee_name','created_at'],'storage'=>['item_code','item_date','category','item_name','in_qty','unit','out_qty','note']][$key];
 $hdr=$d['headers'];$orderCol=$date;
 if($key==='beneficiaries'){$i=array_search('allocation_sector',$cols,true);if($i!==false)array_splice($cols,$i,1);}
 if($key==='hr'){$i=array_search('created_at',$cols,true);if($i!==false)array_splice($cols,$i,1);}
 $hdr=array_slice($hdr,0,count($cols));$pairs=array_combine($cols,$hdr);
 if($key==='profiles'&&!is_admin()&&!can('profiles','view_sensitive'))$pairs=array_diff_key($pairs,array_flip(['national_id','phone','other_phone','fc_no','war_injury','disability','chronic_diseases']));
 if($key==='beneficiaries'&&!is_admin()&&!can('beneficiaries','view_sensitive'))$pairs=array_diff_key($pairs,array_flip(['national_id','phone']));
 $cols=array_keys($pairs);$hdr=array_values($pairs);
 $orderBy=$key==='profiles'?profile_order_sql(''):($key==='hr'?org_rank_sql('job_position').', full_name, id':"$date,id");
 $q=db()->prepare('SELECT '.implode(',',$cols)." FROM $table$where ORDER BY $orderBy");$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_NUM);
 if($key==='profiles'){$hi=array_search('is_household_head',$cols,true);if($hi!==false)foreach($rows as &$rw){$rw[$hi]=((int)$rw[$hi]===1)?'Yes':'No';}unset($rw);}
 audit('export',$key,null,"XLSX $from to $to");dt_send_csv('Yarmouk_'.$key.'_'.$from.'_to_'.$to.'.xlsx',$hdr,$rows);}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='import_csv'){
 try{check_csrf();$key=(string)($_POST['dataset']??'');if(!isset($dataSets[$key]))throw new RuntimeException('نوع البيانات غير معروف');$d=$dataSets[$key];if(!can($d['perm'],'create'))throw new RuntimeException('لا تملك صلاحية الاستيراد لهذا القسم');if(empty($_FILES['csv_file']['tmp_name'])||!is_uploaded_file($_FILES['csv_file']['tmp_name']))throw new RuntimeException('اختر ملف CSV أولاً');if($_FILES['csv_file']['size']>25*1024*1024)throw new RuntimeException('الحد الأقصى لحجم الملف 25MB');$allRows=dt_read_rows($_FILES['csv_file']['tmp_name'],$d['title']);if(!$allRows)throw new RuntimeException('الملف فارغ');
 $aliases=['id'=>'id','national id'=>'id','f. member'=>'f. member','hh id'=>'hhid','hhid'=>'hhid','beneficiary\'s name'=>'beneficiary name','beneficiary name'=>'beneficiary name','transaction date'=>'date','aid date'=>'date','item date'=>'date','full name'=>'name','name - 0'=>'name','fc no'=>'fc no','a/r date'=>'a/r date','add / remove date'=>'a/r date','in qty'=>'in qty','out qty'=>'out qty',
  'type'=>'flow','transaction type'=>'flow','direction'=>'flow','in/out'=>'flow','income/expenses'=>'flow','income / expenses'=>'flow','الحركة'=>'flow','نوع الحركة'=>'flow','الاتجاه'=>'flow','داخل/طالع'=>'flow','التاريخ'=>'date','التصنيف'=>'category','الفئة'=>'category','البند'=>'item','المبلغ'=>'amount','القيمة'=>'amount','ملاحظات'=>'note','ملاحظة'=>'note'];
 $required=['profiles'=>['sector','id','name','hhid'],'beneficiaries'=>['sector','id','category','package','delivery date'],'finance'=>['flow','date','category','item','amount'],'aids'=>['date','package','quantity','unit'],'hr'=>['name','job position'],'storage'=>['date','category','item','unit']][$key];
 $defaultFlow=null;if($key==='finance'){$df=(string)($_POST['default_flow']??'');if(in_array($df,['Income','Expenses'],true))$defaultFlow=$df;}
 $buildMap=function(array $cells)use($aliases){$m=[];foreach($cells as $i=>$h){$h=dt_norm(dt_clean_cell($h));if($h!==''&&!isset($m[$h]))$m[$h]=$i;}foreach($aliases as $a=>$b)if(isset($m[dt_norm($a)])&&!isset($m[dt_norm($b)]))$m[dt_norm($b)]=$m[dt_norm($a)];return $m;};
 $headerIdx=0;$map=$buildMap($allRows[0]);$bestScore=-1;
 foreach(array_slice($allRows,0,20,true) as $ri=>$cells){$m=$buildMap($cells);$score=0;foreach($required as $r)if(isset($m[dt_norm($r)]))$score++;if($score>$bestScore){$bestScore=$score;$headerIdx=$ri;$map=$m;}}
 $dataRows=array_slice($allRows,$headerIdx+1);
 $seen=implode(' | ',array_filter(array_map('dt_clean_cell',$allRows[$headerIdx]),fn($x)=>$x!==''));
 foreach($required as $r){if($key==='finance'&&$r==='flow'&&$defaultFlow!==null)continue;if(!array_key_exists(dt_norm($r),$map)){$hint=($key==='finance'&&$r==='flow')?' — إن كان ملفك لا يحتوي عمود Flow (مثلاً ملف للداخل وآخر للطالع) اختر «نوع الحركة» من القائمة أعلى النموذج.':'';throw new RuntimeException('عمود مطلوب غير موجود في الملف: '.$r.$hint.' | الأعمدة التي قرأها النظام: '.($seen!==''?$seen:'(لا شيء، تأكد أن الصف الأول يحتوي أسماء الأعمدة)'));}}
 $inserted=0;$skipped=0;$errors=[];$rowNo=1;$pdo=db();$pdo->beginTransaction();
 foreach($dataRows as $ri=>$row){$rowNo=$headerIdx+2+$ri;if(count(array_filter($row,fn($x)=>trim((string)$x)!==''))===0)continue;$get=function($name)use($map,$row){$i=$map[dt_norm($name)]??null;return $i===null?null:dt_clean_cell($row[$i]??'');};
 try{$v=[];
 // Ignore section labels / formatted blank rows commonly found in the source workbook.
 if($key==='profiles' && !(($get('sector')??'')!=='')){$skipped++;continue;}
 if($key==='beneficiaries' && !(($get('sector')??'')!=='')){$skipped++;continue;}
 if($key==='finance' && !(($get('flow')??'')!=='' || ($get('date')??'')!=='')){$skipped++;continue;}
 if($key==='aids' && !(($get('date')??'')!=='' || ($get('package')??'')!=='')){$skipped++;continue;}
 if($key==='hr' && !(($get('name')??'')!=='' || ($get('job position')??'')!=='')){$skipped++;continue;}
 if($key==='storage' && !(($get('date')??'')!=='' || ($get('item')??'')!=='')){$skipped++;continue;}
 if($key==='profiles'){$sector=strtoupper($get('sector')??'');if(!in_array($sector,['A','B','C','D','E','S','S1','R','AS','BS','CS','DS'],true))throw new RuntimeException('Sector غير صحيح');if($sector==='R'&&!is_admin())throw new RuntimeException('استيراد سجلات R للمدير فقط');if(!can('profiles','create',$sector))throw new RuntimeException('لا تملك صلاحية هذا القطاع');$nid=$get('id');$name=$get('name');$hh=$get('hhid');if(!$nid||!$name||!$hh)throw new RuntimeException('ID وName وHHID مطلوبة');// Keep duplicate identities as separate records by design.
$v=['sector'=>$sector,'tent'=>$get('tent'),'tent_type'=>$get('tent type'),'tent_condition'=>$get('tent condition'),'national_id'=>$nid,'full_name'=>$name,'gender'=>$get('gender'),'birth_date'=>dt_date($get('birth date')),'social_status'=>$get('social status'),'family_member'=>$get('f. member'),'death_date'=>dt_date($get('death date')),'is_household_head'=>in_array(strtoupper($get('hh')??''),['YES','Y','1','TRUE'],true)?1:0,'hh_id'=>$hh,'hospitality'=>$get('hospitality'),'partner_name'=>$get('partner name'),'motherhood'=>$get('motherhood'),'war_injury'=>$get('war injury'),'disability'=>$get('disability'),'chronic_diseases'=>$get('chronic diseases'),'house_status'=>$get('house status'),'region'=>$get('region'),'phone'=>$get('phone'),'other_phone'=>$get('other phone'),'original_address'=>$get('original address'),'nearest_place'=>$get('nearest place'),'shelter_address'=>$get('shelter address'),'fc'=>$get('fc'),'fc_no'=>$get('fc no'),'job_position'=>$get('job position'),'note'=>$get('note'),'added_at'=>dt_date($get('a/r date'))];if($sector==='R'){$v['archived_at']=date('Y-m-d H:i:s');$v['archived_from_sector']=null;}$cols=array_keys($v);$st=$pdo->prepare('INSERT INTO profiles('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$st->execute(array_values($v));
 }elseif($key==='beneficiaries'){$dtype=strtoupper($get('distribution type')??'NORMAL')==='FULL'?'FULL':'NORMAL';$sector=strtoupper($get('sector')??'A');if($dtype==='FULL'){if(!is_admin())throw new RuntimeException('استيراد توزيعات FULL للمدير فقط');$sector='A';$allocSector='A';}else{$allocSector=in_array($sector,['S1','AS','BS','CS','DS'],true)?'S':$sector;if(!$sector||!can('beneficiaries','create',$allocSector))throw new RuntimeException('Sector غير مسموح');}$cat=ucfirst(strtolower($get('category')??''));if(!in_array($cat,['Food','Financial','Logistics'],true))throw new RuntimeException('Category يجب أن تكون Food أو Financial أو Logistics');$date=dt_date($get('delivery date'));if(!$date)throw new RuntimeException('Delivery Date غير صحيح');$v=['distribution_type'=>$dtype,'sector'=>$sector,'allocation_sector'=>$allocSector,'tent'=>$dtype==='FULL'?null:$get('tent'),'beneficiary_name'=>$dtype==='FULL'?'FULL – All Camp':$get('beneficiary name'),'national_id'=>$dtype==='FULL'?null:$get('id'),'category'=>$cat,'package'=>$get('package'),'amount'=>is_numeric($get('amount'))?$get('amount'):null,'delivery_date'=>$date,'donor'=>$get('donor'),'members'=>$dtype==='FULL'?null:(is_numeric($get('members'))?(int)$get('members'):null),'phone'=>$dtype==='FULL'?null:$get('phone'),'note'=>$get('note'),'created_by'=>user()['id']];if(!$v['package']||($dtype==='NORMAL'&&!$v['beneficiary_name']))throw new RuntimeException('اسم المستفيد والطرد مطلوبان للتوزيع الفردي'); // FULL rows are documentation-only.
$cols=array_keys($v);$st=$pdo->prepare('INSERT INTO beneficiaries('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$st->execute(array_values($v));
 }elseif($key==='finance'){$flow=dt_flow_value($get('flow'));if($flow===null)$flow=$defaultFlow;if($flow===null)throw new RuntimeException('قيمة Flow غير مفهومة ('.($get('flow')??'').'): استخدم Income أو Expenses (أو داخل / طالع)');$date=dt_date($get('date'));$amount=str_replace([',','₪','$','JOD','NIS',' '],'',(string)$get('amount'));if(!$date||!is_numeric($amount))throw new RuntimeException('التاريخ والمبلغ مطلوبان');$v=['flow'=>$flow,'transaction_date'=>$date,'category'=>$get('category'),'item'=>$get('item'),'amount'=>$amount,'note'=>$get('note'),'created_by'=>user()['id']];// Duplicate rows are imported; no content-based de-duplication.
$cols=array_keys($v);$st=$pdo->prepare('INSERT INTO finance('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$st->execute(array_values($v));
 }elseif($key==='aids'){$date=dt_date($get('date'));if(!$date||!is_numeric($get('quantity')))throw new RuntimeException('التاريخ والكمية مطلوبان');$v=['aid_date'=>$date,'package'=>$get('package'),'quantity'=>$get('quantity'),'unit'=>$get('unit'),'donor'=>$get('donor'),'note'=>$get('note')];// Duplicate rows are imported; no content-based de-duplication.
$cols=array_keys($v);$st=$pdo->prepare('INSERT INTO aids('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$st->execute(array_values($v));
 }elseif($key==='hr'){$sector=strtoupper($get('sector')??'');if($sector&&!can('hr','create',$sector))throw new RuntimeException('لا تملك صلاحية هذا القطاع');$v=['sector'=>$sector?:null,'job_position'=>$get('job position'),'full_name'=>$get('name'),'national_id'=>$get('id'),'gender'=>$get('gender'),'age'=>is_numeric($get('age'))?(int)$get('age'):null,'social_status'=>$get('social status'),'phone'=>$get('phone'),'committee_name'=>$get('committee')];$cols=array_keys($v);$st=$pdo->prepare('INSERT INTO employees('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$st->execute(array_values($v));
 }else{$date=dt_date($get('date'));if(!$date||(($get('in qty')??'')!==''&&!is_numeric($get('in qty')))||(($get('out qty')??'')!==''&&!is_numeric($get('out qty'))))throw new RuntimeException('التاريخ والكميات غير صحيحة');$v=['item_code'=>$get('item code')?:null,'item_date'=>$date,'category'=>$get('category'),'item_name'=>$get('item'),'in_qty'=>$get('in qty')?:0,'unit'=>$get('unit'),'out_qty'=>$get('out qty')?:0,'note'=>$get('note')];// Duplicate rows are imported; no content-based de-duplication.
$cols=array_keys($v);$st=$pdo->prepare('INSERT INTO warehouse_items('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$st->execute(array_values($v));}
 $inserted++;
 }catch(Throwable $e){$errors[]='السطر '.$rowNo.': '.($e instanceof PDOException?'قيمة لا تناسب قاعدة البيانات (نص طويل جداً أو نوع غير صحيح) — رمز '.($e->errorInfo[1]??$e->getCode()):$e->getMessage());if(count($errors)>=20)break;}}
 if($errors){$pdo->rollBack();$dataToolsError='تم إلغاء الاستيراد بالكامل لحماية البيانات. '.implode(' | ',$errors);}else{$pdo->commit();audit('import',$key,null,"Inserted $inserted; blank rows skipped $skipped");$dataToolsNotice="اكتمل الاستيراد بنجاح. تمت إضافة $inserted سجل، وتم تجاهل $skipped صفاً فارغاً أو غير صالح من نوع البيانات.";$dataToolsStats=['inserted'=>$inserted,'skipped'=>$skipped];}
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$dataToolsError=safe_error($e);}}
?>
