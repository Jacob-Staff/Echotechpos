<?php
/**
 * EchoTech POS - Transactions
 * Database-driven Admin transaction register.
 */
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);
if($pharmacy_id<=0){header('Location: ../index.php?error=session_expired');exit;}

function tx_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function tx_bind(mysqli_stmt $s,string $types,array &$params):void{
    if($types==='')return;$refs=[$types];foreach($params as &$v)$refs[]=&$v;call_user_func_array([$s,'bind_param'],$refs);
}
function tx_rows(mysqli $c,string $sql,string $types,array $params):array{
    $s=$c->prepare($sql);if(!$s)throw new RuntimeException($c->error);
    if($types!==''){$p=$params;tx_bind($s,$types,$p);}
    if(!$s->execute())throw new RuntimeException($s->error);
    $r=$s->get_result();$rows=$r?$r->fetch_all(MYSQLI_ASSOC):[];$s->close();return $rows;
}

$ph=tx_rows($conn,"SELECT name FROM pharmacies WHERE id=? LIMIT 1","i",[$pharmacy_id]);
$pharmacy_name=$ph[0]['name']??'PHARMACY POS';
$branches=tx_rows($conn,"SELECT id,branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name","i",[$pharmacy_id]);

$today=date('Y-m-d');
$filter_date=$_GET['date']??$today;
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$filter_date))$filter_date=$today;
$branch_id=(int)($_GET['branch_id']??0);
$payment=$_GET['payment']??'';
$search=trim((string)($_GET['search']??''));

if($branch_id>0&&!tx_rows($conn,"SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1","ii",[$branch_id,$pharmacy_id]))$branch_id=0;

$where="s.pharmacy_id=? AND DATE(s.sale_date)=?";
$types="is";$params=[$pharmacy_id,$filter_date];
if($branch_id>0){$where.=" AND s.branch_id=?";$types.="i";$params[]=$branch_id;}
if($payment!==''){$where.=" AND s.payment_method=?";$types.="s";$params[]=$payment;}
if($search!==''){
    $where.=" AND (s.invoice LIKE CONCAT('%',?,'%') OR s.issued_by LIKE CONCAT('%',?,'%') OR u.username LIKE CONCAT('%',?,'%') OR u.full_name LIKE CONCAT('%',?,'%') OR EXISTS(SELECT 1 FROM sales_items sx INNER JOIN store_items ix ON ix.id=sx.product_id WHERE sx.sale_id=s.id AND (ix.item_name LIKE CONCAT('%',?,'%') OR ix.barcode LIKE CONCAT('%',?,'%'))))";
    $types.="ssssss";for($i=0;$i<6;$i++)$params[]=$search;
}

