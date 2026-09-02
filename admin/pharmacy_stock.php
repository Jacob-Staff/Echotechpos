<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(E_ALL);
if(session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../includes/auth.php';
date_default_timezone_set('Africa/Lusaka');

$role=strtolower(trim((string)($_SESSION['role']??'')));
if(!in_array($role,['admin','administrator'],true)){http_response_code(403);exit('Access denied.');}
$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);
if($pharmacy_id<=0){header('Location: ../login_inc.php');exit;}
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function money(float $v):string{return 'K'.number_format($v,2);}

$pharmacy_name='PHARMACY POS'; $branch_count=0; $total_orders=0;
$user_display_name=(string)($_SESSION['full_name']??$_SESSION['username']??'Administrator'); $user_role='Admin'; $admin_page_title='Inventory';
try{
 $s=$conn->prepare("SELECT name FROM pharmacies WHERE id=? LIMIT 1");$s->bind_param('i',$pharmacy_id);$s->execute();$r=$s->get_result()->fetch_assoc();if($r)$pharmacy_name=$r['name'];$s->close();
 $s=$conn->prepare("SELECT COUNT(*) c FROM branches WHERE pharmacy_id=?");$s->bind_param('i',$pharmacy_id);$s->execute();$branch_count=(int)($s->get_result()->fetch_assoc()['c']??0);$s->close();
 $s=$conn->prepare("SELECT COUNT(*) c FROM sales WHERE pharmacy_id=?");$s->bind_param('i',$pharmacy_id);$s->execute();$total_orders=(int)($s->get_result()->fetch_assoc()['c']??0);$s->close();
}catch(Throwable $e){error_log('ADMIN STOCK BRANDING '.$e->getMessage());}

