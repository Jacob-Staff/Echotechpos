<?php
/**
 * EchoTech POS - Inventory / Stock Report
 * Database-driven pharmacy-wide Admin stock directory.
 */
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);
if($pharmacy_id<=0){header('Location: ../index.php?error=session_expired');exit;}

function st_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function st_bind(mysqli_stmt $s,string $types,array &$params):void{
    if($types==='')return;$refs=[$types];foreach($params as &$v)$refs[]=&$v;call_user_func_array([$s,'bind_param'],$refs);
}
function st_rows(mysqli $c,string $sql,string $types,array $params):array{
    $s=$c->prepare($sql);if(!$s)throw new RuntimeException($c->error);
    if($types!==''){$p=$params;st_bind($s,$types,$p);}
    if(!$s->execute())throw new RuntimeException($s->error);
    $r=$s->get_result();$rows=$r?$r->fetch_all(MYSQLI_ASSOC):[];$s->close();return $rows;
}

$ph=st_rows($conn,"SELECT name FROM pharmacies WHERE id=? LIMIT 1","i",[$pharmacy_id]);
$pharmacy_name=$ph[0]['name']??'PHARMACY POS';
$branches=st_rows($conn,"SELECT id,branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name","i",[$pharmacy_id]);
$categories=st_rows($conn,"SELECT DISTINCT category AS name FROM store_items WHERE pharmacy_id=? AND category IS NOT NULL AND TRIM(category)<>'' ORDER BY category","i",[$pharmacy_id]);

$today=date('Y-m-d');
$branch_id=(int)($_GET['branch_id']??0);
$category=trim((string)($_GET['category']??''));
$stock_filter=$_GET['stock']??'all';
$expiry_filter=$_GET['expiry']??'all';
$search=trim((string)($_GET['search']??''));

if($branch_id>0&&!st_rows($conn,"SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1","ii",[$branch_id,$pharmacy_id]))$branch_id=0;

$where="i.pharmacy_id=? AND i.is_active=1";$types="i";$params=[$pharmacy_id];
if($branch_id>0){$where.=" AND i.branch_id=?";$types.="i";$params[]=$branch_id;}
if($category!==''){$where.=" AND i.category=?";$types.="s";$params[]=$category;}
if($stock_filter==='out')$where.=" AND i.quantity<=0";
elseif($stock_filter==='low')$where.=" AND i.quantity>0 AND i.quantity<=5";
elseif($stock_filter==='in')$where.=" AND i.quantity>5";
if($expiry_filter==='expired')$where.=" AND i.expiry_date IS NOT NULL AND i.expiry_date<?";elseif($expiry_filter==='soon')$where.=" AND i.expiry_date IS NOT NULL AND i.expiry_date BETWEEN ? AND DATE_ADD(?,INTERVAL 30 DAY)";
if($expiry_filter==='expired'){$types.="s";$params[]=$today;}elseif($expiry_filter==='soon'){$types.="ss";$params[]=$today;$params[]=$today;}
if($search!==''){$where.=" AND (i.item_name LIKE CONCAT('%',?,'%') OR i.barcode LIKE CONCAT('%',?,'%') OR i.strength LIKE CONCAT('%',?,'%') OR i.manufacturer LIKE CONCAT('%',?,'%'))";$types.="ssss";for($i=0;$i<4;$i++)$params[]=$search;}

$baseWhere="i.pharmacy_id=? AND i.is_active=1";$baseTypes="i";$baseParams=[$pharmacy_id];
if($branch_id>0){$baseWhere.=" AND i.branch_id=?";$baseTypes.="i";$baseParams[]=$branch_id;}

