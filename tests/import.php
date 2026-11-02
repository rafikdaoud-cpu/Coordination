<?php
require __DIR__.'/workflow.php';
require __DIR__.'/../lib/Vendor/SimpleXLSX.php';
require __DIR__.'/../lib/Controller/ImportApiController.php';
function workbook(array $rows): string {
 $file=tempnam(sys_get_temp_dir(),'coord-xlsx-'); $zip=new ZipArchive();$zip->open($file,ZipArchive::OVERWRITE);
 $xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
 foreach($rows as $i=>$row){$xml.='<row r="'.($i+1).'">';foreach($row as $j=>$v){$xml.='<c r="'.chr(65+$j).($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1,'UTF-8').'</t></is></c>';}$xml.='</row>';}
 $xml.='</sheetData></worksheet>';$zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();return $file;
}
$session->uid='manager';$request->p=[];
$import=new \OCA\Coordination\Controller\ImportApiController('coordination',$request,$service,$session,$groups,$access);
$file=workbook([['Nom du client','Numéro de téléphone','N° dossier'],['Client Test','0555000000','DOS-EXEMPLE']]);
try {
 $request->upload=['tmp_name'=>$file,'size'=>filesize($file),'error'=>0];
 $session->uid='coord';check($import->uploadXlsx()->status===403,'coordinateur cannot import files');
 $session->uid='manager';$result=$import->uploadXlsx();check($result->data['imported_count']===1,'actual XLSX file imported');
 $created=$service->created[0];check($created['phone']==='0555000000'&&$created['case_number']==='DOS-EXEMPLE','Numéro de téléphone is not mistaken for dossier number');
 check($created['client_name']==='Client Test','accented French headers parsed');
 $zip=new ZipArchive();$zip->open($file);$zip->addFromString('xl/sharedStrings.xml',str_repeat(' ',17*1024*1024));$zip->close();
 check(\OCA\Coordination\Vendor\SimpleXLSX::parse($file)===null,'oversized uncompressed ZIP member rejected before XML parsing');
} finally {unlink($file);}
$file=workbook([['Téléphone','Dossier'],['0555000000','DOS-EXEMPLE']]);
try { $request->upload=['tmp_name'=>$file,'size'=>filesize($file)];check($import->uploadXlsx()->status===400,'missing client-name header fails before importing wrong column'); } finally {unlink($file);}
$file=workbook([['Dossier','Nom du client'],['DOS-SANS-NOM','']]);
try { $request->upload=['tmp_name'=>$file,'size'=>filesize($file)];$result=$import->uploadXlsx();check($result->data['imported_count']===0&&count($result->data['errors'])===1,'blank client name does not turn dossier reference into a person'); } finally {unlink($file);}
$file=workbook([['Nom du client'],['Test']]);
try { $zip=new ZipArchive();$zip->open($file);$zip->addFromString('xl/worksheets/sheet1.xml','<worksheet><sheetData><row><c r="ZZZZZZ1"><v>1</v></c></row></sheetData></worksheet>');$zip->close();check(\OCA\Coordination\Vendor\SimpleXLSX::parse($file)===null,'extreme cell reference rejected without allocating an unbounded row'); } finally {unlink($file);}
echo "$n checks passed including real XLSX fixtures\n";