$search=trim((string)($_GET['search']??''));$branch_id=(int)($_GET['branch_id']??0);$category=trim((string)($_GET['category']??''));$stock_filter=trim((string)($_GET['stock']??'all'));$expiry_filter=trim((string)($_GET['expiry']??'all'));
$branches=[];$categories=[];
try{
 $s=$conn->prepare("SELECT id,branch_name FROM branches WHERE pharmacy_id=? ORDER BY branch_name");$s->bind_param('i',$pharmacy_id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$branches[]=$x;$s->close();
 $s=$conn->prepare("SELECT DISTINCT category FROM store_items WHERE pharmacy_id=? AND category IS NOT NULL AND category<>'' ORDER BY category");$s->bind_param('i',$pharmacy_id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$categories[]=$x['category'];$s->close();
}catch(Throwable $e){error_log('ADMIN STOCK FILTERS '.$e->getMessage());}

$where=['si.pharmacy_id=?','si.is_active=1'];$params=[$pharmacy_id];$types='i';
if($branch_id>0){$where[]='si.branch_id=?';$params[]=$branch_id;$types.='i';}
if($search!==''){$where[]='(si.item_name LIKE ? OR si.barcode LIKE ?)';$q='%'.$search.'%';$params[]=$q;$params[]=$q;$types.='ss';}
if($category!==''){$where[]='si.category=?';$params[]=$category;$types.='s';}
if($stock_filter==='out')$where[]='si.quantity<=0';
elseif($stock_filter==='low')$where[]='si.quantity BETWEEN 1 AND 10';
elseif($stock_filter==='healthy')$where[]='si.quantity>10';
if($expiry_filter==='expired')$where[]="si.expiry_date IS NOT NULL AND si.expiry_date<>'0000-00-00' AND si.expiry_date<CURDATE()";
elseif($expiry_filter==='soon')$where[]="si.expiry_date IS NOT NULL AND si.expiry_date<>'0000-00-00' AND si.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)";
elseif($expiry_filter==='valid')$where[]="(si.expiry_date IS NULL OR si.expiry_date='0000-00-00' OR si.expiry_date>=CURDATE())";
$whereSql=implode(' AND ',$where);

$summary=['products'=>0,'units'=>0,'value'=>0.0,'low'=>0,'out'=>0,'expired'=>0];
try{
 $s=$conn->prepare("SELECT COUNT(*) products,COALESCE(SUM(quantity),0) units,COALESCE(SUM(price*quantity),0) value,
 SUM(CASE WHEN quantity BETWEEN 1 AND 10 THEN 1 ELSE 0 END) low,
 SUM(CASE WHEN quantity<=0 THEN 1 ELSE 0 END) outc,
 SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date<>'0000-00-00' AND expiry_date<CURDATE() THEN 1 ELSE 0 END) expired
 FROM store_items WHERE pharmacy_id=? AND is_active=1");
 $s->bind_param('i',$pharmacy_id);$s->execute();$summary=array_merge($summary,$s->get_result()->fetch_assoc()?:[]);$s->close();
}catch(Throwable $e){error_log('ADMIN STOCK SUMMARY '.$e->getMessage());}

$rows=[];
$sql="SELECT si.id,si.item_name,si.barcode,si.category,si.price,COALESCE(si.cost,0) cost,si.quantity,si.expiry_date,b.branch_name
FROM store_items si LEFT JOIN branches b ON b.id=si.branch_id AND b.pharmacy_id=si.pharmacy_id
WHERE $whereSql ORDER BY si.quantity ASC,si.item_name ASC LIMIT 1000";
try{
 $s=$conn->prepare($sql);$bind=[$types];foreach($params as $k=>$v)$bind[]=&$params[$k];call_user_func_array([$s,'bind_param'],$bind);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$rows[]=$x;$s->close();
}catch(Throwable $e){error_log('ADMIN STOCK QUERY '.$e->getMessage());}

?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($pharmacy_name)?> - Admin Inventory</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--bg:#f4f6f8;--card:#fff;--text:#1d252d;--muted:#6d7782;--border:#dfe4e9;--blue:#246bfe;--green:#159a68;--yellow:#e7a72e;--red:#d94d61;--sidebar:250px;--shadow:0 4px 18px rgba(31,40,49,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px Inter,Arial,sans-serif}.main{margin-left:var(--sidebar);min-height:100vh}.page{padding:22px;max-width:1700px;margin:auto}.heading{display:flex;justify-content:space-between;gap:16px;margin-bottom:18px}.heading h1{margin:0 0 5px;font-size:28px}.heading p{margin:0;color:var(--muted)}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}.kpi{padding:15px}.kpi small{display:block;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.kpi strong{display:block;font-size:24px;margin-top:7px}.kpi.blue{border-top:3px solid var(--blue)}.kpi.green{border-top:3px solid var(--green)}.kpi.yellow{border-top:3px solid var(--yellow)}.kpi.red{border-top:3px solid var(--red)}
.filters{padding:15px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1fr auto;gap:9px;align-items:end}.field label{display:block;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:5px}.field input,.field select{width:100%;height:40px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff}.btn{height:40px;border:1px solid var(--border);background:#fff;border-radius:8px;padding:0 13px;font-weight:800;cursor:pointer}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.table-card{overflow:hidden}.table-head{padding:15px 17px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between}.table-head h2{margin:0;font-size:16px}.responsive{overflow:auto}table{width:100%;border-collapse:collapse;min-width:950px}th,td{padding:11px 12px;border-bottom:1px solid #edf0f4;text-align:left;font-size:12px}th{background:#fafbfc;color:#667789;font-size:10px;text-transform:uppercase;white-space:nowrap}.badge{display:inline-block;padding:5px 8px;border-radius:999px;font-size:10px;font-weight:800}.good{background:#e8f7f0;color:#08754f}.low{background:#fff6df;color:#9a6700}.out{background:#fff0f2;color:#b4233b}.soon{color:#b45309;font-weight:800}.num{text-align:right;font-weight:700}.empty{text-align:center;padding:40px;color:var(--muted)}
@media(max-width:1250px){.kpis{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.main{margin-left:0}.page{padding:16px}.heading{flex-direction:column}.grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.kpis{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr}.heading h1{font-size:23px}}@media print{.admin-aside,.admin-header,.no-print{display:none!important}.main{margin-left:0}.page{padding:0}.card{box-shadow:none}}
</style></head><body><main class="main">
<?php
require_once __DIR__ . '/actions/admin_aside.php';
require_once __DIR__ . '/actions/admin_header.php';
?>
<div class="page">
<div class="heading"><div><h1><i class="fas fa-boxes-stacked" style="color:var(--blue)"></i> Inventory</h1><p><?=h($pharmacy_name)?> &mdash; consolidated stock across the pharmacy group.</p></div><button class="btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button></div>
<div class="kpis">
<div class="card kpi blue"><small>Active Products</small><strong><?=number_format((int)$summary['products'])?></strong></div>
<div class="card kpi green"><small>Units in Stock</small><strong><?=number_format((int)$summary['units'])?></strong></div>
<div class="card kpi"><small>Selling Value</small><strong><?=money((float)$summary['value'])?></strong></div>
<div class="card kpi yellow"><small>Low Stock</small><strong><?=number_format((int)$summary['low'])?></strong></div>
<div class="card kpi red"><small>Out / Expired</small><strong><?=number_format((int)$summary['out'])?> / <?=number_format((int)$summary['expired'])?></strong></div>
</div>
<form class="card filters no-print"><div class="grid">
<div class="field"><label>Search</label><input name="search" value="<?=h($search)?>" placeholder="Product or barcode"></div>
<div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=(int)$b['id']?>" <?=$branch_id===(int)$b['id']?'selected':''?>><?=h($b['branch_name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Category</label><select name="category"><option value="">All categories</option><?php foreach($categories as $c):?><option value="<?=h($c)?>" <?=$category===$c?'selected':''?>><?=h($c)?></option><?php endforeach;?></select></div>
<div class="field"><label>Stock</label><select name="stock"><option value="all">All</option><option value="healthy" <?=$stock_filter==='healthy'?'selected':''?>>Healthy</option><option value="low" <?=$stock_filter==='low'?'selected':''?>>Low (1-10)</option><option value="out" <?=$stock_filter==='out'?'selected':''?>>Out of stock</option></select></div>
<div class="field"><label>Expiry</label><select name="expiry"><option value="all">All</option><option value="valid" <?=$expiry_filter==='valid'?'selected':''?>>Valid</option><option value="soon" <?=$expiry_filter==='soon'?'selected':''?>>Within 90 days</option><option value="expired" <?=$expiry_filter==='expired'?'selected':''?>>Expired</option></select></div>
<button class="btn primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
</div></form>
<div class="card table-card"><div class="table-head"><h2>Group Stock Directory</h2><span><?=number_format(count($rows))?> records</span></div><div class="responsive"><table><thead><tr><th>#</th><th>Product</th><th>Barcode</th><th>Category</th><th>Branch</th><th>Cost</th><th>Price</th><th>Qty</th><th>Stock Value</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
<?php if($rows):foreach($rows as $i=>$r):
$q=(int)$r['quantity'];$expiry=$r['expiry_date']??'';$expired=$expiry && $expiry!=='0000-00-00' && strtotime($expiry)<strtotime(date('Y-m-d'));$soon=$expiry && !$expired && $expiry!=='0000-00-00' && strtotime($expiry)<=strtotime('+90 days');
?>
<tr><td><?=$i+1?></td><td><strong><?=h($r['item_name'])?></strong></td><td><?=h($r['barcode']?:'â€”')?></td><td><?=h($r['category']?:'General')?></td><td><?=h($r['branch_name']?:'Unassigned')?></td><td><?=money((float)$r['cost'])?></td><td><?=money((float)$r['price'])?></td><td><span class="badge <?=$q<=0?'out':($q<=10?'low':'good')?>"><?=$q?></span></td><td class="num"><?=money($q*(float)$r['price'])?></td><td class="<?=$expired?'out':($soon?'soon':'')?>"><?= $expiry&&$expiry!=='0000-00-00'?h(date('d M Y',strtotime($expiry))):'No expiry'?></td><td><span class="badge <?=$expired||$q<=0?'out':($q<=10||$soon?'low':'good')?>"><?=$expired?'Expired':($q<=0?'Out of stock':($q<=10?'Low stock':($soon?'Expiring soon':'Healthy'))) ?></span></td></tr>
<?php endforeach;else:?><tr><td colspan="11" class="empty">No inventory records match the selected criteria.</td></tr><?php endif;?></tbody></table></div></div>
</div></main></body></html>
