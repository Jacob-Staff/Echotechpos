<?php
/* Admin page DB bootstrap: uses the project's existing connection file. */
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$__admin_db_candidates = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../includes/database.php',
    __DIR__ . '/../db.php',
];
foreach ($__admin_db_candidates as $__f) {
    if (is_file($__f)) { require_once $__f; break; }
}
unset($__f, $__admin_db_candidates);

$adminDb = null;
foreach (['conn','mysqli','db'] as $__v) {
    if (isset($$__v) && $$__v instanceof mysqli) { $adminDb = $$__v; break; }
}
if (!$adminDb && function_exists('get_db_connection')) {
    $adminDb = get_db_connection();
}
if (!$adminDb && function_exists('db_connect')) {
    $adminDb = db_connect();
}
if (!$adminDb || !($adminDb instanceof mysqli)) {
    http_response_code(500);
    die('Database connection was not found. Keep the existing POS database connection file in config/ or includes/.');
}
$adminDb->set_charset('utf8mb4');

$adminPharmacyId = 0;
foreach (['pharmacy_id','admin_pharmacy_id'] as $__k) {
    if (!empty($_SESSION[$__k])) { $adminPharmacyId = (int)$_SESSION[$__k]; break; }
}
if (!$adminPharmacyId && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $adminPharmacyId = (int)($_SESSION['user']['pharmacy_id'] ?? 0);
}
if (!$adminPharmacyId && isset($_SESSION['admin']) && is_array($_SESSION['admin'])) {
    $adminPharmacyId = (int)($_SESSION['admin']['pharmacy_id'] ?? 0);
}
if (!$adminPharmacyId && isset($_SESSION['logged_in_user']) && is_array($_SESSION['logged_in_user'])) {
    $adminPharmacyId = (int)($_SESSION['logged_in_user']['pharmacy_id'] ?? 0);
}
if (!$adminPharmacyId && isset($_SESSION['user_id'])) {
    $uid=(int)$_SESSION['user_id'];
    $st=$adminDb->prepare('SELECT pharmacy_id FROM users WHERE id=? LIMIT 1');
    if($st){$st->bind_param('i',$uid);$st->execute();$r=$st->get_result()->fetch_assoc();$adminPharmacyId=(int)($r['pharmacy_id']??0);$st->close();}
}
if ($adminPharmacyId <= 0) {
    http_response_code(403);
    die('Admin pharmacy context was not found in the current session.');
}

function admin_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function admin_money($v): string { return 'K' . number_format((float)$v, 2); }
function admin_csrf(): string {
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf']=bin2hex(random_bytes(24));
    return $_SESSION['admin_csrf'];
}
function admin_check_csrf(): void {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); die('Invalid security token. Please refresh and try again.'); }
}
function admin_redirect(string $url): never { header('Location: '.$url); exit; }
function admin_current_user_id(): int {
    foreach (['user_id','admin_id'] as $k) if (!empty($_SESSION[$k])) return (int)$_SESSION[$k];
    foreach (['user','admin','logged_in_user'] as $k) if (isset($_SESSION[$k]) && is_array($_SESSION[$k])) return (int)($_SESSION[$k]['id'] ?? 0);
    return 0;
}
function admin_q(mysqli $db,string $sql,array $types=[],array $vals=[]): array {
    $st=$db->prepare($sql); if(!$st) throw new RuntimeException($db->error);
    if($types) $st->bind_param($types,...$vals); $st->execute(); $res=$st->get_result(); $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[]; $st->close(); return $rows;
}
function admin_one(mysqli $db,string $sql,array $types=[],array $vals=[]): ?array { $r=admin_q($db,$sql,$types,$vals); return $r[0]??null; }


