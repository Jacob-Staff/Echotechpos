<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(E_ALL);
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/../includes/conn.php';
require_once __DIR__.'/../includes/auth.php';
date_default_timezone_set('Africa/Lusaka');
$role=strtolower(trim((string)($_SESSION['role']??'')));
if(!in_array($role,['admin','administrator'],true)){http_response_code(403);exit('Access denied.');}
$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);if($pharmacy_id<=0){header('Location: ../login_inc.php');exit;}
function h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function money(float $v):string{return 'K'.number_format($v,2);}
$pharmacy_name='PHARMACY POS';$branch_count=0;$total_orders=0;$user_display_name=(string)($_SESSION['full_name']??$_SESSION['username']??'Administrator');$user_role='Admin';$admin_page_title='Transactions';
try{
 $s=$conn->prepare("SELECT name FROM pharmacies WHERE id=? LIMIT 1");$s->bind_param('i',$pharmacy_id);$s->execute();$r=$s->get_result()->fetch_assoc();if($r)$pharmacy_name=$r['name'];$s->close();
 $s=$conn->prepare("SELECT COUNT(*) c FROM branches WHERE pharmacy_id=?");$s->bind_param('i',$pharmacy_id);$s->execute();$branch_count=(int)($s->get_result()->fetch_assoc()['c']??0);$s->close();
 $s=$conn->prepare("SELECT COUNT(*) c FROM sales WHERE pharmacy_id=?");$s->bind_param('i',$pharmacy_id);$s->execute();$total_orders=(int)($s->get_result()->fetch_assoc()['c']??0);$s->close();
}catch(Throwable $e){error_log('ADMIN TX BRANDING '.$e->getMessage());}

$date=trim((string)($_GET['date']??date('Y-m-d')));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=date('Y-m-d');
$branch_id=(int)($_GET['branch_id']??0);$method=trim((string)($_GET['method']??'All'));$search=trim((string)($_GET['search']??''));
$branches=[];try{$s=$conn->prepare("SELECT id,branch_name FROM branches WHERE pharmacy_id=? ORDER BY branch_name");$s->bind_param('i',$pharmacy_id);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc())$branches[]=$x;$s->close();}catch(Throwable $e){error_log('ADMIN TX BRANCHES '.$e->getMessage());}

$where=['s.pharmacy_id=?','DATE(s.created_at)=?'];$params=[$pharmacy_id,$date];$types='is';
if($branch_id>0){$where[]='s.branch_id=?';$params[]=$branch_id;$types.='i';}
if($method!=='All'){if(strtolower($method)==='mobile')$where[]="LOWER(s.payment_method) IN ('mobile','mobile money','momo')";else{$where[]='LOWER(s.payment_method)=LOWER(?)';$params[]=$method;$types.='s';}}
if($search!==''){$where[]="(s.invoice LIKE ? OR COALESCE(u.full_name,u.username,s.issued_by,'') LIKE ? OR EXISTS(SELECT 1 FROM sales_items sx LEFT JOIN store_items ix ON ix.id=sx.product_id WHERE sx.sale_id=s.id AND ix.item_name LIKE ?))";$q='%'.$search.'%';$params[]=$q;$params[]=$q;$params[]=$q;$types.='sss';}
$whereSql=implode(' AND ',$where);
$rows=[];$revenue=0.0;$units=0;
$sql="SELECT s.id,s.invoice,s.created_at,s.payment_method,COALESCE(s.total,s.total_amount,0) total,b.branch_name,COALESCE(u.full_name,u.username,s.issued_by,'System') issuer,
(SELECT GROUP_CONCAT(CONCAT(COALESCE(st.item_name,'Item'),' (x',si.quantity,')') SEPARATOR ', ') FROM sales_items si LEFT JOIN store_items st ON st.id=si.product_id WHERE si.sale_id=s.id) items_sold
FROM sales s LEFT JOIN branches b ON b.id=s.branch_id AND b.pharmacy_id=s.pharmacy_id LEFT JOIN users u ON u.id=s.user_id
WHERE $whereSql ORDER BY s.id DESC LIMIT 1000";
try{$s=$conn->prepare($sql);$bind=[$types];foreach($params as $k=>$v)$bind[]=&$params[$k];call_user_func_array([$s,'bind_param'],$bind);$s->execute();$r=$s->get_result();while($x=$r->fetch_assoc()){$rows[]=$x;$revenue+=(float)$x['total'];$s2=$conn->prepare("SELECT COALESCE(SUM(quantity),0) q FROM sales_items WHERE sale_id=?");$sid=(int)$x['id'];$s2->bind_param('i',$sid);$s2->execute();$units+=(int)($s2->get_result()->fetch_assoc()['q']??0);$s2->close();}$s->close();}catch(Throwable $e){error_log('ADMIN TX QUERY '.$e->getMessage());}

