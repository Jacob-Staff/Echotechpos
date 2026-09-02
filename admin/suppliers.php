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


$pageTitle='Suppliers';
$csrf=admin_csrf();
$notice=''; $error='';

try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        admin_check_csrf();
        $action=$_POST['action']??'';
        if($action==='save_supplier'){
            $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $contact=trim($_POST['contact_person']??'');
            $phone=trim($_POST['phone']??''); $email=trim($_POST['email']??''); $address=trim($_POST['address']??''); $branch=(int)($_POST['branch_id']??0);
            if($name==='') throw new RuntimeException('Supplier name is required.');
            $br=admin_one($adminDb,'SELECT id FROM branches WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$branch,$adminPharmacyId]);
            if(!$br) throw new RuntimeException('Select a valid branch for this pharmacy.');
            if($id){$st=$adminDb->prepare('UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,branch_id=? WHERE id=? AND pharmacy_id=?');$st->bind_param('sssssiii',$name,$contact,$phone,$email,$address,$branch,$id,$adminPharmacyId);}
            else {$st=$adminDb->prepare('INSERT INTO suppliers(pharmacy_id,name,contact_person,phone,email,address,branch_id) VALUES(?,?,?,?,?,?,?)');$st->bind_param('isssssi',$adminPharmacyId,$name,$contact,$phone,$email,$address,$branch);}
            if(!$st||!$st->execute()) throw new RuntimeException($st?$st->error:$adminDb->error); if($st)$st->close();
            $notice=$id?'Supplier updated successfully.':'Supplier added successfully.';
        } elseif($action==='delete_supplier'){
            $id=(int)($_POST['id']??0); $used=admin_one($adminDb,'SELECT COUNT(*) c FROM purchase_orders WHERE supplier_id=? AND pharmacy_id=?','ii',[$id,$adminPharmacyId]);
            if((int)($used['c']??0)>0) throw new RuntimeException('This supplier has purchase orders and cannot be deleted. Edit the supplier instead.');
            $st=$adminDb->prepare('DELETE FROM suppliers WHERE id=? AND pharmacy_id=?');$st->bind_param('ii',$id,$adminPharmacyId);$st->execute();$st->close();$notice='Supplier deleted successfully.';
        }
    }
} catch(Throwable $e){$error=$e->getMessage();}