$pageTitle='Purchase Orders';$csrf=admin_csrf();$notice='';$error='';
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  admin_check_csrf();$action=$_POST['action']??'';
  if($action==='create_po'){
   $supplier=(int)$_POST['supplier_id'];$branch=(int)$_POST['branch_id'];$expected=trim($_POST['expected_date']??'');$notes=trim($_POST['notes']??'');
   $ids=$_POST['product_id']??[];$qty=$_POST['quantity']??[];$price=$_POST['unit_price']??[];
   $sup=admin_one($adminDb,'SELECT id FROM suppliers WHERE id=? AND pharmacy_id=?','ii',[$supplier,$adminPharmacyId]);$br=admin_one($adminDb,'SELECT id FROM branches WHERE id=? AND pharmacy_id=?','ii',[$branch,$adminPharmacyId]);
   if(!$sup||!$br) throw new RuntimeException('Invalid supplier or branch.');
   $items=[];$total=0;
   foreach($ids as $i=>$pid){$pid=(int)$pid;$q=max(0,(int)($qty[$i]??0));$p=max(0,(float)($price[$i]??0));if(!$pid||$q<1)continue;$prod=admin_one($adminDb,'SELECT id,item_name FROM store_items WHERE id=? AND pharmacy_id=? AND branch_id=? AND is_active=1 LIMIT 1','iii',[$pid,$adminPharmacyId,$branch]);if(!$prod)continue;$items[]=[$pid,$q,$p];$total+=($q*$p);}
   if(!$items) throw new RuntimeException('Add at least one valid product with quantity greater than zero.');
   $adminDb->begin_transaction();$poNo='PO-'.date('Ymd-His').'-'.random_int(100,999);$st=$adminDb->prepare('INSERT INTO purchase_orders(po_number,pharmacy_id,supplier_id,po_date,expected_date,status,total_cost,created_by,branch_id) VALUES(?,?,?,?,?,?,?,?,?)');$status='ordered';$uid=admin_current_user_id();$ed=$expected!==''?$expected:null;$now=date('Y-m-d H:i:s');$st->bind_param('siisssdii',$poNo,$adminPharmacyId,$supplier,$now,$ed,$status,$total,$uid,$branch);if(!$st->execute())throw new RuntimeException($st->error);$poId=$st->insert_id;$st->close();
   $it=$adminDb->prepare('INSERT INTO purchase_items(purchase_id,product_id,quantity,qty_received,unit_price,pharmacy_id,branch_id) VALUES(?,?,?,0,?,?,?)');foreach($items as $x){$it->bind_param('iiidii',$poId,$x[0],$x[1],$x[2],$adminPharmacyId,$branch);if(!$it->execute())throw new RuntimeException($it->error);} $it->close();$adminDb->commit();$notice='Purchase order '.$poNo.' created successfully.';
  }elseif($action==='cancel_po'){$id=(int)$_POST['id'];$st=$adminDb->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=? AND pharmacy_id=? AND status IN ('draft','ordered','partial')");$st->bind_param('ii',$id,$adminPharmacyId);$st->execute();$st->close();$notice='Purchase order cancelled.';}
  elseif($action==='receive_po'){
   $id=(int)$_POST['id'];$rows=admin_q($adminDb,'SELECT pi.*,si.quantity current_qty FROM purchase_items pi JOIN store_items si ON si.id=pi.product_id AND si.pharmacy_id=pi.pharmacy_id AND si.branch_id=pi.branch_id WHERE pi.purchase_id=? AND pi.pharmacy_id=?','ii',[$id,$adminPharmacyId]);$po=admin_one($adminDb,'SELECT * FROM purchase_orders WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$id,$adminPharmacyId]);if(!$po)throw new RuntimeException('Purchase order not found.');$adminDb->begin_transaction();foreach($rows as $r){$received=(int)($r['qty_received']??0);$ordered=(int)$r['quantity'];$add=max(0,$ordered-$received);if(!$add)continue;$st=$adminDb->prepare('UPDATE purchase_items SET qty_received=quantity WHERE id=? AND purchase_id=? AND pharmacy_id=?');$st->bind_param('iii',$r['id'],$id,$adminPharmacyId);$st->execute();$st->close();$u=$adminDb->prepare('UPDATE store_items SET quantity=quantity+? WHERE id=? AND pharmacy_id=? AND branch_id=?');$u->bind_param('iiii',$add,$r['product_id'],$adminPharmacyId,$r['branch_id']);$u->execute();$u->close();}$up=$adminDb->prepare("UPDATE purchase_orders SET status='received' WHERE id=? AND pharmacy_id=?");$up->bind_param('ii',$id,$adminPharmacyId);$up->execute();$up->close();$adminDb->commit();$notice='Purchase order received and stock updated.';
  }
 }
}catch(Throwable $e){if($adminDb->errno===0){/* no-op */}$error=$e->getMessage();if($adminDb->errno)@$adminDb->rollback();}

