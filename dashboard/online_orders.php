<?php
declare(strict_types=1);

ini_set('display_errors','0');
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Africa/Lusaka');

require_once '../includes/conn.php';
require_once '../includes/auth.php';

$pharmacyId=(int)($_SESSION['pharmacy_id']??0);
$branchId=(int)($_SESSION['branch_id']??0);
$userId=(int)($_SESSION['user_id']??0);
if($pharmacyId<=0||$branchId<=0){ header('Location: ../login.php?error=session_expired'); exit; }

function oo_h($v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
function oo_json(array $d,int $code=200):never{http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

$action=strtolower(trim((string)($_POST['action']??$_GET['action']??'')));

if($action==='update_status'){
    if($_SERVER['REQUEST_METHOD']!=='POST') oo_json(['success'=>false,'message'=>'Invalid request method.'],405);
    $orderId=(int)($_POST['order_id']??0);
    $status=trim((string)($_POST['status']??''));
    $allowed=['Pending','Processing','Completed','Cancelled'];
    if($orderId<=0||!in_array($status,$allowed,true)) oo_json(['success'=>false,'message'=>'Invalid order or status.'],422);

    $conn->begin_transaction();
    try{
        $stmt=$conn->prepare("SELECT id,status FROM clients_orders WHERE id=? AND pharmacy_id=? AND branch_id=? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('iii',$orderId,$pharmacyId,$branchId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$order) throw new RuntimeException('Order not found for this branch.');
        $current=(string)$order['status'];
        $valid=false;
        if($status==='Processing') $valid=($current==='Pending');
        elseif($status==='Completed') $valid=($current==='Processing');
        elseif($status==='Cancelled') $valid=in_array($current,['Pending','Processing'],true);
        elseif($status==='Pending') $valid=false;
        if(!$valid) throw new RuntimeException('This order cannot be moved from '.$current.' to '.$status.'.');
        $stmt=$conn->prepare("UPDATE clients_orders SET status=? WHERE id=? AND pharmacy_id=? AND branch_id=? LIMIT 1");
        $stmt->bind_param('siii',$status,$orderId,$pharmacyId,$branchId);$stmt->execute();
        if($stmt->affected_rows!==1) throw new RuntimeException('Order status was not changed.');
        $stmt->close();$conn->commit();
        oo_json(['success'=>true,'message'=>'Order updated.','status'=>$status]);
    }catch(Throwable $e){$conn->rollback();oo_json(['success'=>false,'message'=>$e->getMessage()],422);}
}

if($action==='order'){
    $orderId=(int)($_GET['id']??$_POST['id']??0);
    if($orderId<=0) oo_json(['success'=>false,'message'=>'Invalid order.'],422);
    $stmt=$conn->prepare("SELECT co.id,co.client_id,co.order_number,co.total_amount,co.payment_method,co.status,co.order_date,co.pharmacy_id,co.branch_id,b.branch_name,c.full_name,c.phone,c.email FROM clients_orders co LEFT JOIN branches b ON b.id=co.branch_id LEFT JOIN clients c ON c.id=co.client_id WHERE co.id=? AND co.pharmacy_id=? AND co.branch_id=? LIMIT 1");
    $stmt->bind_param('iii',$orderId,$pharmacyId,$branchId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$order) oo_json(['success'=>false,'message'=>'Order not found.'],404);
    $stmt=$conn->prepare("SELECT oi.product_id,oi.quantity,oi.price_at_purchase,si.item_name,si.strength,si.image FROM clients_order_items oi LEFT JOIN store_items si ON si.id=oi.product_id WHERE oi.order_id=? AND oi.pharmacy_id=? AND oi.branch_id=? ORDER BY oi.id ASC");
    $stmt->bind_param('iii',$orderId,$pharmacyId,$branchId);$stmt->execute();$items=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    oo_json(['success'=>true,'order'=>$order,'items'=>$items]);
}

$filter=trim((string)($_GET['status']??''));
$allowed=['','Pending','Processing','Completed','Cancelled'];
if(!in_array($filter,$allowed,true))$filter='';
$where="WHERE co.pharmacy_id=? AND co.branch_id=?";$types='ii';$params=[$pharmacyId,$branchId];
if($filter!==''){$where.=' AND co.status=?';$types.='s';$params[]=$filter;}
$sql="SELECT co.id,co.client_id,co.order_number,co.total_amount,co.payment_method,co.status,co.order_date,c.full_name,c.phone FROM clients_orders co LEFT JOIN clients c ON c.id=co.client_id $where ORDER BY co.id DESC LIMIT 200";
$stmt=$conn->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$orders=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

$counts=['Pending'=>0,'Processing'=>0,'Completed'=>0,'Cancelled'=>0];
$stmt=$conn->prepare("SELECT status,COUNT(*) total FROM clients_orders WHERE pharmacy_id=? AND branch_id=? GROUP BY status");$stmt->bind_param('ii',$pharmacyId,$branchId);$stmt->execute();$r=$stmt->get_result();while($x=$r->fetch_assoc())if(isset($counts[$x['status']]))$counts[$x['status']]=(int)$x['total'];$stmt->close();

require_once '../includes/head.php';
?>
<style>
.oo-wrap{padding:18px;background:#f5f7fa;min-height:calc(100vh - 70px)}.oo-card{background:#fff;border:1px solid #e5e9ef;border-radius:14px;box-shadow:0 3px 14px rgba(15,23,42,.04)}.oo-title{font-weight:800;color:#17324d}.oo-tabs{display:flex;gap:8px;overflow:auto}.oo-tab{border:1px solid #dfe6ed;background:#fff;color:#526173;border-radius:999px;padding:8px 14px;text-decoration:none;font-weight:700;font-size:13px;white-space:nowrap}.oo-tab.active{background:#17324d;color:#fff;border-color:#17324d}.oo-badge{padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800}.oo-pending{background:#fff3cd;color:#8a5a00}.oo-processing{background:#e8e9ff;color:#5147a5}.oo-completed{background:#dcfce7;color:#166534}.oo-cancelled{background:#fee2e2;color:#991b1b}.oo-table th{font-size:11px;text-transform:uppercase;color:#7b8794;letter-spacing:.04em}.oo-table td{vertical-align:middle}.oo-btn{border:0;border-radius:8px;padding:7px 11px;font-weight:700;font-size:12px}.oo-primary{background:#17324d;color:#fff}.oo-success{background:#0f9d67;color:#fff}.oo-danger{background:#c0392b;color:#fff}.oo-modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999}.oo-modal.open{display:flex}.oo-dialog{width:min(720px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px}.oo-item{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #edf1f5}@media(max-width:700px){.oo-wrap{padding:12px}.oo-table thead{display:none}.oo-table,.oo-table tbody,.oo-table tr,.oo-table td{display:block;width:100%}.oo-table tr{padding:14px;border-bottom:1px solid #edf1f5}.oo-table td{border:0!important;padding:4px 0}.oo-table td:before{content:attr(data-label);display:inline-block;width:100px;color:#8793a0;font-size:11px;font-weight:700}.oo-actions{margin-top:8px}}
</style>
<div id="main-wrapper">
<?php if(file_exists('../includes/header.php'))require_once '../includes/header.php'; if(file_exists('../includes/aside.php'))require_once '../includes/aside.php'; ?>
<div class="page-wrapper"><div class="oo-wrap">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h4 class="oo-title mb-1">Online Orders</h4><div class="text-muted small">Customer orders received from the online pharmacy store.</div></div><button class="oo-btn oo-primary" onclick="location.reload()">â†» Refresh</button></div>
<div class="row g-2 mb-3">
<?php foreach($counts as $k=>$v):?><div class="col-6 col-md-3"><div class="oo-card p-3"><div class="text-muted small"><?=oo_h($k)?></div><div class="fs-4 fw-bold"><?=number_format($v)?></div></div></div><?php endforeach;?>
</div>
<div class="oo-card p-3">
<div class="oo-tabs mb-3"><a class="oo-tab <?=$filter===''?'active':''?>" href="online_orders.php">All</a><?php foreach(array_keys($counts) as $s):?><a class="oo-tab <?=$filter===$s?'active':''?>" href="online_orders.php?status=<?=urlencode($s)?>"><?=oo_h($s)?> <span class="ms-1"><?=number_format($counts[$s])?></span></a><?php endforeach;?></div>
<div class="table-responsive"><table class="table oo-table mb-0"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if(!$orders):?><tr><td colspan="6" class="text-center py-5 text-muted">No online orders found.</td></tr><?php else: foreach($orders as $o): $s=(string)$o['status'];?><tr>
<td data-label="Order"><strong>#<?=oo_h($o['order_number'])?></strong></td><td data-label="Customer"><strong><?=oo_h($o['full_name']?:'Customer')?></strong><br><small class="text-muted"><?=oo_h($o['phone']??'')?></small></td><td data-label="Date"><?=oo_h($o['order_date'])?></td><td data-label="Total"><strong>K<?=number_format((float)$o['total_amount'],2)?></strong></td><td data-label="Status"><span class="oo-badge oo-<?=strtolower($s)?>"><?=oo_h($s)?></span></td>
<td data-label="Action" class="oo-actions"><button class="oo-btn oo-primary" onclick="viewOrder(<?=$o['id']?>)">View</button> <?php if($s==='Pending'):?><button class="oo-btn oo-success" onclick="changeStatus(<?=$o['id']?>,'Processing')">Accept</button><button class="oo-btn oo-danger" onclick="changeStatus(<?=$o['id']?>,'Cancelled')">Cancel</button><?php elseif($s==='Processing'):?><button class="oo-btn oo-success" onclick="changeStatus(<?=$o['id']?>,'Completed')">Complete</button><button class="oo-btn oo-danger" onclick="changeStatus(<?=$o['id']?>,'Cancelled')">Cancel</button><?php endif;?></td>
</tr><?php endforeach;endif;?></tbody></table></div></div>
</div></div></div>
<div class="oo-modal" id="ooModal"><div class="oo-dialog"><div class="p-3 border-bottom d-flex justify-content-between"><strong id="ooTitle">Order</strong><button class="btn btn-light rounded-circle" onclick="closeOrder()">Ã—</button></div><div class="p-3" id="ooBody">Loadingâ€¦</div></div></div>
<script>
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
async function viewOrder(id){const r=await fetch('online_orders.php?action=order&id='+id);const d=await r.json();if(!d.success){alert(d.message||'Unable to load order.');return}const o=d.order;let h='<div class="mb-3"><b>Customer:</b> '+esc(o.full_name||'Customer')+'<br><b>Phone:</b> '+esc(o.phone||'')+'<br><b>Branch:</b> '+esc(o.branch_name||'')+'<br><b>Payment:</b> '+esc(o.payment_method||'')+'<br><b>Status:</b> '+esc(o.status)+'</div>';h+=d.items.map(i=>'<div class="oo-item"><span><b>'+esc(i.item_name||'Product')+'</b><br><small class="text-muted">'+esc(i.strength||'')+' Ã— '+i.quantity+'</small></span><strong>K'+Number(i.price_at_purchase).toFixed(2)+'</strong></div>').join('');h+='<div class="text-end fs-5 mt-3"><b>Total: K'+Number(o.total_amount).toFixed(2)+'</b></div>';document.getElementById('ooTitle').textContent='#'+o.order_number;document.getElementById('ooBody').innerHTML=h;document.getElementById('ooModal').classList.add('open');}
function closeOrder(){document.getElementById('ooModal').classList.remove('open')}
async function changeStatus(id,status){if(!confirm('Change this order to '+status+'?'))return;const fd=new FormData();fd.append('action','update_status');fd.append('order_id',id);fd.append('status',status);const r=await fetch('online_orders.php',{method:'POST',body:fd});const d=await r.json();if(!d.success){alert(d.message||'Unable to update order.');return}location.reload();}
document.getElementById('ooModal').addEventListener('click',e=>{if(e.target.id==='ooModal')closeOrder()});
setTimeout(()=>location.reload(),60000);
</script>
<?php if(file_exists('../includes/footer.php'))require_once '../includes/footer.php'; ?>
