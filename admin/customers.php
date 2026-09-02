<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);
if($pharmacy_id<=0){header('Location: ../index.php?error=session_expired');exit;}

function cu_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cu_bind(mysqli_stmt $s,string $types,array &$params):void{if($types==='')return;$refs=[$types];foreach($params as &$v)$refs[]=&$v;call_user_func_array([$s,'bind_param'],$refs);}
function cu_rows(mysqli $db,string $sql,string $types='',array $params=[]):array{$s=$db->prepare($sql);if(!$s)throw new RuntimeException($db->error);if($types!==''){$p=$params;cu_bind($s,$types,$p);}if(!$s->execute())throw new RuntimeException($s->error);$r=$s->get_result();$rows=$r?$r->fetch_all(MYSQLI_ASSOC):[];$s->close();return $rows;}
function cu_one(mysqli $db,string $sql,string $types='',array $params=[]):?array{$r=cu_rows($db,$sql,$types,$params);return $r[0]??null;}
function cu_csrf():string{if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(24));return $_SESSION['admin_csrf'];}
function cu_check_csrf():void{if(!hash_equals((string)($_SESSION['admin_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('Security token expired. Please refresh the page and try again.');}

$ph=cu_one($conn,'SELECT name FROM pharmacies WHERE id=? LIMIT 1','i',[$pharmacy_id]);$pharmacy_name=$ph['name']??'PHARMACY POS';
$branches=cu_rows($conn,'SELECT id,branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name','i',[$pharmacy_id]);
$branch_count=count($branches);$csrf=cu_csrf();$notice='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        cu_check_csrf();$action=(string)($_POST['action']??'');
        if($action==='save_customer'){
            $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$phone=trim((string)($_POST['phone']??''));$email=trim((string)($_POST['email']??''));$address=trim((string)($_POST['address']??''));$branch_id=(int)($_POST['branch_id']??0);
            if($name===''||$phone==='')throw new RuntimeException('Customer name and phone are required.');
            if(!cu_one($conn,'SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1','ii',[$branch_id,$pharmacy_id]))throw new RuntimeException('Please select a valid active branch.');
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Please enter a valid email address.');
            if($id>0){
                if(!cu_one($conn,'SELECT id FROM customers WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$id,$pharmacy_id]))throw new RuntimeException('Customer not found.');
                $s=$conn->prepare('UPDATE customers SET name=?,phone=?,email=?,address=?,branch_id=? WHERE id=? AND pharmacy_id=?');if(!$s)throw new RuntimeException($conn->error);$s->bind_param('ssssiii',$name,$phone,$email,$address,$branch_id,$id,$pharmacy_id);if(!$s->execute())throw new RuntimeException($s->error);$s->close();$notice='Customer updated successfully.';
            }else{
                $s=$conn->prepare('INSERT INTO customers(pharmacy_id,branch_id,name,phone,email,address) VALUES(?,?,?,?,?,?)');if(!$s)throw new RuntimeException($conn->error);$s->bind_param('iissss',$pharmacy_id,$branch_id,$name,$phone,$email,$address);if(!$s->execute())throw new RuntimeException($s->error);$s->close();$notice='Customer added successfully.';
            }
        }elseif($action==='delete_customer'){
            $id=(int)($_POST['id']??0);
            if(!cu_one($conn,'SELECT id FROM customers WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$id,$pharmacy_id]))throw new RuntimeException('Customer not found.');
            $customer=cu_one($conn,'SELECT client_id FROM customers WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$id,$pharmacy_id]);
            if((int)($customer['client_id']??0)>0){
                $used=cu_one($conn,'SELECT COUNT(*) c FROM clients_orders WHERE client_id=? AND pharmacy_id=?','ii',[(int)$customer['client_id'],$pharmacy_id]);
                if((int)($used['c']??0)>0)throw new RuntimeException('This customer has online-order history and cannot be deleted. Edit the customer instead.');
            }
            $s=$conn->prepare('DELETE FROM customers WHERE id=? AND pharmacy_id=?');if(!$s)throw new RuntimeException($conn->error);$s->bind_param('ii',$id,$pharmacy_id);if(!$s->execute())throw new RuntimeException($s->error);$s->close();$notice='Customer deleted successfully.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$search=trim((string)($_GET['search']??''));$branch_filter=(int)($_GET['branch_id']??0);
if($branch_filter>0&&!cu_one($conn,'SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1','ii',[$branch_filter,$pharmacy_id]))$branch_filter=0;
$where='c.pharmacy_id=?';$types='i';$params=[$pharmacy_id];
if($branch_filter){$where.=' AND c.branch_id=?';$types.='i';$params[]=$branch_filter;}
if($search!==''){$where.=' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?)';$types.='ssss';$like='%'.$search.'%';array_push($params,$like,$like,$like,$like);}

$customers=cu_rows($conn,"SELECT c.id,c.name,c.phone,c.email,c.address,c.created_at,c.branch_id,c.client_id,b.branch_name,
COALESCE(o.order_count,0) order_count,COALESCE(o.completed_value,0) completed_value,COALESCE(o.total_value,0) total_value,COALESCE(o.pending_count,0) pending_count
FROM customers c
LEFT JOIN branches b ON b.id=c.branch_id AND b.pharmacy_id=c.pharmacy_id
LEFT JOIN (
    SELECT client_id,pharmacy_id,branch_id,
           COUNT(*) order_count,
           SUM(CASE WHEN status='Completed' THEN total_amount ELSE 0 END) completed_value,
           SUM(total_amount) total_value,
           SUM(CASE WHEN status IN ('Pending','Processing') THEN 1 ELSE 0 END) pending_count
    FROM clients_orders
    GROUP BY client_id,pharmacy_id,branch_id
) o ON o.client_id=c.client_id AND o.pharmacy_id=c.pharmacy_id AND o.branch_id=c.branch_id
WHERE $where ORDER BY c.name ASC",$types,$params);

$summary=cu_one($conn,"SELECT COUNT(*) customers,COALESCE(SUM(CASE WHEN c.client_id IS NOT NULL THEN 1 ELSE 0 END),0) linked_clients,COALESCE(SUM(CASE WHEN o.order_count>0 THEN o.order_count ELSE 0 END),0) orders,COALESCE(SUM(CASE WHEN o.completed_value>0 THEN o.completed_value ELSE 0 END),0) completed_value
FROM customers c
LEFT JOIN (SELECT client_id,pharmacy_id,branch_id,COUNT(*) order_count,SUM(CASE WHEN status='Completed' THEN total_amount ELSE 0 END) completed_value FROM clients_orders GROUP BY client_id,pharmacy_id,branch_id) o ON o.client_id=c.client_id AND o.pharmacy_id=c.pharmacy_id AND o.branch_id=c.branch_id
WHERE $where",$types,$params)??[];

$edit=null;if(isset($_GET['edit']))$edit=cu_one($conn,'SELECT * FROM customers WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[(int)$_GET['edit'],$pharmacy_id]);
$view=null;$history=[];
if(isset($_GET['view'])){
    $vid=(int)$_GET['view'];
    $view=cu_one($conn,'SELECT c.*,b.branch_name,cl.full_name client_name,cl.email client_email FROM customers c LEFT JOIN branches b ON b.id=c.branch_id AND b.pharmacy_id=c.pharmacy_id LEFT JOIN clients cl ON cl.id=c.client_id WHERE c.id=? AND c.pharmacy_id=? LIMIT 1','ii',[$vid,$pharmacy_id]);
    if($view&&$view['client_id'])$history=cu_rows($conn,'SELECT order_number,total_amount,payment_method,status,order_date FROM clients_orders WHERE client_id=? AND pharmacy_id=? AND branch_id=? ORDER BY order_date DESC','iii',[(int)$view['client_id'],$pharmacy_id,(int)$view['branch_id']]);
}
$user_role=function_exists('current_role')?current_role():'Admin';$user_display_name=function_exists('current_user')?current_user():'Administrator';$total_orders=(int)(cu_one($conn,'SELECT COUNT(*) c FROM sales WHERE pharmacy_id=?','i',[$pharmacy_id])['c']??0);$current_admin_page='customers.php';$branch_label='All branches';foreach($branches as $b)if((int)$b['id']===$branch_filter)$branch_label=$b['branch_name'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Customers | <?=cu_e($pharmacy_name)?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--blue:#246bfe;--bg:#f4f6f8;--border:#dfe4e9;--text:#1d252d;--muted:#718091;--green:#159a68;--orange:#e7a72e;--red:#d94d61;--purple:#7658e8}*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}.admin-main{margin-left:250px;min-height:100vh}.content{padding:22px 28px 35px}.head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}.head h1{font-size:29px;margin:0;font-weight:850}.head p{margin:6px 0 0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px}.btn{height:40px;padding:0 14px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.primary{background:var(--blue);border-color:var(--blue);color:#fff}.danger{background:#fff0f2;border-color:#ffd0d7;color:#bd3348}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 18px rgba(31,40,49,.06)}.kpi{padding:17px 18px;min-height:104px;border-top:3px solid var(--blue)}.kpi:nth-child(2){border-top-color:var(--green)}.kpi:nth-child(3){border-top-color:var(--orange)}.kpi:nth-child(4){border-top-color:var(--purple)}.label{font-size:10px;text-transform:uppercase;font-weight:800;letter-spacing:.7px;color:#687587}.value{font-size:25px;font-weight:850;margin-top:8px}.filters{padding:16px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1.6fr 1fr auto;gap:10px}.field label{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.field input,.field select{width:100%;height:40px;border:1px solid #d8dee5;border-radius:7px;background:#fff;padding:0 10px}.table-card{overflow:hidden}.table-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:10px;font-weight:850}.muted{color:#8793a0}.wrap{overflow:auto}.table{width:100%;min-width:1000px;border-collapse:collapse}.table th{padding:12px 14px;background:#f6f8fa;color:#647184;font-size:10px;text-transform:uppercase;text-align:left;letter-spacing:.5px}.table td{padding:13px 14px;border-top:1px solid #edf0f3;font-size:12px}.badge{display:inline-block;border-radius:14px;padding:4px 8px;font-size:10px;font-weight:800}.good{background:#e8f7f0;color:#128255}.neutral{background:#edf3ff;color:#246bfe}.pending{background:#fff6df;color:#a46a00}.money{font-weight:850}.notice{padding:11px 14px;margin-bottom:15px;border-radius:8px;font-size:12px;font-weight:700}.ok{background:#e8f7f0;color:#176f4f;border:1px solid #c7ead9}.err{background:#fff0f2;color:#a82c40;border:1px solid #ffd0d7}.empty{text-align:center;padding:55px;color:#8b96a4}.modal{position:fixed;inset:0;background:rgba(15,23,33,.48);display:none;align-items:center;justify-content:center;padding:20px;z-index:2000}.modal.open{display:flex}.modal-box{width:min(700px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.22)}.modal-head{padding:16px 19px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;align-items:center}.modal-head h2{margin:0;font-size:17px}.close{border:0;background:transparent;font-size:20px;color:#778392;cursor:pointer}.modal-body{padding:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}.form-field label{display:block;font-size:10px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.form-field input,.form-field select,.form-field textarea{width:100%;border:1px solid #d8dee5;border-radius:7px;padding:10px;font:inherit}.form-field textarea{min-height:80px;resize:vertical}.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}.detail{background:#f7f9fb;border:1px solid #e8edf2;border-radius:8px;padding:11px}.detail span{display:block;font-size:9px;text-transform:uppercase;color:#7b8794;font-weight:800}.detail b{display:block;margin-top:5px;font-size:13px}.history{width:100%;border-collapse:collapse}.history th,.history td{padding:10px;border-bottom:1px solid #edf0f3;text-align:left;font-size:11px}.history th{background:#f6f8fa;color:#687587;text-transform:uppercase;font-size:9px}@media(max-width:900px){.admin-main{margin-left:0}.content{padding:18px 15px}.kpis{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr 1fr}.form-grid,.detail-grid{grid-template-columns:1fr}}@media(max-width:600px){.head{align-items:flex-start;flex-direction:column}.actions{width:100%}.actions .btn{flex:1}.kpis,.grid{grid-template-columns:1fr}.full{grid-column:auto}}@media print{.admin-aside,.admin-header,.filters,.no-print,.modal{display:none!important}.admin-main{margin:0}.content{padding:0}.card{box-shadow:none}}
</style>
</head>
<body>
<?php require __DIR__.'/actions/admin_aside.php'; ?>
<div class="admin-main">
<?php require __DIR__.'/actions/admin_header.php'; ?>
<main class="content">
<div class="head"><div><h1><i class="fa-solid fa-user-group" style="color:#246bfe"></i> Customers</h1><p><?=cu_e($pharmacy_name)?> â€” customer directory â€” <?=cu_e($branch_label)?></p></div><div class="actions no-print"><button class="btn primary" type="button" onclick="openCustomerModal()"><i class="fa-solid fa-plus"></i> Add Customer</button><button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div></div>
<?php if($notice):?><div class="notice ok"><i class="fa-solid fa-circle-check"></i> <?=cu_e($notice)?></div><?php endif;?><?php if($error):?><div class="notice err"><i class="fa-solid fa-circle-exclamation"></i> <?=cu_e($error)?></div><?php endif;?>
<div class="kpis"><div class="card kpi"><div class="label">Customers</div><div class="value"><?=number_format((int)($summary['customers']??0))?></div></div><div class="card kpi"><div class="label">Linked Online Accounts</div><div class="value"><?=number_format((int)($summary['linked_clients']??0))?></div></div><div class="card kpi"><div class="label">Online Orders</div><div class="value"><?=number_format((int)($summary['orders']??0))?></div></div><div class="card kpi"><div class="label">Completed Spend</div><div class="value">K<?=number_format((float)($summary['completed_value']??0),2)?></div></div></div>
<form class="card filters no-print" method="get"><div class="grid"><div class="field"><label>Search</label><input name="search" value="<?=cu_e($search)?>" placeholder="Name, phone, email or address"></div><div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=$branch_filter===(int)$b['id']?'selected':''?>><?=cu_e($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field"><label>&nbsp;</label><button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div></div></form>
<div class="card table-card"><div class="table-head"><span>Customer Directory</span><span class="muted"><?=count($customers)?> records</span></div><div class="wrap"><table class="table"><thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Email</th><th>Branch</th><th>Online Account</th><th>Orders</th><th>Completed Spend</th><th>Pending</th><th>Actions</th></tr></thead><tbody>
<?php if($customers):$n=1;foreach($customers as $c):?><tr><td><?=$n++?></td><td><b><?=cu_e($c['name'])?></b><?php if($c['address']):?><br><span class="muted"><?=cu_e($c['address'])?></span><?php endif;?></td><td><?=cu_e($c['phone'])?></td><td><?=cu_e($c['email']?:'â€”')?></td><td><?=cu_e($c['branch_name']?:'â€”')?></td><td><?php if($c['client_id']):?><span class="badge good">Linked</span><?php else:?><span class="badge neutral">Local</span><?php endif;?></td><td><a class="badge neutral" href="?view=<?=$c['id']?>"><?=number_format((int)$c['order_count'])?></a></td><td class="money">K<?=number_format((float)$c['completed_value'],2)?></td><td><?php if((int)$c['pending_count']>0):?><span class="badge pending"><?=number_format((int)$c['pending_count'])?></span><?php else:?>â€”<?php endif;?></td><td><a class="btn" href="?view=<?=$c['id']?>"><i class="fa-solid fa-eye"></i></a> <a class="btn" href="?edit=<?=$c['id']?>"><i class="fa-solid fa-pen"></i></a> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=cu_e($csrf)?>"><input type="hidden" name="action" value="delete_customer"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn danger" type="submit" onclick="return confirm('Delete this customer? This cannot be undone.');"><i class="fa-solid fa-trash"></i></button></form></td></tr><?php endforeach;else:?><tr><td colspan="10" class="empty">No customers match these filters.</td></tr><?php endif;?></tbody></table></div></div>
</main></div>

<div class="modal" id="customerModal" onclick="if(event.target===this)closeCustomerModal()"><div class="modal-box"><div class="modal-head"><h2><?= $edit ? 'Edit Customer' : 'Add Customer' ?></h2><button class="close" type="button" onclick="closeCustomerModal()">&times;</button></div><form method="post" class="modal-body"><input type="hidden" name="csrf" value="<?=cu_e($csrf)?>"><input type="hidden" name="action" value="save_customer"><input type="hidden" name="id" value="<?=cu_e($edit['id']??0)?>"><div class="form-grid"><div class="form-field full"><label>Customer Name *</label><input required name="name" value="<?=cu_e($edit['name']??'')?>"></div><div class="form-field"><label>Phone *</label><input required name="phone" value="<?=cu_e($edit['phone']??'')?>"></div><div class="form-field"><label>Email</label><input type="email" name="email" value="<?=cu_e($edit['email']??'')?>"></div><div class="form-field"><label>Branch *</label><select required name="branch_id"><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=((int)($edit['branch_id']??$branch_filter)===(int)$b['id'])?'selected':''?>><?=cu_e($b['branch_name'])?></option><?php endforeach;?></select></div><div class="form-field full"><label>Address</label><textarea name="address"><?=cu_e($edit['address']??'')?></textarea></div></div><div class="modal-actions"><a class="btn" href="customers.php">Cancel</a><button class="btn primary" type="submit"><i class="fa-solid fa-save"></i> Save Customer</button></div></form></div></div>

<?php if($view):?><div class="modal open" id="viewModal" onclick="if(event.target===this)closeViewModal()"><div class="modal-box"><div class="modal-head"><h2><?=cu_e($view['name'])?></h2><button class="close" type="button" onclick="closeViewModal()">&times;</button></div><div class="modal-body"><div class="detail-grid"><div class="detail"><span>Phone</span><b><?=cu_e($view['phone'])?></b></div><div class="detail"><span>Email</span><b><?=cu_e($view['email']?:'â€”')?></b></div><div class="detail"><span>Branch</span><b><?=cu_e($view['branch_name']?:'â€”')?></b></div></div><?php if($view['client_id']):?><h3 style="font-size:14px;margin:5px 0 12px">Online Order History</h3><?php if($history):?><div class="wrap"><table class="history"><thead><tr><th>Order</th><th>Date</th><th>Payment</th><th>Status</th><th>Total</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?=cu_e($h['order_number'])?></td><td><?=cu_e(date('d M Y H:i',strtotime($h['order_date'])))?></td><td><?=cu_e($h['payment_method']?:'â€”')?></td><td><?=cu_e($h['status'])?></td><td class="money">K<?=number_format((float)$h['total_amount'],2)?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><div class="empty" style="padding:30px">No online orders recorded for this customer.</div><?php endif;?><?php else:?><div class="empty" style="padding:30px">This customer is a local POS customer and has no linked online account.</div><?php endif;?></div></div></div><?php endif;?>
<?php if($edit):?><script>document.getElementById('customerModal').classList.add('open');</script><?php endif;?>
<script>
function openCustomerModal(){document.getElementById('customerModal').classList.add('open')}
function closeCustomerModal(){document.getElementById('customerModal').classList.remove('open');history.replaceState({},'', 'customers.php')}
function closeViewModal(){location.href='customers.php'}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeCustomerModal();const v=document.getElementById('viewModal');if(v)closeViewModal()}})
</script>
</body></html>
