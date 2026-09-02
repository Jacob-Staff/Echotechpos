<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

function sp_e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function sp_bind(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as &$value) $refs[] = &$value;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
function sp_rows(mysqli $db, string $sql, string $types = '', array $params = []): array {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($types !== '') {
        $bind = $params;
        sp_bind($stmt, $types, $bind);
    }
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}
function sp_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array {
    $rows = sp_rows($db, $sql, $types, $params);
    return $rows[0] ?? null;
}
function sp_csrf(): string {
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['admin_csrf'];
}
function sp_check_csrf(): void {
    if (!hash_equals((string)($_SESSION['admin_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Security token expired. Please refresh the page and try again.');
    }
}

$ph = sp_one($conn, 'SELECT name FROM pharmacies WHERE id=? LIMIT 1', 'i', [$pharmacy_id]);
$pharmacy_name = $ph['name'] ?? 'PHARMACY POS';
$branches = sp_rows($conn, 'SELECT id,branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name', 'i', [$pharmacy_id]);
$branch_count = count($branches);

$csrf = sp_csrf();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        sp_check_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_supplier') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $contact = trim((string)($_POST['contact_person'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $branch_id = (int)($_POST['branch_id'] ?? 0);

            if ($name === '') throw new RuntimeException('Supplier name is required.');
            if ($branch_id <= 0 || !sp_one($conn, 'SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1', 'ii', [$branch_id, $pharmacy_id])) {
                throw new RuntimeException('Please select a valid active branch.');
            }

            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,branch_id=? WHERE id=? AND pharmacy_id=?');
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('sssssiii', $name, $contact, $phone, $email, $address, $branch_id, $id, $pharmacy_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
                $notice = 'Supplier updated successfully.';
            } else {
                $stmt = $conn->prepare('INSERT INTO suppliers(pharmacy_id,name,contact_person,phone,email,address,branch_id) VALUES(?,?,?,?,?,?,?)');
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('isssssi', $pharmacy_id, $name, $contact, $phone, $email, $address, $branch_id);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
                $notice = 'Supplier added successfully.';
            }
        }

        if ($action === 'delete_supplier') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid supplier.');

            $exists = sp_one($conn, 'SELECT id FROM suppliers WHERE id=? AND pharmacy_id=? LIMIT 1', 'ii', [$id, $pharmacy_id]);
            if (!$exists) throw new RuntimeException('Supplier not found.');

            $legacy = sp_one($conn, 'SELECT COUNT(*) c FROM purchase_order WHERE supplier_id=? AND pharmacy_id=?', 'ii', [$id, $pharmacy_id]);
            $modern = sp_one($conn, 'SELECT COUNT(*) c FROM purchase_orders WHERE supplier_id=? AND pharmacy_id=?', 'ii', [$id, $pharmacy_id]);
            if ((int)($legacy['c'] ?? 0) > 0 || (int)($modern['c'] ?? 0) > 0) {
                throw new RuntimeException('This supplier has purchase-order history and cannot be deleted. Edit the supplier instead.');
            }

            $stmt = $conn->prepare('DELETE FROM suppliers WHERE id=? AND pharmacy_id=?');
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('ii', $id, $pharmacy_id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $notice = 'Supplier deleted successfully.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$branch_id = (int)($_GET['branch_id'] ?? 0);
if ($branch_id > 0 && !sp_one($conn, 'SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1', 'ii', [$branch_id, $pharmacy_id])) {
    $branch_id = 0;
}

$where = 's.pharmacy_id=?';
$types = 'i';
$params = [$pharmacy_id];

if ($branch_id > 0) {
    $where .= ' AND s.branch_id=?';
    $types .= 'i';
    $params[] = $branch_id;
}
if ($search !== '') {
    $where .= ' AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.address LIKE ?)';
    $types .= 'sssss';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$summary = sp_one($conn, "SELECT COUNT(*) suppliers,
    COALESCE(SUM(CASE WHEN po.supplier_id IS NOT NULL THEN po.total_cost ELSE 0 END),0) ordered_value,
    COALESCE(SUM(CASE WHEN po.status='received' THEN po.total_cost ELSE 0 END),0) received_value
    FROM suppliers s
    LEFT JOIN purchase_orders po ON po.supplier_id=s.id AND po.pharmacy_id=s.pharmacy_id AND po.status<>'cancelled'
    WHERE $where", $types, $params) ?? [];

$suppliers = sp_rows($conn, "SELECT s.id,s.name,s.contact_person,s.phone,s.email,s.address,s.branch_id,
    b.branch_name,
    COALESCE(po.po_count,0) po_count,
    COALESCE(po.po_value,0) po_value,
    COALESCE(po.received_value,0) received_value
    FROM suppliers s
    LEFT JOIN branches b ON b.id=s.branch_id AND b.pharmacy_id=s.pharmacy_id
    LEFT JOIN (
        SELECT supplier_id,pharmacy_id,
               COUNT(*) po_count,
               SUM(CASE WHEN status<>'cancelled' THEN total_cost ELSE 0 END) po_value,
               SUM(CASE WHEN status='received' THEN total_cost ELSE 0 END) received_value
        FROM purchase_orders
        GROUP BY supplier_id,pharmacy_id
    ) po ON po.supplier_id=s.id AND po.pharmacy_id=s.pharmacy_id
    WHERE $where
    ORDER BY s.name ASC", $types, $params);

$edit = null;
if (isset($_GET['edit'])) {
    $edit = sp_one($conn, 'SELECT * FROM suppliers WHERE id=? AND pharmacy_id=? LIMIT 1', 'ii', [(int)$_GET['edit'], $pharmacy_id]);
}
$user_role = function_exists('current_role') ? current_role() : 'Admin';
$user_display_name = function_exists('current_user') ? current_user() : 'Administrator';
$total_orders = (int)(sp_one($conn, 'SELECT COUNT(*) c FROM sales WHERE pharmacy_id=?', 'i', [$pharmacy_id])['c'] ?? 0);
$current_admin_page = 'suppliers.php';
$branch_label = 'All branches';
foreach ($branches as $b) if ((int)$b['id'] === $branch_id) $branch_label = $b['branch_name'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Suppliers | <?=sp_e($pharmacy_name)?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--blue:#246bfe;--bg:#f4f6f8;--border:#dfe4e9;--text:#1d252d;--muted:#718091;--green:#159a68;--orange:#e7a72e;--red:#d94d61}
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}.admin-main{margin-left:250px;min-height:100vh}.content{padding:22px 28px 35px}.head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}.head h1{font-size:29px;margin:0;font-weight:850}.head p{margin:6px 0 0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px}.btn{height:40px;padding:0 14px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.primary{background:var(--blue);border-color:var(--blue);color:#fff}.danger{background:#fff0f2;border-color:#ffd0d7;color:#bd3348}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 18px rgba(31,40,49,.06)}.kpi{padding:17px 18px;min-height:104px;border-top:3px solid var(--blue)}.kpi:nth-child(2){border-top-color:var(--green)}.kpi:nth-child(3){border-top-color:var(--orange)}.kpi:nth-child(4){border-top-color:#7658e8}.label{font-size:10px;text-transform:uppercase;font-weight:800;letter-spacing:.7px;color:#687587}.value{font-size:25px;font-weight:850;margin-top:8px}.filters{padding:16px;margin-bottom:16px}.filter-grid{display:grid;grid-template-columns:1.6fr 1fr auto;gap:10px}.field label{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.field input,.field select{width:100%;height:40px;border:1px solid #d8dee5;border-radius:7px;background:#fff;padding:0 10px}.table-card{overflow:hidden}.table-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:10px;font-weight:850}.muted{color:#8793a0}.wrap{overflow:auto}.table{width:100%;min-width:1050px;border-collapse:collapse}.table th{padding:12px 14px;background:#f6f8fa;color:#647184;font-size:10px;text-transform:uppercase;text-align:left;letter-spacing:.5px}.table td{padding:13px 14px;border-top:1px solid #edf0f3;font-size:12px}.badge{display:inline-block;border-radius:14px;padding:4px 8px;font-size:10px;font-weight:800}.good{background:#e8f7f0;color:#128255}.neutral{background:#edf3ff;color:#246bfe}.money{font-weight:850}.notice{padding:11px 14px;margin-bottom:15px;border-radius:8px;font-size:12px;font-weight:700}.ok{background:#e8f7f0;color:#176f4f;border:1px solid #c7ead9}.err{background:#fff0f2;color:#a82c40;border:1px solid #ffd0d7}.empty{text-align:center;padding:55px;color:#8b96a4}.modal{position:fixed;inset:0;background:rgba(15,23,33,.48);display:none;align-items:center;justify-content:center;padding:20px;z-index:2000}.modal.open{display:flex}.modal-box{width:min(560px,100%);background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.22)}.modal-head{padding:16px 19px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;align-items:center}.modal-head h2{margin:0;font-size:17px}.close{border:0;background:transparent;font-size:20px;color:#778392;cursor:pointer}.modal-body{padding:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.form-field.full{grid-column:1/-1}.form-field label{display:block;font-size:10px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.form-field input,.form-field select,.form-field textarea{width:100%;border:1px solid #d8dee5;border-radius:7px;padding:10px;font:inherit}.form-field textarea{min-height:80px;resize:vertical}.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}@media(max-width:900px){.admin-main{margin-left:0}.content{padding:18px 15px}.kpis{grid-template-columns:1fr 1fr}.filter-grid{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr}.form-field.full{grid-column:auto}}@media(max-width:600px){.head{align-items:flex-start;flex-direction:column}.kpis,.filter-grid{grid-template-columns:1fr}.actions{width:100%}.actions .btn{flex:1}}@media print{.admin-aside,.admin-header,.filters,.no-print,.modal{display:none!important}.admin-main{margin:0}.content{padding:0}.card{box-shadow:none}}
</style>
</head>
<body>
<?php require __DIR__.'/actions/admin_aside.php'; ?>
<div class="admin-main">
<?php require __DIR__.'/actions/admin_header.php'; ?>
<main class="content">
<div class="head">
    <div><h1><i class="fa-solid fa-truck" style="color:#246bfe"></i> Suppliers</h1><p><?=sp_e($pharmacy_name)?> â€” supplier directory â€” <?=sp_e($branch_label)?></p></div>
    <div class="actions no-print"><button class="btn primary" type="button" onclick="openSupplierModal()"><i class="fa-solid fa-plus"></i> Add Supplier</button><button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div>
</div>
<?php if($notice):?><div class="notice ok"><i class="fa-solid fa-circle-check"></i> <?=sp_e($notice)?></div><?php endif;?>
<?php if($error):?><div class="notice err"><i class="fa-solid fa-circle-exclamation"></i> <?=sp_e($error)?></div><?php endif;?>
<div class="kpis">
<div class="card kpi"><div class="label">Suppliers</div><div class="value"><?=number_format((int)($summary['suppliers']??0))?></div></div>
<div class="card kpi"><div class="label">Purchase Orders</div><div class="value"><?=number_format(array_sum(array_map(fn($x)=>(int)$x['po_count'],$suppliers)))?></div></div>
<div class="card kpi"><div class="label">Ordered Value</div><div class="value">K<?=number_format((float)($summary['ordered_value']??0),2)?></div></div>
<div class="card kpi"><div class="label">Received Value</div><div class="value">K<?=number_format((float)($summary['received_value']??0),2)?></div></div>
</div>
<form class="card filters no-print" method="get">
<div class="filter-grid">
<div class="field"><label>Search</label><input name="search" value="<?=sp_e($search)?>" placeholder="Supplier, contact, phone or email"></div>
<div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=$branch_id===(int)$b['id']?'selected':''?>><?=sp_e($b['branch_name'])?></option><?php endforeach;?></select></div>
<div class="field"><label>&nbsp;</label><button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div>
</div>
</form>
<div class="card table-card">
<div class="table-head"><span>Supplier Directory</span><span class="muted"><?=count($suppliers)?> records</span></div>
<div class="wrap"><table class="table"><thead><tr><th>#</th><th>Supplier</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Branch</th><th>Orders</th><th>Ordered Value</th><th>Received</th><th>Actions</th></tr></thead><tbody>
<?php if($suppliers):$n=1;foreach($suppliers as $s):?>
<tr><td><?=$n++?></td><td><b><?=sp_e($s['name'])?></b><?php if($s['address']):?><br><span class="muted"><?=sp_e($s['address'])?></span><?php endif;?></td><td><?=sp_e($s['contact_person']?:'â€”')?></td><td><?=sp_e($s['phone']?:'â€”')?></td><td><?=sp_e($s['email']?:'â€”')?></td><td><?=sp_e($s['branch_name']?:'â€”')?></td><td><span class="badge neutral"><?=number_format((int)$s['po_count'])?></span></td><td class="money">K<?=number_format((float)$s['po_value'],2)?></td><td class="money">K<?=number_format((float)$s['received_value'],2)?></td><td><a class="btn" href="?edit=<?=$s['id']?>"><i class="fa-solid fa-pen"></i></a> <form method="post" style="display:inline" onsubmit="return confirm('Delete this supplier? This cannot be undone.');"><input type="hidden" name="csrf" value="<?=sp_e($csrf)?>"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="btn danger" type="submit"><i class="fa-solid fa-trash"></i></button></form></td></tr>
<?php endforeach;else:?><tr><td colspan="10" class="empty">No suppliers match these filters.</td></tr><?php endif;?>
</tbody></table></div></div>
</main></div>

<div class="modal" id="supplierModal" onclick="if(event.target===this)closeSupplierModal()">
<div class="modal-box">
<div class="modal-head"><h2><?= $edit ? 'Edit Supplier' : 'Add Supplier' ?></h2><button class="close" type="button" onclick="closeSupplierModal()">&times;</button></div>
<form method="post" class="modal-body">
<input type="hidden" name="csrf" value="<?=sp_e($csrf)?>"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="id" value="<?=sp_e($edit['id']??0)?>">
<div class="form-grid">
<div class="form-field full"><label>Supplier Name *</label><input required name="name" value="<?=sp_e($edit['name']??'')?>"></div>
<div class="form-field"><label>Contact Person</label><input name="contact_person" value="<?=sp_e($edit['contact_person']??'')?>"></div>
<div class="form-field"><label>Phone</label><input name="phone" value="<?=sp_e($edit['phone']??'')?>"></div>
<div class="form-field"><label>Email</label><input type="email" name="email" value="<?=sp_e($edit['email']??'')?>"></div>
<div class="form-field"><label>Branch *</label><select required name="branch_id"><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=((int)($edit['branch_id']??$branch_id)===(int)$b['id'])?'selected':''?>><?=sp_e($b['branch_name'])?></option><?php endforeach;?></select></div>
<div class="form-field full"><label>Address</label><textarea name="address"><?=sp_e($edit['address']??'')?></textarea></div>
</div>
<div class="modal-actions"><a class="btn" href="suppliers.php">Cancel</a><button class="btn primary" type="submit"><i class="fa-solid fa-save"></i> Save Supplier</button></div>
</form></div></div>
<?php if($edit):?><script>document.getElementById('supplierModal').classList.add('open');</script><?php endif;?>
<script>
function openSupplierModal(){document.getElementById('supplierModal').classList.add('open')}
function closeSupplierModal(){document.getElementById('supplierModal').classList.remove('open');history.replaceState({},'', 'suppliers.php')}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeSupplierModal()});
</script>
</body></html>