$summary=tx_rows($conn,"SELECT COALESCE(SUM(x.total_amount),0) revenue,COUNT(*) transactions,COALESCE(SUM(x.units),0) units
FROM (
    SELECT s.id,s.total_amount,COALESCE(SUM(si.quantity),0) units
    FROM sales s
    LEFT JOIN sales_items si ON si.sale_id=s.id AND si.pharmacy_id=s.pharmacy_id AND si.branch_id=s.branch_id
    LEFT JOIN users u ON u.id=s.user_id AND u.pharmacy_id=s.pharmacy_id
    WHERE $where
    GROUP BY s.id,s.total_amount
) x",$types,$params)[0]??['revenue'=>0,'transactions'=>0,'units'=>0];

$rows=tx_rows($conn,"SELECT s.id,s.invoice,s.sale_date,s.total_amount,s.payment_method,s.issued_by,
b.branch_name,COALESCE(NULLIF(TRIM(u.full_name),''),u.username,s.issued_by,'Unknown') cashier,
COALESCE(SUM(si.quantity),0) units,COUNT(DISTINCT si.id) item_lines
FROM sales s
LEFT JOIN sales_items si ON si.sale_id=s.id AND si.pharmacy_id=s.pharmacy_id AND si.branch_id=s.branch_id
LEFT JOIN branches b ON b.id=s.branch_id AND b.pharmacy_id=s.pharmacy_id
LEFT JOIN users u ON u.id=s.user_id AND u.pharmacy_id=s.pharmacy_id
WHERE $where
GROUP BY s.id,s.invoice,s.sale_date,s.total_amount,s.payment_method,s.issued_by,b.branch_name,u.full_name,u.username
ORDER BY s.sale_date DESC,s.id DESC",$types,$params);

$paymentOptions=tx_rows($conn,"SELECT DISTINCT payment_method FROM sales WHERE pharmacy_id=? AND payment_method IS NOT NULL AND TRIM(payment_method)<>'' ORDER BY payment_method","i",[$pharmacy_id]);
$branch_label='All branches';foreach($branches as $b)if((int)$b['id']===$branch_id)$branch_label=$b['branch_name'];
$admin_page_title='Transactions';$user_role=current_role();$user_display_name=current_user();$branch_count=count($branches);$total_orders=(int)$summary['transactions'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transactions | <?=tx_e($pharmacy_name)?></title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--blue:#246bfe;--bg:#f4f6f8;--border:#dfe4e9;--text:#1d252d;--muted:#718091;--green:#159a68;--orange:#e7a72e}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}.admin-main{margin-left:250px;min-height:100vh}.content{padding:22px 28px 35px}.head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.head h1{font-size:29px;margin:0}.head p{margin:7px 0 0;color:var(--muted);font-size:13px}.btn{height:40px;padding:0 14px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:800;cursor:pointer}.primary{background:var(--blue);border-color:var(--blue);color:#fff}.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 18px rgba(31,40,49,.06)}.kpi{padding:18px 20px;border-top:3px solid var(--blue);min-height:105px}.kpi:nth-child(2){border-top-color:var(--green)}.kpi:nth-child(3){border-top-color:var(--orange)}.label{font-size:10px;text-transform:uppercase;font-weight:800;letter-spacing:.7px;color:#687587}.value{font-size:27px;font-weight:850;margin-top:8px}.filters{padding:16px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1fr 1fr 1fr 1.6fr auto;gap:10px}.field label{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.field input,.field select{width:100%;height:40px;border:1px solid #d8dee5;border-radius:7px;background:#fff;padding:0 10px}.table-card{overflow:hidden}.table-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;font-weight:800}.muted{color:#8793a0}.wrap{overflow:auto}.table{width:100%;min-width:950px;border-collapse:collapse}.table th{padding:12px 14px;background:#f6f8fa;color:#647184;font-size:10px;text-transform:uppercase;text-align:left;letter-spacing:.5px}.table td{padding:13px 14px;border-top:1px solid #edf0f3;font-size:12px}.badge{display:inline-block;background:#edf3ff;color:#246bfe;border-radius:14px;padding:4px 8px;font-size:10px;font-weight:800}.money{font-weight:850}.empty{text-align:center;padding:55px;color:#8b96a4}@media(max-width:900px){.admin-main{margin-left:0}.content{padding:18px 15px}.grid{grid-template-columns:1fr 1fr}.kpis{grid-template-columns:1fr 1fr}}@media(max-width:600px){.head{align-items:flex-start;flex-direction:column;gap:12px}.grid,.kpis{grid-template-columns:1fr}.head h1{font-size:24px}}@media print{.admin-aside,.admin-header,.filters,.no-print{display:none!important}.admin-main{margin:0}.content{padding:0}.card{box-shadow:none}}
</style></head><body>
<?php require __DIR__.'/actions/admin_aside.php'; ?><div class="admin-main"><?php require __DIR__.'/actions/admin_header.php'; ?>
<main class="content"><div class="head"><div><h1><i class="fa-solid fa-receipt" style="color:#246bfe"></i> Transactions</h1><p><?=tx_e($pharmacy_name)?> â€” <?=tx_e($branch_label)?>, <?=date('d M Y',strtotime($filter_date))?></p></div><button class="btn no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>
<div class="kpis"><div class="card kpi"><div class="label">Total Revenue</div><div class="value">K<?=number_format((float)$summary['revenue'],2)?></div></div><div class="card kpi"><div class="label">Transactions</div><div class="value"><?=number_format((int)$summary['transactions'])?></div></div><div class="card kpi"><div class="label">Units Sold</div><div class="value"><?=number_format((int)$summary['units'])?></div></div></div>
<form class="card filters" method="get"><div class="grid"><div class="field"><label>Date</label><input type="date" name="date" value="<?=tx_e($filter_date)?>"></div><div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=$branch_id==(int)$b['id']?'selected':''?>><?=tx_e($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Payment</label><select name="payment"><option value="">All methods</option><?php foreach($paymentOptions as $p):?><option value="<?=tx_e($p['payment_method'])?>" <?=$payment===$p['payment_method']?'selected':''?>><?=tx_e($p['payment_method'])?></option><?php endforeach;?></select></div><div class="field"><label>Search</label><input name="search" value="<?=tx_e($search)?>" placeholder="Invoice, staff or product"></div><div class="field"><label>&nbsp;</label><button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div></div></form>
<div class="card table-card"><div class="table-head"><span>Transaction Register</span><span class="muted"><?=count($rows)?> records</span></div><div class="wrap"><table class="table"><thead><tr><th>#</th><th>Invoice</th><th>Items</th><th>Branch</th><th>Payment</th><th>Time</th><th>Handled By</th><th>Total</th><th>Action</th></tr></thead><tbody>
<?php if($rows):$n=1;foreach($rows as $r):?><tr><td><?=$n++?></td><td><span class="badge"><?=tx_e($r['invoice'])?></span></td><td><?=number_format((int)$r['units'])?> units <span class="muted">(<?=number_format((int)$r['item_lines'])?> lines)</span></td><td><?=tx_e($r['branch_name'])?></td><td><?=tx_e($r['payment_method']?:'Cash')?></td><td><?=tx_e(date('H:i',strtotime($r['sale_date'])))?></td><td><?=tx_e($r['cashier'])?></td><td class="money">K<?=number_format((float)$r['total_amount'],2)?></td><td><a class="btn" href="view_invoice.php?id=<?=$r['id']?>" title="View invoice"><i class="fa-solid fa-eye"></i></a></td></tr><?php endforeach;else:?><tr><td colspan="9" class="empty">No transactions recorded for this criteria.</td></tr><?php endif;?>
</tbody></table></div></div></main></div></body></html>
