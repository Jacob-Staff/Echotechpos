<?php
declare(strict_types=1);
/** EchoTech POS - Admin ZRA item registration endpoint. */
if(session_status()===PHP_SESSION_NONE)session_start();
foreach([__DIR__.'/../../includes/auth.php',__DIR__.'/../../includes/auth_helpers.php',__DIR__.'/../../auth.php'] as $f){if(is_file($f)){require_once $f;break;}}
foreach([__DIR__.'/../../includes/conn.php',__DIR__.'/../../config.php',__DIR__.'/../../db.php'] as $f){if(is_file($f)){require_once $f;if(isset($conn)&&$conn instanceof mysqli)break;}}
if(function_exists('require_login'))require_login();
if((string)($_SESSION['role']??'')!=='Admin'){http_response_code(403);exit('Access denied.');}
if(!isset($conn)||!($conn instanceof mysqli)){http_response_code(500);exit('Database connection unavailable.');}
require_once __DIR__.'/zra_client.php';
$pharmacyId=(int)($_SESSION['pharmacy_id']??0);$userId=(int)($_SESSION['user_id']??0);
if($pharmacyId<=0)exit('Pharmacy session is missing.');
function zra_items_redirect(string $m='',string $e=''):never{header('Location: ../zra.php?'.http_build_query($e?['err'=>$e]:['ok'=>$m]));exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')zra_items_redirect('','POST required.');
$csrf=(string)($_POST['csrf']??'');if(empty($_SESSION['zra_csrf'])||!hash_equals($_SESSION['zra_csrf'],$csrf))exit('Invalid security token.');
$productId=(int)($_POST['product_id']??0);$branchId=(int)($_POST['branch_id']??0);$bhf=trim((string)($_POST['bhf_id']??''));$itemCd=trim((string)($_POST['item_cd']??''));$itemClsCd=trim((string)($_POST['item_cls_cd']??''));$itemTyCd=trim((string)($_POST['item_ty_cd']??'2'));$orgn=trim((string)($_POST['orgn_nat_cd']??'ZM'));$pkg=trim((string)($_POST['pkg_unit_cd']??'EA'));$qtyUnit=trim((string)($_POST['qty_unit_cd']??'EA'));$tax=trim((string)($_POST['tax_ty_cd']??'A'));
if($productId<=0||$branchId<=0||!preg_match('/^.{3}$/',$bhf)||$itemCd===''||$itemClsCd==='')zra_items_redirect('','Product, branch, bhfId, ZRA item code and classification code are required.');
$settings=[];$s=$conn->prepare('SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1');if($s){$s->bind_param('i',$pharmacyId);$s->execute();$settings=$s->get_result()->fetch_assoc()?:[];$s->close();}$tpin=preg_replace('/\D+/','',(string)($settings['tpin']??''));if(strlen($tpin)!==10)zra_items_redirect('','Enter the 10-digit ZRA TPIN in Compliance first.');
$device=[];$s=$conn->prepare('SELECT * FROM zra_devices WHERE pharmacy_id=? AND branch_id=? AND environment=? LIMIT 1');$env=(string)($settings['smart_invoice_environment']??'test');if($s){$s->bind_param('iis',$pharmacyId,$branchId,$env);$s->execute();$device=$s->get_result()->fetch_assoc()?:[];$s->close();}if(!$device||(int)$device['initialized']!==1)zra_items_redirect('','Initialize the branch ZRA device first.');
$s=$conn->prepare('SELECT id,item_name,barcode,price FROM store_items WHERE id=? AND pharmacy_id=? AND branch_id=? LIMIT 1');if(!$s)zra_items_redirect('','Unable to load product.');$s->bind_param('iii',$productId,$pharmacyId,$branchId);$s->execute();$item=$s->get_result()->fetch_assoc();$s->close();if(!$item)zra_items_redirect('','Product not found in this pharmacy/branch.');
$payload=['tpin'=>$tpin,'bhfId'=>$bhf,'itemCd'=>$itemCd,'itemClsCd'=>$itemClsCd,'itemTyCd'=>$itemTyCd,'itemNm'=>mb_substr((string)$item['item_name'],0,200),'itemStdNm'=>mb_substr((string)$item['item_name'],0,200),'orgnNatCd'=>$orgn,'pkgUnitCd'=>$pkg,'qtyUnitCd'=>$qtyUnit,'vatCatCd'=>$tax,'iplCatCd'=>null,'tlCatCd'=>null,'exciseTxCatCd'=>null,'regrNm'=>(string)($_SESSION['full_name']??'Administrator'),'regrId'=>(string)$userId,'modrNm'=>(string)($_SESSION['full_name']??'Administrator'),'modrId'=>(string)$userId,'btchNo'=>null,'bcd'=>($item['barcode']??null),'dftPrc'=>(float)$item['price'],'addInfo'=>'EchoTech POS','sftyQty'=>0,'isrcAplcbYn'=>'N','useYn'=>'Y'];
$res=zra_http_post((string)$device['base_url'],'/items/saveItem',$payload);
if(!$res['ok'])zra_items_redirect('','ZRA item registration failed: '.($res['error']??'Unknown error.'));
$cols=[];$r=$conn->query('SHOW COLUMNS FROM store_items');if($r)while($c=$r->fetch_assoc())$cols[]=$c['Field'];$sets=[];$vals=[];$types='';foreach(['zra_item_cd'=>$itemCd,'zra_item_cls_cd'=>$itemClsCd,'zra_item_type_cd'=>$itemTyCd,'zra_orgn_nat_cd'=>$orgn,'zra_pkg_unit_cd'=>$pkg,'zra_qty_unit_cd'=>$qtyUnit,'zra_tax_type_cd'=>$tax] as $col=>$val){if(in_array($col,$cols,true)){$sets[]="`$col`=?";$vals[]=$val;$types.='s';}}if(in_array('zra_registered',$cols,true)){$sets[]='zra_registered=1';}if(in_array('zra_registered_at',$cols,true)){$sets[]='zra_registered_at=NOW()';}if($sets){$sql='UPDATE store_items SET '.implode(',',$sets).' WHERE id=? AND pharmacy_id=? AND branch_id=?';$s=$conn->prepare($sql);if($s){$types.='iii';$vals[]=$productId;$vals[]=$pharmacyId;$vals[]=$branchId;$refs=[];$refs[]=$types;foreach($vals as $k=>$v)$refs[]=&$vals[$k];call_user_func_array([$s,'bind_param'],$refs);@$s->execute();$s->close();}}
zra_items_redirect('Product registered with ZRA successfully.');
