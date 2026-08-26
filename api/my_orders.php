<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(E_ALL);
if(session_status()===PHP_SESSION_NONE)session_start();
date_default_timezone_set('Africa/Lusaka');
require_once __DIR__.'/../includes/conn.php';

$clientId=(int)($_SESSION['client_id']??0);
if($clientId<=0){$self=basename($_SERVER['PHP_SELF']);header('Location: login_client.php?redirect='.urlencode($self));exit;}
function mo_h($v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
function mo_json(array $d,int $code=200):never{http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

if(isset($_GET['order'])){
 $id=(int)$_GET['order']; if($id<=0)mo_json(['success'=>false,'message'=>'Invalid order.'],422);
 $stmt=$conn->prepare("SELECT co.id,co.order_number,co.total_amount,co.payment_method,co.status,co.order_date,co.pharmacy_id,co.branch_id,b.branch_name FROM clients_orders co LEFT JOIN branches b ON b.id=co.branch_id WHERE co.id=? AND co.client_id=? LIMIT 1");
 $stmt->bind_param('ii',$id,$clientId);$stmt->execute();$o=$stmt->get_result()->fetch_assoc();$stmt->close();
 if(!$o)mo_json(['success'=>false,'message'=>'Order not found.'],404);
 $stmt=$conn->prepare("SELECT oi.product_id,oi.quantity,oi.price_at_purchase,si.item_name,si.strength,si.image FROM clients_order_items oi LEFT JOIN store_items si ON si.id=oi.product_id WHERE oi.order_id=? AND oi.pharmacy_id=? AND oi.branch_id=? ORDER BY oi.id ASC");
 $stmt->bind_param('iii',$id,$o['pharmacy_id'],$o['branch_id']);$stmt->execute();$items=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
 mo_json(['success'=>true,'order'=>$o,'items'=>$items]);
}

$filter=trim((string)($_GET['status']??''));$allowed=['','Pending','Processing','Completed','Cancelled'];if(!in_array($filter,$allowed,true))$filter='';
$sql="SELECT co.id,co.order_number,co.total_amount,co.payment_method,co.status,co.order_date,co.branch_id,b.branch_name FROM clients_orders co LEFT JOIN branches b ON b.id=co.branch_id WHERE co.client_id=?";$types='i';$params=[$clientId];
if($filter!==''){$sql.=' AND co.status=?';$types.='s';$params[]=$filter;}$sql.=' ORDER BY co.id DESC LIMIT 100';
$stmt=$conn->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$orders=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
require_once __DIR__.'/store_header.php';
?>
<style>
.mo-page{max-width:1120px;margin:auto;padding:25px 18px 110px;background:#f7f9fb;min-height:calc(100vh - 130px)}.mo-head h1{margin:0;color:#003339;font-size:30px;font-weight:850}.mo-head p{margin:5px 0 20px;color:#64748b}.mo-filters{display:flex;gap:8px;overflow:auto;padding:2px 0 12px}.mo-filter{white-space:nowrap;text-decoration:none;border:1px solid #dce5ea;background:#fff;color:#52616d;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:750}.mo-filter.active{background:#003339;color:#fff;border-color:#003339}.mo-card{background:#fff;border:1px solid #e3eaee;border-radius:16px;margin:12px 0;padding:18px;box-shadow:0 4px 16px rgba(0,51,57,.045)}.mo-top{display:flex;justify-content:space-between;gap:15px}.mo-no{font-weight:850;color:#003339}.mo-muted{font-size:12px;color:#71808a}.mo-status{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:850}.mo-pending{background:#fff3cd;color:#8a5a00}.mo-processing{background:#e8e9ff;color:#5147a5}.mo-completed{background:#dcfce7;color:#166534}.mo-cancelled{background:#fee2e2;color:#991b1b}.mo-progress{display:flex;margin:20px 0 15px}.mo-step{flex:1;text-align:center;position:relative;color:#9aa6af;font-size:10px;font-weight:800}.mo-step:not(:last-child):after{content:'';position:absolute;top:9px;left:50%;width:100%;height:3px;background:#e8edf0}.mo-step.done{color:#003339}.mo-dot{position:relative;z-index:1;width:20px;height:20px;margin:auto auto 5px;border-radius:50%;background:#e8edf0}.mo-step.done .mo-dot{background:#00b386}.mo-step.done:not(:last-child):after{background:#00b386}.mo-bottom{border-top:1px solid #edf1f4;padding-top:13px;display:flex;justify-content:space-between;align-items:center}.mo-view{border:0;background:#003339;color:#fff;border-radius:9px;padding:9px 14px;font-weight:750}.mo-empty{text-align:center;padding:60px 20px;color:#64748b}.mo-modal{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none;align-items:center;justify-content:center;padding:18px;z-index:10000}.mo-modal.open{display:flex}.mo-dialog{width:min(680px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:18px}.mo-modal-head{display:flex;justify-content:space-between;align-items:center;padding:17px 18px;border-bottom:1px solid #edf1f4}.mo-close{border:0;width:34px;height:34px;border-radius:50%;background:#f1f5f9;font-size:20px}.mo-body{padding:18px}.mo-item{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #edf1f4}@media(max-width:600px){.mo-page{padding:18px 12px 100px}.mo-top{flex-direction:column}.mo-bottom{gap:12px}.mo-step{font-size:9px}}
</style>
<main class="mo-page">
 <div class="mo-head"><h1>My Orders</h1><p>Track your pharmacy orders and view your order history.</p></div>
 <nav class="mo-filters"><a class="mo-filter <?=$filter===''?'active':''?>" href="my_orders.php">All</a><?php foreach(['Pending','Processing','Completed','Cancelled'] as $s):?><a class="mo-filter <?=$filter===$s?'active':''?>" href="my_orders.php?status=<?=urlencode($s)?>"><?=mo_h($s)?></a><?php endforeach;?></nav>
 <?php if(!$orders):?><div class="mo-card mo-empty"><h3>No orders yet</h3><p>Your orders will appear here after you place an order.</p></div><?php else: foreach($orders as $o):$s=(string)$o['status'];$steps=['Pending','Processing','Completed'];$idx=array_search($s,$steps,true);?>
 <article class="mo-card"><div class="mo-top"><div><div class="mo-no">#<?=mo_h($o['order_number'])?></div><div class="mo-muted"><?=mo_h($o['branch_name']?:'Pharmacy')?> · <?=mo_h($o['order_date'])?></div></div><span class="mo-status mo-<?=strtolower($s)?>"><?=mo_h($s)?></span></div>
 <?php if($s!=='Cancelled'):?><div class="mo-progress"><?php foreach($steps as $i=>$step):?><div class="mo-step <?=$idx!==false&&$i<=$idx?'done':''?>"><div class="mo-dot"></div><?=mo_h($step==='Pending'?'Received':$step)?></div><?php endforeach;?></div><?php endif;?>
 <div class="mo-bottom"><strong>K<?=number_format((float)$o['total_amount'],2)?></strong><button class="mo-view" onclick="viewOrder(<?=$o['id']?>)">View Order</button></div></article>
 <?php endforeach;endif;?>
</main>
<div class="mo-modal" id="moModal"><section class="mo-dialog"><div class="mo-modal-head"><strong id="moTitle">Order</strong><button class="mo-close" onclick="closeOrder()">×</button></div><div class="mo-body" id="moBody">Loading…</div></section></div>
<script>
function moEsc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
async function viewOrder(id){try{const r=await fetch('my_orders.php?order='+encodeURIComponent(id),{cache:'no-store'});const d=await r.json();if(!d.success)throw new Error(d.message||'Unable to load order.');const o=d.order;let h='<div class="mb-3"><b>Status:</b> '+moEsc(o.status)+'<br><b>Branch:</b> '+moEsc(o.branch_name||'')+'<br><span class="mo-muted">'+moEsc(o.order_date||'')+' · '+moEsc(o.payment_method||'')+'</span></div>';h+=d.items.map(i=>'<div class="mo-item"><span><b>'+moEsc(i.item_name||'Product')+'</b><br><span class="mo-muted">'+moEsc(i.strength||'')+' × '+i.quantity+'</span></span><strong>K'+Number(i.price_at_purchase).toFixed(2)+'</strong></div>').join('');h+='<div class="text-end mt-3 fs-5"><b>Total: K'+Number(o.total_amount).toFixed(2)+'</b></div>';document.getElementById('moTitle').textContent='#'+o.order_number;document.getElementById('moBody').innerHTML=h;document.getElementById('moModal').classList.add('open');}catch(e){console.error(e);}}
function closeOrder(){document.getElementById('moModal').classList.remove('open')}document.getElementById('moModal').addEventListener('click',e=>{if(e.target.id==='moModal')closeOrder()});
setInterval(()=>{if(!document.getElementById('moModal').classList.contains('open'))location.reload()},30000);
</script>