?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($pharmacy_name)?> - Admin Transactions</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--bg:#f4f6f8;--card:#fff;--text:#1d252d;--muted:#6d7782;--border:#dfe4e9;--blue:#246bfe;--green:#159a68;--yellow:#e7a72e;--sidebar:250px;--shadow:0 4px 18px rgba(31,40,49,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px Inter,Arial,sans-serif}.main{margin-left:var(--sidebar);min-height:100vh}.page{padding:22px;max-width:1700px;margin:auto}.heading{display:flex;justify-content:space-between;gap:16px;margin-bottom:18px}.heading h1{margin:0 0 5px;font-size:28px}.heading p{margin:0;color:var(--muted)}
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}.kpi{padding:16px}.kpi small{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:800}.kpi strong{display:block;font-size:25px;margin-top:7px}.kpi.blue{border-top:3px solid var(--blue)}.kpi.green{border-top:3px solid var(--green)}.kpi.yellow{border-top:3px solid var(--yellow)}
.filters{padding:15px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1fr 1fr 1fr 1.4fr auto;gap:9px;align-items:end}.field label{display:block;font-size:10px;text-transform:uppercase;font-weight:800;color:var(--muted);margin-bottom:5px}.field input,.field select{width:100%;height:40px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff}.btn{height:40px;border:1px solid var(--border);background:#fff;border-radius:8px;padding:0 13px;font-weight:800;cursor:pointer}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.table-card{overflow:hidden}.table-head{padding:15px 17px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between}.table-head h2{margin:0;font-size:16px}.responsive{overflow:auto}table{width:100%;border-collapse:collapse;min-width:900px}th,td{padding:12px;border-bottom:1px solid #edf0f4;text-align:left;font-size:12px;vertical-align:top}th{background:#fafbfc;color:#667789;font-size:10px;text-transform:uppercase;white-space:nowrap}.num{text-align:right;font-weight:800}.badge{display:inline-block;padding:5px 8px;border-radius:999px;background:#eef2f6;color:#52606d;font-size:10px;font-weight:800}.empty{text-align:center;padding:45px;color:var(--muted)}.invoice{color:var(--blue);font-weight:800}
@media(max-width:900px){.main{margin-left:0}.page{padding:16px}.heading{flex-direction:column}.grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.kpis{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.heading h1{font-size:23px}}@media print{.admin-aside,.admin-header,.no-print{display:none!important}.main{margin-left:0}.page{padding:0}.card{box-shadow:none}}
</style></head><body><main class="main">
<?php
require_once __DIR__ . '/actions/admin_aside.php';
require_once __DIR__ . '/actions/admin_header.php';
?>
<div class="page">
<div class="heading"><div><h1><i class="fas fa-receipt" style="color:var(--blue)"></i> Transactions</h1><p><?=h($pharmacy_name)?> &mdash; all branches, <?=h(date('d M Y',strtotime($date)))?>.</p></div><button class="btn no-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button></div>
<div class="kpis"><div class="card kpi blue"><small>Total Revenue</small><strong><?=money($revenue)?></strong></div><div class="card kpi green"><small>Transactions</small><strong><?=number_format(count($rows))?></strong></div><div class="card kpi yellow"><small>Units Sold</small><strong><?=number_format($units)?></strong></div></div>
<form class="card filters no-print"><div class="grid">
<div class="field"><label>Date</label><input type="date" name="date" value="<?=h($date)?>"></div>
<div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=(int)$b['id']?>" <?=$branch_id===(int)$b['id']?'selected':''?>><?=h($b['branch_name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Payment</label><select name="method"><option value="All">All methods</option><option value="Cash" <?=$method==='Cash'?'selected':''?>>Cash</option><option value="Mobile" <?=$method==='Mobile'?'selected':''?>>Mobile Money</option><option value="Card" <?=$method==='Card'?'selected':''?>>Card</option><option value="Bank Transfer" <?=$method==='Bank Transfer'?'selected':''?>>Bank Transfer</option><option value="Cash on Delivery" <?=$method==='Cash on Delivery'?'selected':''?>>Cash on Delivery</option></select></div>
<div class="field"><label>Search</label><input name="search" value="<?=h($search)?>" placeholder="Invoice, staff or product"></div>
<button class="btn primary" type="submit"><i class="fas fa-filter"></i> Apply</button>
</div></form>
<div class="card table-card"><div class="table-head"><h2>Transaction Register</h2><span><?=number_format(count($rows))?> records</span></div><div class="responsive"><table><thead><tr><th>#</th><th>Invoice</th><th>Items</th><th>Branch</th><th>Payment</th><th>Time</th><th>Handled By</th><th>Total</th><th class="no-print">Action</th></tr></thead><tbody>
<?php if($rows):foreach($rows as $i=>$r):?><tr><td><?=$i+1?></td><td><span class="invoice">#<?=h($r['invoice'])?></span></td><td><?=h($r['items_sold']?:'No items recorded')?></td><td><?=h($r['branch_name']?:'Unassigned')?></td><td><span class="badge"><?=h($r['payment_method']?:'Cash')?></span></td><td><?=h(date('h:i A',strtotime((string)$r['created_at'])))?></td><td><?=h($r['issuer'])?></td><td class="num"><?=money((float)$r['total'])?></td><td class="no-print"><a class="btn" href="view_invoice.php?id=<?=(int)$r['id']?>" title="View invoice"><i class="fas fa-eye"></i></a></td></tr><?php endforeach;else:?><tr><td colspan="9" class="empty">No transactions recorded for this criteria.</td></tr><?php endif;?></tbody></table></div></div>
</div></main></body></html>
