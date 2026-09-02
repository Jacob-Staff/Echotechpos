<?php
/**
 * EchoTech POS - Admin page bootstrap
 * Uses the SAME authentication and database connection as the Admin Dashboard.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/conn.php';

require_admin();

$adminDb = $conn;
$adminPharmacyId = (int) (current_pharmacy() ?? 0);

if ($adminPharmacyId <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

$user_role = current_role() ?? 'Admin';
$user_display_name = current_user();

$pharmacy_name = 'EchoTech POS';
$stmt = $conn->prepare('SELECT name FROM pharmacies WHERE id = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $adminPharmacyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['name'])) {
        $pharmacy_name = $row['name'];
    }
    $stmt->close();
}

$branch_count = 0;
$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM branches WHERE pharmacy_id = ?');
if ($stmt) {
    $stmt->bind_param('i', $adminPharmacyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $branch_count = (int) ($row['c'] ?? 0);
    $stmt->close();
}

$total_orders = 0;
$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM sales WHERE pharmacy_id = ?');
if ($stmt) {
    $stmt->bind_param('i', $adminPharmacyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $total_orders = (int) ($row['c'] ?? 0);
    $stmt->close();
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


$pageTitle='Customers';$csrf=admin_csrf();$notice='';$error='';
try{if($_SERVER['REQUEST_METHOD']==='POST'){admin_check_csrf();$a=$_POST['action']??'';if($a==='save_customer'){$id=(int)$_POST['id'];$name=trim($_POST['name']??'');$phone=trim($_POST['phone']??'');$email=trim($_POST['email']??'');$address=trim($_POST['address']??'');$branch=(int)$_POST['branch_id'];if($name===''||$phone==='')throw new RuntimeException('Customer name and phone are required.');if(!admin_one($adminDb,'SELECT id FROM branches WHERE id=? AND pharmacy_id=?','ii',[$branch,$adminPharmacyId]))throw new RuntimeException('Invalid branch.');if($id){$st=$adminDb->prepare('UPDATE customers SET name=?,phone=?,email=?,address=?,branch_id=? WHERE id=? AND pharmacy_id=?');$st->bind_param('ssssiii',$name,$phone,$email,$address,$branch,$id,$adminPharmacyId);}else{$st=$adminDb->prepare('INSERT INTO customers(pharmacy_id,branch_id,name,phone,email,address) VALUES(?,?,?,?,?,?)');$st->bind_param('iissss',$adminPharmacyId,$branch,$name,$phone,$email,$address);}if(!$st||!$st->execute())throw new RuntimeException($st?$st->error:$adminDb->error);if($st)$st->close();$notice=$id?'Customer updated successfully.':'Customer added successfully.';}elseif($a==='delete_customer'){$id=(int)$_POST['id'];$used=admin_one($adminDb,'SELECT COUNT(*) c FROM clients_orders co JOIN customers c ON c.client_id=co.client_id AND c.pharmacy_id=co.pharmacy_id WHERE c.id=? AND c.pharmacy_id=?','ii',[$id,$adminPharmacyId]);if((int)($used['c']??0)>0)throw new RuntimeException('This customer has online orders and cannot be deleted. Edit the customer instead.');$st=$adminDb->prepare('DELETE FROM customers WHERE id=? AND pharmacy_id=?');$st->bind_param('ii',$id,$adminPharmacyId);$st->execute();$st->close();$notice='Customer deleted successfully.';}}}catch(Throwable $e){$error=$e->getMessage();}
$branches=admin_q($adminDb,'SELECT id,branch_name FROM branches WHERE pharmacy_id=? ORDER BY branch_name','i',[$adminPharmacyId]);$q=trim($_GET['q']??'');$bf=(int)($_GET['branch_id']??0);$where='c.pharmacy_id=?';$types='i';$vals=[$adminPharmacyId];if($bf){$where.=' AND c.branch_id=?';$types.='i';$vals[]=$bf;}if($q!==''){$where.=' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';$types.='sss';$like='%'.$q.'%';array_push($vals,$like,$like,$like);}$customers=admin_q($adminDb,"SELECT c.*,b.branch_name,COALESCE(o.orders_count,0) orders_count,COALESCE(o.completed_value,0) completed_value,COALESCE(o.total_value,0) total_value FROM customers c LEFT JOIN branches b ON b.id=c.branch_id AND b.pharmacy_id=c.pharmacy_id LEFT JOIN (SELECT client_id,pharmacy_id,branch_id,COUNT(*) orders_count,SUM(CASE WHEN status='Completed' THEN total_amount ELSE 0 END) completed_value,SUM(total_amount) total_value FROM clients_orders GROUP BY client_id,pharmacy_id,branch_id) o ON o.client_id=c.client_id AND o.pharmacy_id=c.pharmacy_id AND o.branch_id=c.branch_id WHERE $where ORDER BY c.name ASC",$types,$vals);$edit=null;if(isset($_GET['edit']))$edit=admin_one($adminDb,'SELECT * FROM customers WHERE id=? AND pharmacy_id=?','ii',[(int)$_GET['edit'],$adminPharmacyId]);$history=null;$historyRows=[];if(isset($_GET['view'])){$vid=(int)$_GET['view'];$history=admin_one($adminDb,'SELECT c.*,b.branch_name,cl.full_name client_name FROM customers c LEFT JOIN branches b ON b.id=c.branch_id AND b.pharmacy_id=c.pharmacy_id LEFT JOIN clients cl ON cl.id=c.client_id WHERE c.id=? AND c.pharmacy_id=?','ii',[$vid,$adminPharmacyId]);if($history&&$history['client_id'])$historyRows=admin_q($adminDb,'SELECT order_number,total_amount,payment_method,status,order_date FROM clients_orders WHERE client_id=? AND pharmacy_id=? AND branch_id=? ORDER BY order_date DESC','iii',[$history['client_id'],$adminPharmacyId,$history['branch_id']]);}
require_once __DIR__.'/actions/admin_aside.php';?>
<div class="admin-main"><div class="admin-header-slot"><?php require_once __DIR__.'/actions/admin_header.php';?></div><main class="admin-page">
<style>
.admin-page{padding:24px 28px 40px;background:#f5f7fb;min-height:calc(100vh - 70px);font-family:Inter,Arial,sans-serif;color:#17202a}.admin-page *{box-sizing:border-box}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.top h1{font-size:25px;margin:0}.top p{margin:5px 0;color:#718096;font-size:13px}.btn{border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}.primary{background:#17202a;color:#fff}.light{background:#fff;color:#344054;border:1px solid #d9e0e8}.danger{background:#fff0f0;color:#c53030;border:1px solid #ffd0d0}.grid{display:grid;grid-template-columns:380px 1fr;gap:20px}.card{background:#fff;border:1px solid #e7ebf0;border-radius:14px;box-shadow:0 5px 20px rgba(20,30,50,.04);margin-bottom:20px}.head{padding:16px 19px;border-bottom:1px solid #edf0f4;display:flex;justify-content:space-between;align-items:center}.head h2{margin:0;font-size:16px}.body{padding:19px}.field{display:flex;flex-direction:column;gap:6px;margin-bottom:13px}.field label{font-size:12px;font-weight:700;color:#596579}.field input,.field select,.field textarea{border:1px solid #dce2e9;border-radius:8px;padding:10px;font:inherit}.field textarea{min-height:85px;resize:vertical}.filters{display:flex;gap:9px;flex-wrap:wrap;padding:15px 18px}.filters input,.filters select{padding:10px;border:1px solid #dce2e9;border-radius:8px;background:#fff}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:850px}.table th,.table td{padding:12px 15px;border-bottom:1px solid #edf0f4;text-align:left;font-size:13px}.table th{background:#fafbfd;color:#687386;font-size:11px;text-transform:uppercase}.mini{font-size:11px;color:#788495}.toast{padding:11px 14px;border-radius:9px;margin-bottom:15px}.ok{background:#edf8f0;color:#256b35}.err{background:#fff0f0;color:#b42318}.empty{text-align:center;padding:35px;color:#788495}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.detail{padding:11px;background:#fafbfd;border-radius:9px}.detail small{display:block;color:#788495;margin-bottom:4px}.badge{padding:5px 8px;border-radius:999px;font-size:11px;font-weight:700}.completed{background:#edf8f0;color:#27753a}.pending{background:#fff7e6;color:#9a6700}.cancelled{background:#fff0f0;color:#b42318}@media(max-width:1000px){.grid{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr 1fr}}@media(max-width:700px){.admin-main{margin-left:0}.admin-page{padding:16px}.detail-grid{grid-template-columns:1fr}}
</style>
<div class="top"><div><h1>Customers</h1><p>Manage pharmacy-group customers and their order history.</p></div><a class="btn primary" href="?new=1">ï¼‹ Add Customer</a></div><?php if($notice):?><div class="toast ok"><?=admin_h($notice)?></div><?php endif;?><?php if($error):?><div class="toast err"><?=admin_h($error)?></div><?php endif;?>
<div class="grid"><div class="card"><div class="head"><h2><?= $edit?'Edit Customer':'Add Customer' ?></h2><?php if($edit):?><a class="btn light" href="customers.php">Cancel</a><?php endif;?></div><div class="body"><form method="post"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><div class="field"><label>Full Name *</label><input required name="name" value="<?=admin_h($edit['name']??'')?>"></div><div class="field"><label>Phone *</label><input required name="phone" value="<?=admin_h($edit['phone']??'')?>"></div><div class="field"><label>Email</label><input type="email" name="email" value="<?=admin_h($edit['email']??'')?>"></div><div class="field"><label>Branch</label><select name="branch_id" required><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=((int)($edit['branch_id']??0)===(int)$b['id'])?'selected':''?>><?=admin_h($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Address</label><textarea name="address"><?=admin_h($edit['address']??'')?></textarea></div><button class="btn primary"><?=$edit?'Update Customer':'Save Customer'?></button></form></div></div>
<div class="card"><div class="head"><h2>Customer Directory</h2><span class="mini"><?=count($customers)?> customer(s)</span></div><form class="filters" method="get"><input name="q" value="<?=admin_h($q)?>" placeholder="Search name, phone or email"><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=($bf===$b['id'])?'selected':''?>><?=admin_h($b['branch_name'])?></option><?php endforeach;?></select><button class="btn primary">Filter</button><a class="btn light" href="customers.php">Reset</a></form><div class="table-wrap"><table class="table"><thead><tr><th>Customer</th><th>Contact</th><th>Branch</th><th>Orders</th><th>Completed Spend</th><th>Total Order Value</th><th>Actions</th></tr></thead><tbody><?php if(!$customers):?><tr><td colspan="7" class="empty">No customers found.</td></tr><?php else:foreach($customers as $c):?><tr><td><strong><?=admin_h($c['name'])?></strong><div class="mini">Added <?=admin_h(date('d M Y',strtotime($c['created_at'])))?></div></td><td><?=admin_h($c['phone'])?><div class="mini"><?=admin_h($c['email']?:'No email')?></div></td><td><?=admin_h($c['branch_name']?:'â€”')?></td><td><?=$c['orders_count']?></td><td><?=admin_money($c['completed_value'])?></td><td><?=admin_money($c['total_value'])?></td><td><div style="display:flex;gap:7px;flex-wrap:wrap"><a class="btn light" href="?view=<?=$c['id']?>">History</a><a class="btn light" href="?edit=<?=$c['id']?>">Edit</a><?php if((int)$c['orders_count']===0):?><form method="post" onsubmit="return confirm('Delete this customer?');"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="delete_customer"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn danger">Delete</button></form><?php endif;?></div></td></tr><?php endforeach;endif;?></tbody></table></div></div></div>
<?php if($history):?><div class="card"><div class="head"><h2>Customer History â€” <?=admin_h($history['name'])?></h2><a class="btn light" href="customers.php">Close</a></div><div class="body"><div class="detail-grid"><div class="detail"><small>Phone</small><strong><?=admin_h($history['phone'])?></strong></div><div class="detail"><small>Email</small><strong><?=admin_h($history['email']?:'â€”')?></strong></div><div class="detail"><small>Branch</small><strong><?=admin_h($history['branch_name']?:'â€”')?></strong></div><div class="detail"><small>Client Account</small><strong><?=admin_h($history['client_name']?:'Not linked')?></strong></div></div><div class="table-wrap" style="margin-top:18px"><table class="table" style="min-width:700px"><thead><tr><th>Order</th><th>Date</th><th>Payment</th><th>Status</th><th>Amount</th></tr></thead><tbody><?php if(!$historyRows):?><tr><td colspan="5" class="empty">No online customer orders are linked to this customer.</td></tr><?php else:foreach($historyRows as $r):?><tr><td><?=admin_h($r['order_number'])?></td><td><?=admin_h(date('d M Y H:i',strtotime($r['order_date'])))?></td><td><?=admin_h($r['payment_method'])?></td><td><span class="badge <?=strtolower($r['status'])?>"><?=admin_h($r['status'])?></span></td><td><?=admin_money($r['total_amount'])?></td></tr><?php endforeach;endif;?></tbody></table></div></div></div><?php endif;?>
</main></div>