$branches=admin_q($adminDb,'SELECT id,branch_name FROM branches WHERE pharmacy_id=? ORDER BY branch_name','i',[$adminPharmacyId]);$suppliers=admin_q($adminDb,'SELECT id,name FROM suppliers WHERE pharmacy_id=? ORDER BY name','i',[$adminPharmacyId]);
$products=admin_q($adminDb,'SELECT id,item_name,barcode,cost,quantity,branch_id FROM store_items WHERE pharmacy_id=? AND is_active=1 ORDER BY item_name','i',[$adminPharmacyId]);
$branchFilter=(int)($_GET['branch_id']??0);$statusFilter=$_GET['status']??'';$search=trim($_GET['q']??'');$supplierFilter=(int)($_GET['supplier_id']??0);$where='po.pharmacy_id=?';$types='i';$vals=[$adminPharmacyId];if($branchFilter){$where.=' AND po.branch_id=?';$types.='i';$vals[]=$branchFilter;}if($supplierFilter){$where.=' AND po.supplier_id=?';$types.='i';$vals[]=$supplierFilter;}if(in_array($statusFilter,['draft','ordered','partial','received','cancelled'],true)){$where.=' AND po.status=?';$types.='s';$vals[]=$statusFilter;}if($search!==''){$where.=' AND (po.po_number LIKE ? OR s.name LIKE ?)';$types.='ss';$like='%'.$search.'%';$vals[]=$like;$vals[]=$like;}
$orders=admin_q($adminDb,"SELECT po.*,s.name supplier_name,b.branch_name,u.full_name created_name,(SELECT COALESCE(SUM(pi.quantity),0) FROM purchase_items pi WHERE pi.purchase_id=po.id AND pi.pharmacy_id=po.pharmacy_id) ordered_units,(SELECT COALESCE(SUM(pi.qty_received),0) FROM purchase_items pi WHERE pi.purchase_id=po.id AND pi.pharmacy_id=po.pharmacy_id) received_units FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id AND s.pharmacy_id=po.pharmacy_id LEFT JOIN branches b ON b.id=po.branch_id AND b.pharmacy_id=po.pharmacy_id LEFT JOIN users u ON u.id=po.created_by WHERE $where ORDER BY po.po_date DESC,po.id DESC",$types,$vals);
$view=null;$viewItems=[];if(isset($_GET['view'])){$vid=(int)$_GET['view'];$view=admin_one($adminDb,'SELECT po.*,s.name supplier_name,s.phone supplier_phone,s.email supplier_email,b.branch_name,u.full_name created_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id AND s.pharmacy_id=po.pharmacy_id LEFT JOIN branches b ON b.id=po.branch_id AND b.pharmacy_id=po.pharmacy_id LEFT JOIN users u ON u.id=po.created_by WHERE po.id=? AND po.pharmacy_id=?','ii',[$vid,$adminPharmacyId]);if($view)$viewItems=admin_q($adminDb,'SELECT pi.*,si.item_name,si.barcode FROM purchase_items pi JOIN store_items si ON si.id=pi.product_id WHERE pi.purchase_id=? AND pi.pharmacy_id=? ORDER BY pi.id','ii',[$vid,$adminPharmacyId]);}
require_once __DIR__.'/actions/admin_aside.php';
?>
<div class="admin-main"><div class="admin-header-slot"><?php require_once __DIR__.'/actions/admin_header.php'; ?></div><main class="admin-page">
<style>
.admin-page{padding:24px 28px 40px;background:#f5f7fb;min-height:calc(100vh - 70px);font-family:Inter,Arial,sans-serif;color:#17202a}.admin-page *{box-sizing:border-box}.top{display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:20px}.top h1{margin:0;font-size:25px}.top p{margin:5px 0;color:#718096;font-size:13px}.btn{border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.primary{background:#17202a;color:#fff}.light{background:#fff;color:#344054;border:1px solid #d9e0e8}.danger{background:#fff0f0;color:#c53030;border:1px solid #ffd0d0}.card{background:#fff;border:1px solid #e7ebf0;border-radius:14px;box-shadow:0 5px 20px rgba(20,30,50,.04);margin-bottom:20px}.head{padding:16px 19px;border-bottom:1px solid #edf0f4;display:flex;justify-content:space-between;align-items:center}.head h2{font-size:16px;margin:0}.body{padding:19px}.filters{display:flex;gap:9px;flex-wrap:wrap}.filters input,.filters select,.field input,.field select,.field textarea{padding:10px;border:1px solid #dce2e9;border-radius:8px;background:#fff;font:inherit}.filters input{min-width:210px}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:1000px}.table th,.table td{padding:12px 15px;border-bottom:1px solid #edf0f4;text-align:left;font-size:13px}.table th{background:#fafbfd;color:#687386;font-size:11px;text-transform:uppercase}.badge{padding:5px 8px;border-radius:999px;font-size:11px;font-weight:700}.ordered{background:#eef4ff;color:#315ea8}.partial{background:#fff7e6;color:#9a6700}.received{background:#edf8f0;color:#27753a}.cancelled{background:#fff0f0;color:#b42318}.draft{background:#f1f3f5;color:#596579}.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:20px}.formgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:flex;flex-direction:column;gap:6px}.field label{font-size:12px;font-weight:700;color:#596579}.full{grid-column:1/-1}.item-row{display:grid;grid-template-columns:1.7fr .7fr .8fr 40px;gap:8px;margin-bottom:8px;align-items:center}.remove{border:0;background:#fff0f0;color:#c53030;border-radius:8px;height:38px}.total{font-size:18px;font-weight:800;text-align:right;padding-top:10px}.toast{padding:11px 14px;border-radius:9px;margin-bottom:15px}.ok{background:#edf8f0;color:#256b35}.err{background:#fff0f0;color:#b42318}.empty{text-align:center;padding:35px;color:#788495}.modalbox{background:#fff;border:1px solid #e3e7ed;border-radius:14px;padding:20px}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.detail{background:#fafbfd;border-radius:9px;padding:11px}.detail small{display:block;color:#788495;margin-bottom:4px}.detail strong{font-size:13px}@media(max-width:1000px){.grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr 1fr}}@media(max-width:700px){.admin-main{margin-left:0}.admin-page{padding:16px}.formgrid{grid-template-columns:1fr}.item-row{grid-template-columns:1fr 80px 100px 40px}.detail-grid{grid-template-columns:1fr}}
</style>
<div class="top"><div><h1>Purchase Orders</h1><p>Group-wide supplier purchasing and stock receiving.</p></div></div>
<?php if($notice):?><div class="toast ok"><?=admin_h($notice)?></div><?php endif;?><?php if($error):?><div class="toast err"><?=admin_h($error)?></div><?php endif;?>
<div class="grid"><div class="card"><div class="head"><h2>Create Purchase Order</h2></div><div class="body"><form method="post" id="poForm"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="create_po"><div class="formgrid"><div class="field"><label>Supplier *</label><select name="supplier_id" required><option value="">Select supplier</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=admin_h($s['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Branch *</label><select name="branch_id" id="branch" required><option value="">Select branch</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>"><?=admin_h($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Expected Date</label><input type="date" name="expected_date"></div><div class="field full"><label>Notes</label><textarea name="notes" placeholder="Optional notes"></textarea></div></div><div style="margin:18px 0 8px;font-weight:800">Order Items</div><div id="items"></div><button type="button" class="btn light" onclick="addItem()">＋ Add Item</button><div class="total">Total: <span id="grand">K0.00</span></div><div style="margin-top:15px"><button class="btn primary">Create Purchase Order</button></div></form></div></div>
<div class="card"><div class="head"><h2>Quick Status</h2></div><div class="body"><p style="margin:0 0 10px;color:#718096;font-size:13px">Use the register below to view, cancel, or receive orders. Receiving updates the corresponding branch stock.</p><div class="detail-grid"><div class="detail"><small>Orders</small><strong><?=count($orders)?></strong></div><div class="detail"><small>Ordered Value</small><strong><?=admin_money(array_sum(array_column($orders,'total_cost')))?></strong></div></div></div></div></div>
<div class="card"><div class="head"><h2>Purchase Order Register</h2><span style="font-size:12px;color:#788495"><?=count($orders)?> result(s)</span></div><form class="filters" method="get"><input name="q" value="<?=admin_h($search)?>" placeholder="PO number or supplier"><select name="supplier_id"><option value="0">All suppliers</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>" <?=($supplierFilter===$s['id'])?'selected':''?>><?=admin_h($s['name'])?></option><?php endforeach;?></select><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=($branchFilter===$b['id'])?'selected':''?>><?=admin_h($b['branch_name'])?></option><?php endforeach;?></select><select name="status"><option value="">All statuses</option><?php foreach(['draft','ordered','partial','received','cancelled'] as $st):?><option value="<?=$st?>" <?=($statusFilter===$st)?'selected':''?>><?=ucfirst($st)?></option><?php endforeach;?></select><button class="btn primary">Filter</button><a class="btn light" href="purchase_orders.php">Reset</a></form><div class="table-wrap"><table class="table"><thead><tr><th>PO</th><th>Supplier</th><th>Branch</th><th>Date</th><th>Status</th><th>Units</th><th>Total</th><th>Actions</th></tr></thead><tbody><?php if(!$orders):?><tr><td colspan="8" class="empty">No purchase orders found.</td></tr><?php else:foreach($orders as $o):?><tr><td><strong><?=admin_h($o['po_number']?:('#'.$o['id']))?></strong><div style="font-size:11px;color:#788495">by <?=admin_h($o['created_name']?:'Unknown')?></div></td><td><?=admin_h($o['supplier_name'])?></td><td><?=admin_h($o['branch_name'])?></td><td><?=admin_h(date('d M Y H:i',strtotime($o['po_date'])))?></td><td><span class="badge <?=admin_h($o['status'])?>"><?=ucfirst($o['status'])?></span></td><td><?=$o['received_units']?> / <?=$o['ordered_units']?></td><td><?=admin_money($o['total_cost'])?></td><td><div style="display:flex;gap:6px;flex-wrap:wrap"><a class="btn light" href="?view=<?=$o['id']?>">View</a><?php if(in_array($o['status'],['ordered','partial'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="receive_po"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="btn primary" onclick="return confirm('Receive all outstanding quantities for this purchase order? Stock will be increased.')">Receive</button></form><form method="post"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="cancel_po"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="btn danger" onclick="return confirm('Cancel this purchase order?')">Cancel</button></form><?php endif;?></div></td></tr><?php endforeach;endif;?></tbody></table></div></div>
<?php if($view):?><div class="card"><div class="head"><h2><?=admin_h($view['po_number']?:('#'.$view['id']))?></h2><a class="btn light" href="purchase_orders.php">Close</a></div><div class="body"><div class="detail-grid"><div class="detail"><small>Supplier</small><strong><?=admin_h($view['supplier_name'])?></strong><small><?=admin_h($view['supplier_phone']?:'')?></small></div><div class="detail"><small>Branch</small><strong><?=admin_h($view['branch_name'])?></strong></div><div class="detail"><small>Status</small><strong><?=ucfirst($view['status'])?></strong></div><div class="detail"><small>Total</small><strong><?=admin_money($view['total_cost'])?></strong></div></div><div class="table-wrap" style="margin-top:18px"><table class="table" style="min-width:700px"><thead><tr><th>Product</th><th>Barcode</th><th>Ordered</th><th>Received</th><th>Unit Cost</th><th>Line Total</th><th>Expiry</th><th>Batch</th></tr></thead><tbody><?php foreach($viewItems as $it):?><tr><td><?=admin_h($it['item_name'])?></td><td><?=admin_h($it['barcode']?:'—')?></td><td><?=$it['quantity']?></td><td><?=$it['qty_received']?></td><td><?=admin_money($it['unit_price'])?></td><td><?=admin_money($it['unit_price']*$it['quantity'])?></td><td><?=admin_h($it['expiry_date']?:'—')?></td><td><?=admin_h($it['batch_no']?:'—')?></td></tr><?php endforeach;?></tbody></table></div></div></div><?php endif;?>
<script>
const products=<?=json_encode($products,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;let row=0;
function addItem(){const wrap=document.getElementById('items'),el=document.createElement('div');el.className='item-row';el.dataset.row=row++;let opts='<option value="">Select product</option>';products.forEach(p=>opts+=`<option value="${p.id}" data-cost="${Number(p.cost||0)}">${esc(p.item_name)}${p.barcode?' — '+esc(p.barcode):''}</option>`);el.innerHTML=`<select name="product_id[]" required onchange="calc()">${opts}</select><input type="number" name="quantity[]" min="1" value="1" required oninput="calc()"><input type="number" name="unit_price[]" min="0" step="0.01" value="0.00" required oninput="calc()"><button type="button" class="remove" onclick="this.parentElement.remove();calc()">×</button>`;wrap.appendChild(el);calc()}
function calc(){let total=0;document.querySelectorAll('#items .item-row').forEach(r=>{let q=Number(r.querySelector('[name="quantity[]"]').value||0),p=Number(r.querySelector('[name="unit_price[]"]').value||0);total+=q*p});document.getElementById('grand').textContent='K'+total.toFixed(2)}
function esc(s){return String(s).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
addItem();
</script></main></div>