$kpi=st_rows($conn,"SELECT COUNT(*) active_products,COALESCE(SUM(quantity),0) units_stock,COALESCE(SUM(quantity*price),0) selling_value,
SUM(CASE WHEN quantity>0 AND quantity<=5 THEN 1 ELSE 0 END) low_stock,
SUM(CASE WHEN quantity<=0 THEN 1 ELSE 0 END) out_stock,
SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date<? THEN 1 ELSE 0 END) expired
FROM store_items i WHERE $baseWhere","s".$baseTypes,[$today,...$baseParams])[0]??[];
$rows=st_rows($conn,"SELECT i.id,i.item_name,i.barcode,i.category,i.cost,i.price,i.quantity,i.expiry_date,i.strength,i.manufacturer,
b.branch_name
FROM store_items i
LEFT JOIN branches b ON b.id=i.branch_id AND b.pharmacy_id=i.pharmacy_id
WHERE $where ORDER BY CASE WHEN i.quantity<=0 THEN 0 WHEN i.expiry_date IS NOT NULL AND i.expiry_date<? THEN 1 WHEN i.quantity<=5 THEN 2 ELSE 3 END,i.item_name",
$types."s",[...$params,$today]);

$branch_label='All branches';foreach($branches as $b)if((int)$b['id']===$branch_id)$branch_label=$b['branch_name'];
$admin_page_title='Inventory';$user_role=current_role();$user_display_name=current_user();$branch_count=count($branches);$total_orders=0;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory | <?=st_e($pharmacy_name)?></title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--blue:#246bfe;--bg:#f4f6f8;--border:#dfe4e9;--text:#1d252d;--muted:#718091;--green:#159a68;--orange:#e7a72e;--red:#d94d61}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}.admin-main{margin-left:250px;min-height:100vh}.content{padding:22px 28px 35px}.head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.head h1{font-size:29px;margin:0}.head p{margin:7px 0 0;color:var(--muted);font-size:13px}.btn{height:40px;padding:0 14px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:800;cursor:pointer}.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 18px rgba(31,40,49,.06)}.kpi{padding:17px 18px;min-height:105px;border-top:3px solid var(--blue)}.kpi:nth-child(2){border-top-color:var(--green)}.kpi:nth-child(3){border-top-color:#8c98a6}.kpi:nth-child(4){border-top-color:var(--orange)}.kpi:nth-child(5){border-top-color:var(--red)}.label{font-size:9px;text-transform:uppercase;font-weight:800;letter-spacing:.6px;color:#687587}.value{font-size:25px;font-weight:850;margin-top:8px}.filters{padding:16px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr 1fr auto;gap:10px}.field label{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.field input,.field select{width:100%;height:40px;border:1px solid #d8dee5;border-radius:7px;background:#fff;padding:0 10px}.table-card{overflow:hidden}.table-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;font-weight:800}.muted{color:#8793a0}.wrap{overflow:auto}.table{width:100%;min-width:1100px;border-collapse:collapse}.table th{padding:12px 13px;background:#f6f8fa;color:#647184;font-size:10px;text-transform:uppercase;text-align:left;letter-spacing:.5px}.table td{padding:12px 13px;border-top:1px solid #edf0f3;font-size:12px}.badge{display:inline-block;border-radius:14px;padding:4px 8px;font-size:10px;font-weight:800}.good{background:#e8f7f0;color:#159a68}.low{background:#fff6df;color:#a66f08}.out{background:#fff0f2;color:#c73d51}.expired{background:#fff0f2;color:#c73d51}.money{font-weight:850}.empty{text-align:center;padding:55px;color:#8b96a4}@media(max-width:1100px){.kpis{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.admin-main{margin-left:0}.content{padding:18px 15px}.kpis{grid-template-columns:1fr 1fr}}@media(max-width:600px){.head{align-items:flex-start;flex-direction:column;gap:12px}.grid,.kpis{grid-template-columns:1fr}.head h1{font-size:24px}}@media print{.admin-aside,.admin-header,.filters,.no-print{display:none!important}.admin-main{margin:0}.content{padding:0}.card{box-shadow:none}}
</style></head><body>
<?php require __DIR__.'/actions/admin_aside.php'; ?><div class="admin-main"><?php require __DIR__.'/actions/admin_header.php'; ?>
<main class="content"><div class="head"><div><h1><i class="fa-solid fa-boxes-stacked" style="color:#246bfe"></i> Inventory</h1><p><?=st_e($pharmacy_name)?> â€” consolidated stock across the pharmacy group Â· <?=st_e($branch_label)?></p></div><button class="btn no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>
<div class="kpis"><div class="card kpi"><div class="label">Active Products</div><div class="value"><?=number_format((int)$kpi['active_products'])?></div></div><div class="card kpi"><div class="label">Units in Stock</div><div class="value"><?=number_format((int)$kpi['units_stock'])?></div></div><div class="card kpi"><div class="label">Selling Value</div><div class="value">K<?=number_format((float)$kpi['selling_value'],2)?></div></div><div class="card kpi"><div class="label">Low Stock</div><div class="value"><?=number_format((int)$kpi['low_stock'])?></div></div><div class="card kpi"><div class="label">Out / Expired</div><div class="value"><?=number_format((int)$kpi['out_stock'])?> / <?=number_format((int)$kpi['expired'])?></div></div></div>
<form class="card filters" method="get"><div class="grid"><div class="field"><label>Search</label><input name="search" value="<?=st_e($search)?>" placeholder="Product or barcode"></div><div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=$branch_id==(int)$b['id']?'selected':''?>><?=st_e($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Category</label><select name="category"><option value="">All categories</option><?php foreach($categories as $c):?><option value="<?=st_e($c['name'])?>" <?=$category===$c['name']?'selected':''?>><?=st_e($c['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Stock</label><select name="stock"><option value="all" <?=$stock_filter==='all'?'selected':''?>>All</option><option value="in" <?=$stock_filter==='in'?'selected':''?>>In stock</option><option value="low" <?=$stock_filter==='low'?'selected':''?>>Low stock</option><option value="out" <?=$stock_filter==='out'?'selected':''?>>Out of stock</option></select></div><div class="field"><label>Expiry</label><select name="expiry"><option value="all" <?=$expiry_filter==='all'?'selected':''?>>All</option><option value="soon" <?=$expiry_filter==='soon'?'selected':''?>>Next 30 days</option><option value="expired" <?=$expiry_filter==='expired'?'selected':''?>>Expired</option></select></div><div class="field"><label>&nbsp;</label><button class="btn" style="background:#246bfe;color:#fff;border-color:#246bfe" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div></div></form>
<div class="card table-card"><div class="table-head"><span>Group Stock Directory</span><span class="muted"><?=count($rows)?> records</span></div><div class="wrap"><table class="table"><thead><tr><th>#</th><th>Product</th><th>Barcode</th><th>Category</th><th>Branch</th><th>Cost</th><th>Price</th><th>Qty</th><th>Stock Value</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
<?php if($rows):$n=1;foreach($rows as $r):
$expired=!empty($r['expiry_date'])&&$r['expiry_date']<$today;$qty=(int)$r['quantity'];$status=$expired?'Expired':($qty<=0?'Out of stock':($qty<=5?'Low stock':'In stock'));$cls=$expired||$qty<=0?'out':($qty<=5?'low':'good');
?><tr><td><?=$n++?></td><td><b><?=st_e($r['item_name'])?></b><?php if($r['strength']):?><br><span class="muted"><?=st_e($r['strength'])?></span><?php endif;?></td><td><?=st_e($r['barcode'])?></td><td><?=st_e($r['category']?:'General')?></td><td><?=st_e($r['branch_name'])?></td><td>K<?=number_format((float)$r['cost'],2)?></td><td>K<?=number_format((float)$r['price'],2)?></td><td><b><?=$qty?></b></td><td class="money">K<?=number_format($qty*(float)$r['price'],2)?></td><td><?=st_e($r['expiry_date']?date('d M Y',strtotime($r['expiry_date'])):'â€”')?></td><td><span class="badge <?=$cls?>"><?=st_e($status)?></span></td></tr><?php endforeach;else:?><tr><td colspan="11" class="empty">No stock records found for these criteria.</td></tr><?php endif;?>
</tbody></table></div></div></main></div></body></html>