$search=trim($_GET['q']??''); $branchFilter=(int)($_GET['branch_id']??0);
$branches=admin_q($adminDb,'SELECT id,branch_name FROM branches WHERE pharmacy_id=? ORDER BY branch_name','i',[$adminPharmacyId]);
$where='s.pharmacy_id=?';$types='i';$vals=[$adminPharmacyId];
if($branchFilter){$where.=' AND s.branch_id=?';$types.='i';$vals[]=$branchFilter;}
if($search!==''){$where.=' AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';$types.='ssss';$like='%'.$search.'%';array_push($vals,$like,$like,$like,$like);}
$suppliers=admin_q($adminDb,"SELECT s.*,b.branch_name,
 (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id=s.id AND po.pharmacy_id=s.pharmacy_id) po_count,
 (SELECT COALESCE(SUM(po.total_cost),0) FROM purchase_orders po WHERE po.supplier_id=s.id AND po.pharmacy_id=s.pharmacy_id AND po.status<>'cancelled') po_total
 FROM suppliers s LEFT JOIN branches b ON b.id=s.branch_id AND b.pharmacy_id=s.pharmacy_id WHERE $where ORDER BY s.name ASC",$types,$vals);
$edit=null;if(isset($_GET['edit'])) $edit=admin_one($adminDb,'SELECT * FROM suppliers WHERE id=? AND pharmacy_id=?','ii',[(int)$_GET['edit'],$adminPharmacyId]);
require_once __DIR__.'/actions/admin_aside.php';
?>
<div class="admin-main"><div class="admin-header-slot"><?php require_once __DIR__.'/actions/admin_header.php'; ?></div>
<main class="admin-page">
<style>
.admin-page{padding:24px 28px 40px;background:#f5f7fb;min-height:calc(100vh - 70px);font-family:Inter,Arial,sans-serif;color:#17202a}.admin-page *{box-sizing:border-box}.ap-title{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:22px}.ap-title h1{margin:0;font-size:25px}.ap-title p{margin:5px 0 0;color:#718096;font-size:13px}.btn{border:0;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}.btn-primary{background:#17202a;color:#fff}.btn-light{background:#fff;color:#344054;border:1px solid #d9e0e8}.btn-danger{background:#fff0f0;color:#c53030;border:1px solid #ffd1d1}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{background:#fff;border:1px solid #e7ebf0;border-radius:14px;box-shadow:0 5px 20px rgba(20,30,50,.04)}.card-head{padding:17px 19px;border-bottom:1px solid #edf0f4;display:flex;justify-content:space-between;align-items:center}.card-head h2{font-size:16px;margin:0}.card-body{padding:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}.field label{font-size:12px;font-weight:700;color:#596579}.field input,.field select,.field textarea{width:100%;border:1px solid #dce2e9;border-radius:8px;padding:10px 11px;font:inherit;background:#fff}.field textarea{min-height:80px;resize:vertical}.actions{display:flex;gap:8px;margin-top:15px}.filters{padding:15px 18px;display:flex;gap:10px;flex-wrap:wrap}.filters input,.filters select{padding:10px;border:1px solid #dce2e9;border-radius:8px;background:#fff}.table-wrap{overflow:auto}.data-table{width:100%;border-collapse:collapse;min-width:760px}.data-table th,.data-table td{padding:13px 16px;border-bottom:1px solid #edf0f4;text-align:left;font-size:13px}.data-table th{background:#fafbfd;color:#687386;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.muted{color:#7b8797}.badge{padding:5px 8px;border-radius:999px;font-size:11px;font-weight:700;background:#edf7ef;color:#27753a}.mini{font-size:11px;color:#788495}.toast{padding:11px 14px;border-radius:9px;margin-bottom:15px}.ok{background:#edf8f0;color:#256b35}.err{background:#fff0f0;color:#b42318}.row-actions{display:flex;gap:7px}.empty{text-align:center;padding:35px;color:#788495}@media(max-width:1000px){.grid{grid-template-columns:1fr}}@media(max-width:700px){.admin-main{margin-left:0}.admin-page{padding:16px}.form-grid{grid-template-columns:1fr}}
</style>
<div class="ap-title"><div><h1>Suppliers</h1><p>Manage suppliers across the entire pharmacy group.</p></div><a class="btn btn-primary" href="?new=1">＋ Add Supplier</a></div>
<?php if($notice):?><div class="toast ok"><?=admin_h($notice)?></div><?php endif;?><?php if($error):?><div class="toast err"><?=admin_h($error)?></div><?php endif;?>
<div class="grid">
<div class="card"><div class="card-head"><h2><?= $edit?'Edit Supplier':'Add Supplier' ?></h2><?php if($edit):?><a class="btn btn-light" href="suppliers.php">Cancel</a><?php endif;?></div><div class="card-body"><form method="post"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><div class="form-grid"><div class="field full"><label>Supplier Name *</label><input required name="name" value="<?=admin_h($edit['name']??'')?>"></div><div class="field"><label>Contact Person</label><input name="contact_person" value="<?=admin_h($edit['contact_person']??'')?>"></div><div class="field"><label>Phone</label><input name="phone" value="<?=admin_h($edit['phone']??'')?>"></div><div class="field"><label>Email</label><input type="email" name="email" value="<?=admin_h($edit['email']??'')?>"></div><div class="field"><label>Branch</label><select name="branch_id" required><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=((int)($edit['branch_id']??0)===(int)$b['id'])?'selected':''?>><?=admin_h($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field full"><label>Address</label><textarea name="address"><?=admin_h($edit['address']??'')?></textarea></div></div><div class="actions"><button class="btn btn-primary" type="submit"><?=$edit?'Update Supplier':'Save Supplier'?></button></div></form></div></div>
<div class="card"><div class="card-head"><h2>Supplier Directory</h2><span class="mini"><?=count($suppliers)?> supplier(s)</span></div><form class="filters" method="get"><input name="q" placeholder="Search supplier, phone, email..." value="<?=admin_h($search)?>"><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=($branchFilter===$b['id'])?'selected':''?>><?=admin_h($b['branch_name'])?></option><?php endforeach;?></select><button class="btn btn-primary">Filter</button><a class="btn btn-light" href="suppliers.php">Reset</a></form><div class="table-wrap"><table class="data-table"><thead><tr><th>Supplier</th><th>Contact</th><th>Branch</th><th>Purchase Orders</th><th>PO Value</th><th>Actions</th></tr></thead><tbody><?php if(!$suppliers):?><tr><td colspan="6" class="empty">No suppliers found.</td></tr><?php else: foreach($suppliers as $s):?><tr><td><strong><?=admin_h($s['name'])?></strong><div class="mini"><?=admin_h($s['email']?:'No email')?></div></td><td><?=admin_h($s['contact_person']?:'—')?><div class="mini"><?=admin_h($s['phone']?:'—')?></div></td><td><?=admin_h($s['branch_name']?:'—')?></td><td><?=$s['po_count']?></td><td><?=admin_money($s['po_total'])?></td><td><div class="row-actions"><a class="btn btn-light" href="?edit=<?=$s['id']?>">Edit</a><?php if((int)$s['po_count']===0):?><form method="post" onsubmit="return confirm('Delete this supplier?');"><input type="hidden" name="csrf" value="<?=admin_h($csrf)?>"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="btn btn-danger">Delete</button></form><?php endif;?></div></td></tr><?php endforeach; endif;?></tbody></table></div></div></div>
</main></div>
