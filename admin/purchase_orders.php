<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);
if($pharmacy_id<=0){header('Location: ../index.php?error=session_expired');exit;}

function po_e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function po_bind(mysqli_stmt $s,string $types,array &$params):void{if($types==='')return;$refs=[$types];foreach($params as &$v)$refs[]=&$v;call_user_func_array([$s,'bind_param'],$refs);}
function po_rows(mysqli $db,string $sql,string $types='',array $params=[]):array{$s=$db->prepare($sql);if(!$s)throw new RuntimeException($db->error);if($types!==''){$p=$params;po_bind($s,$types,$p);}if(!$s->execute())throw new RuntimeException($s->error);$r=$s->get_result();$rows=$r?$r->fetch_all(MYSQLI_ASSOC):[];$s->close();return $rows;}
function po_one(mysqli $db,string $sql,string $types='',array $params=[]):?array{$r=po_rows($db,$sql,$types,$params);return $r[0]??null;}
function po_csrf():string{if(empty($_SESSION['admin_csrf']))$_SESSION['admin_csrf']=bin2hex(random_bytes(24));return $_SESSION['admin_csrf'];}
function po_check_csrf():void{if(!hash_equals((string)($_SESSION['admin_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('Security token expired. Please refresh the page and try again.');}

$ph=po_one($conn,'SELECT name FROM pharmacies WHERE id=? LIMIT 1','i',[$pharmacy_id]);$pharmacy_name=$ph['name']??'PHARMACY POS';
$branches=po_rows($conn,'SELECT id,branch_name FROM branches WHERE pharmacy_id=? AND is_active=1 ORDER BY branch_name','i',[$pharmacy_id]);
$suppliers=po_rows($conn,'SELECT id,name FROM suppliers WHERE pharmacy_id=? ORDER BY name','i',[$pharmacy_id]);
$branch_count=count($branches);$csrf=po_csrf();$notice='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        po_check_csrf();$action=(string)($_POST['action']??'');
        if($action==='create_po'){
            $supplier_id=(int)($_POST['supplier_id']??0);$branch_id=(int)($_POST['branch_id']??0);
            $expected=trim((string)($_POST['expected_date']??''));$product_ids=$_POST['product_id']??[];$qtys=$_POST['quantity']??[];$prices=$_POST['unit_price']??[];
            if(!po_one($conn,'SELECT id FROM suppliers WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$supplier_id,$pharmacy_id]))throw new RuntimeException('Invalid supplier.');
            if(!po_one($conn,'SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1','ii',[$branch_id,$pharmacy_id]))throw new RuntimeException('Invalid branch.');
            $items=[];$total=0.0;
            foreach($product_ids as $i=>$raw_id){
                $pid=(int)$raw_id;$q=(int)($qtys[$i]??0);$price=(float)($prices[$i]??0);
                if($pid<=0||$q<=0)continue;
                $product=po_one($conn,'SELECT id,cost FROM store_items WHERE id=? AND pharmacy_id=? AND branch_id=? AND is_active=1 LIMIT 1','iii',[$pid,$pharmacy_id,$branch_id]);
                if(!$product)continue;
                if($price<0)$price=0;
                $items[]=[$pid,$q,$price];$total+=($q*$price);
            }
            if(!$items)throw new RuntimeException('Add at least one valid product with quantity greater than zero.');
            if($expected!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expected))throw new RuntimeException('Invalid expected date.');

            $uid=(int)($_SESSION['user_id']??0);
            if($uid<=0)$uid=(int)($_SESSION['admin_id']??0);
            if($uid<=0){
                $u=po_one($conn,'SELECT id FROM users WHERE pharmacy_id=? AND role="Admin" ORDER BY id LIMIT 1','i',[$pharmacy_id]);
                $uid=(int)($u['id']??0);
            }
            if($uid<=0)throw new RuntimeException('Unable to determine the logged-in user.');

            $conn->begin_transaction();
            $po_number='PO-'.date('Ymd-His').'-'.random_int(1000,9999);$po_date=date('Y-m-d H:i:s');$status='ordered';$expected_db=$expected!==''?$expected:null;
            $s=$conn->prepare('INSERT INTO purchase_orders(po_number,pharmacy_id,supplier_id,po_date,expected_date,status,total_cost,created_by,branch_id) VALUES(?,?,?,?,?,?,?,?,?)');
            if(!$s)throw new RuntimeException($conn->error);
            $s->bind_param('siisssdii',$po_number,$pharmacy_id,$supplier_id,$po_date,$expected_db,$status,$total,$uid,$branch_id);
            if(!$s->execute())throw new RuntimeException($s->error);$po_id=$s->insert_id;$s->close();

            $it=$conn->prepare('INSERT INTO purchase_items(purchase_id,product_id,quantity,qty_received,unit_price,pharmacy_id,branch_id) VALUES(?,?,?,0,?,?,?)');
            if(!$it)throw new RuntimeException($conn->error);
            foreach($items as $item){$it->bind_param('iiidii',$po_id,$item[0],$item[1],$item[2],$pharmacy_id,$branch_id);if(!$it->execute())throw new RuntimeException($it->error);}
            $it->close();$conn->commit();$notice='Purchase order '.$po_number.' created successfully.';
        }elseif($action==='cancel_po'){
            $id=(int)($_POST['id']??0);
            $s=$conn->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=? AND pharmacy_id=? AND status IN ('draft','ordered','partial')");
            if(!$s)throw new RuntimeException($conn->error);$s->bind_param('ii',$id,$pharmacy_id);$s->execute();$s->close();$notice='Purchase order cancelled.';
        }elseif($action==='receive_po'){
            $id=(int)($_POST['id']??0);
            $po=po_one($conn,'SELECT * FROM purchase_orders WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$id,$pharmacy_id]);
            if(!$po)throw new RuntimeException('Purchase order not found.');
            if(in_array($po['status'],['received','cancelled'],true))throw new RuntimeException('This purchase order cannot be received.');
            $items=po_rows($conn,'SELECT pi.id,pi.product_id,pi.quantity,pi.qty_received,pi.pharmacy_id,pi.branch_id FROM purchase_items pi WHERE pi.purchase_id=? AND pi.pharmacy_id=?','ii',[$id,$pharmacy_id]);
            if(!$items)throw new RuntimeException('This purchase order has no line items.');

            $conn->begin_transaction();
            $received_any=false;
            foreach($items as $item){
                $ordered=(int)$item['quantity'];$already=(int)$item['qty_received'];$add=max(0,$ordered-$already);
                if($add<=0)continue;
                $u=$conn->prepare('UPDATE store_items SET quantity=quantity+?, cost=? WHERE id=? AND pharmacy_id=? AND branch_id=? AND is_active=1');
                if(!$u)throw new RuntimeException($conn->error);
                $unit=(float)po_one($conn,'SELECT unit_price FROM purchase_items WHERE id=? LIMIT 1','i',[(int)$item['id']])['unit_price'];
                $u->bind_param('idiii',$add,$unit,$item['product_id'],$pharmacy_id,$item['branch_id']);
                if(!$u->execute())throw new RuntimeException($u->error);
                if($u->affected_rows<1)throw new RuntimeException('Product '.$item['product_id'].' could not be updated in stock.');
                $u->close();

                $up=$conn->prepare('UPDATE purchase_items SET qty_received=quantity WHERE id=? AND purchase_id=? AND pharmacy_id=?');
                if(!$up)throw new RuntimeException($conn->error);$up->bind_param('iii',$item['id'],$id,$pharmacy_id);if(!$up->execute())throw new RuntimeException($up->error);$up->close();$received_any=true;
            }
            $st='received';$up=$conn->prepare('UPDATE purchase_orders SET status=? WHERE id=? AND pharmacy_id=?');if(!$up)throw new RuntimeException($conn->error);$up->bind_param('sii',$st,$id,$pharmacy_id);if(!$up->execute())throw new RuntimeException($up->error);$up->close();
            $conn->commit();$notice=$received_any?'Purchase order received and stock updated.':'All ordered quantities were already received.';
        }
    }catch(Throwable $e){if($conn->errno)@$conn->rollback();$error=$e->getMessage();}
}

$branch_filter=(int)($_GET['branch_id']??0);$supplier_filter=(int)($_GET['supplier_id']??0);$status_filter=(string)($_GET['status']??'');$search=trim((string)($_GET['search']??''));
if($branch_filter>0&&!po_one($conn,'SELECT id FROM branches WHERE id=? AND pharmacy_id=? AND is_active=1 LIMIT 1','ii',[$branch_filter,$pharmacy_id]))$branch_filter=0;
if($supplier_filter>0&&!po_one($conn,'SELECT id FROM suppliers WHERE id=? AND pharmacy_id=? LIMIT 1','ii',[$supplier_filter,$pharmacy_id]))$supplier_filter=0;
if(!in_array($status_filter,['draft','ordered','partial','received','cancelled'],true))$status_filter='';

$where='po.pharmacy_id=?';$types='i';$params=[$pharmacy_id];
if($branch_filter){$where.=' AND po.branch_id=?';$types.='i';$params[]=$branch_filter;}
if($supplier_filter){$where.=' AND po.supplier_id=?';$types.='i';$params[]=$supplier_filter;}
if($status_filter){$where.=' AND po.status=?';$types.='s';$params[]=$status_filter;}
if($search!==''){$where.=' AND (COALESCE(po.po_number,CONCAT("PO-",po.id)) LIKE ? OR s.name LIKE ?)';$types.='ss';$like='%'.$search.'%';$params[]=$like;$params[]=$like;}

$summary=po_one($conn,"SELECT COUNT(*) orders,COALESCE(SUM(CASE WHEN po.status<>'cancelled' THEN po.total_cost ELSE 0 END),0) value,COALESCE(SUM(CASE WHEN po.status IN ('ordered','partial') THEN po.total_cost ELSE 0 END),0) pending_value,COALESCE(SUM(CASE WHEN po.status='received' THEN po.total_cost ELSE 0 END),0) received_value FROM purchase_orders po WHERE $where",$types,$params)??[];
$orders=po_rows($conn,"SELECT po.id,COALESCE(NULLIF(po.po_number,''),CONCAT('PO-',po.id)) po_number,po.po_date,po.expected_date,po.status,po.total_cost,s.name supplier_name,b.branch_name,
COALESCE(NULLIF(TRIM(u.full_name),''),u.username,'Unknown') created_name,
COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi WHERE pi.purchase_id=po.id AND pi.pharmacy_id=po.pharmacy_id),0) ordered_units,
COALESCE((SELECT SUM(pi.qty_received) FROM purchase_items pi WHERE pi.purchase_id=po.id AND pi.pharmacy_id=po.pharmacy_id),0) received_units
FROM purchase_orders po
INNER JOIN suppliers s ON s.id=po.supplier_id AND s.pharmacy_id=po.pharmacy_id
LEFT JOIN branches b ON b.id=po.branch_id AND b.pharmacy_id=po.pharmacy_id
LEFT JOIN users u ON u.id=po.created_by AND u.pharmacy_id=po.pharmacy_id
WHERE $where ORDER BY po.po_date DESC,po.id DESC",$types,$params);

$view=null;$view_items=[];
if(isset($_GET['view'])){
    $view_id=(int)$_GET['view'];
    $view=po_one($conn,"SELECT po.*,COALESCE(NULLIF(po.po_number,''),CONCAT('PO-',po.id)) display_po,s.name supplier_name,b.branch_name,COALESCE(NULLIF(TRIM(u.full_name),''),u.username,'Unknown') created_name
    FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id AND s.pharmacy_id=po.pharmacy_id LEFT JOIN branches b ON b.id=po.branch_id AND b.pharmacy_id=po.pharmacy_id LEFT JOIN users u ON u.id=po.created_by AND u.pharmacy_id=po.pharmacy_id
    WHERE po.id=? AND po.pharmacy_id=? LIMIT 1",'ii',[$view_id,$pharmacy_id]);
    if($view)$view_items=po_rows($conn,"SELECT pi.*,si.item_name,si.barcode FROM purchase_items pi LEFT JOIN store_items si ON si.id=pi.product_id AND si.pharmacy_id=pi.pharmacy_id WHERE pi.purchase_id=? AND pi.pharmacy_id=? ORDER BY pi.id",'ii',[$view_id,$pharmacy_id]);
}

$products=po_rows($conn,'SELECT id,item_name,barcode,cost,branch_id FROM store_items WHERE pharmacy_id=? AND is_active=1 ORDER BY item_name','i',[$pharmacy_id]);
$user_role=function_exists('current_role')?current_role():'Admin';$user_display_name=function_exists('current_user')?current_user():'Administrator';$branch_count=count($branches);$total_orders=(int)(po_one($conn,'SELECT COUNT(*) c FROM sales WHERE pharmacy_id=?','i',[$pharmacy_id])['c']??0);$current_admin_page='purchase_orders.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purchase Orders | <?=po_e($pharmacy_name)?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--blue:#246bfe;--bg:#f4f6f8;--border:#dfe4e9;--text:#1d252d;--muted:#718091;--green:#159a68;--orange:#e7a72e;--red:#d94d61;--purple:#7658e8}*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Arial,sans-serif}.admin-main{margin-left:250px;min-height:100vh}.content{padding:22px 28px 35px}.head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}.head h1{font-size:29px;margin:0;font-weight:850}.head p{margin:6px 0 0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px}.btn{height:40px;padding:0 14px;border:1px solid var(--border);border-radius:8px;background:#fff;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.primary{background:var(--blue);border-color:var(--blue);color:#fff}.danger{background:#fff0f2;border-color:#ffd0d7;color:#bd3348}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:16px}.card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 18px rgba(31,40,49,.06)}.kpi{padding:17px 18px;min-height:104px;border-top:3px solid var(--blue)}.kpi:nth-child(2){border-top-color:var(--orange)}.kpi:nth-child(3){border-top-color:var(--red)}.kpi:nth-child(4){border-top-color:var(--green)}.label{font-size:10px;text-transform:uppercase;font-weight:800;letter-spacing:.7px;color:#687587}.value{font-size:25px;font-weight:850;margin-top:8px}.filters{padding:16px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr 1.4fr auto;gap:10px}.field label{display:block;font-size:9px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.field input,.field select{width:100%;height:40px;border:1px solid #d8dee5;border-radius:7px;background:#fff;padding:0 10px}.table-card{overflow:hidden}.table-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:10px;font-weight:850}.muted{color:#8793a0}.wrap{overflow:auto}.table{width:100%;min-width:1100px;border-collapse:collapse}.table th{padding:12px 14px;background:#f6f8fa;color:#647184;font-size:10px;text-transform:uppercase;text-align:left;letter-spacing:.5px}.table td{padding:12px 14px;border-top:1px solid #edf0f3;font-size:12px}.badge{display:inline-block;border-radius:14px;padding:4px 8px;font-size:10px;font-weight:800}.draft{background:#edf1f5;color:#5d6977}.ordered{background:#edf3ff;color:#246bfe}.partial{background:#fff6df;color:#a46a00}.received{background:#e8f7f0;color:#128255}.cancelled{background:#fff0f2;color:#bd3348}.money{font-weight:850}.notice{padding:11px 14px;margin-bottom:15px;border-radius:8px;font-size:12px;font-weight:700}.ok{background:#e8f7f0;color:#176f4f;border:1px solid #c7ead9}.err{background:#fff0f2;color:#a82c40;border:1px solid #ffd0d7}.empty{text-align:center;padding:55px;color:#8b96a4}.modal{position:fixed;inset:0;background:rgba(15,23,33,.48);display:none;align-items:center;justify-content:center;padding:18px;z-index:2000}.modal.open{display:flex}.modal-box{width:min(900px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.22)}.modal-head{padding:16px 19px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;align-items:center}.modal-head h2{margin:0;font-size:17px}.close{border:0;background:transparent;font-size:20px;color:#778392;cursor:pointer}.modal-body{padding:19px}.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}.form-field label{display:block;font-size:10px;text-transform:uppercase;font-weight:800;color:#687587;margin-bottom:6px}.form-field input,.form-field select{width:100%;height:40px;border:1px solid #d8dee5;border-radius:7px;background:#fff;padding:0 10px;font:inherit}.line-card{margin-top:16px;border:1px solid #e4e9ee;border-radius:10px;overflow:hidden}.line-head,.line{display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;padding:9px}.line-head{background:#f6f8fa;font-size:10px;text-transform:uppercase;font-weight:800;color:#687587}.line{border-top:1px solid #edf0f3}.line input,.line select{width:100%;height:38px;border:1px solid #d8dee5;border-radius:7px;padding:0 8px}.remove-line{height:38px;width:40px;border:1px solid #ffd0d7;background:#fff0f2;color:#bd3348;border-radius:7px}.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:15px}.detail{background:#f7f9fb;border:1px solid #e8edf2;border-radius:8px;padding:11px}.detail span{display:block;font-size:9px;text-transform:uppercase;color:#7b8794;font-weight:800}.detail b{display:block;margin-top:5px;font-size:13px}.detail-table{width:100%;border-collapse:collapse}.detail-table th,.detail-table td{padding:10px;border-bottom:1px solid #edf0f3;text-align:left;font-size:11px}.detail-table th{background:#f6f8fa;color:#687587;text-transform:uppercase;font-size:9px}@media(max-width:1050px){.grid{grid-template-columns:1fr 1fr 1fr}.kpis{grid-template-columns:1fr 1fr}.form-grid{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:1fr 1fr}}@media(max-width:900px){.admin-main{margin-left:0}.content{padding:18px 15px}}@media(max-width:650px){.head{align-items:flex-start;flex-direction:column}.actions{width:100%;flex-wrap:wrap}.actions .btn{flex:1}.grid,.kpis,.form-grid,.detail-grid{grid-template-columns:1fr}.line,.line-head{grid-template-columns:1fr}.line-head{display:none}}@media print{.admin-aside,.admin-header,.filters,.no-print,.modal{display:none!important}.admin-main{margin:0}.content{padding:0}.card{box-shadow:none}}
</style>
</head>
<body>
<?php require __DIR__.'/actions/admin_aside.php'; ?>
<div class="admin-main">
<?php require __DIR__.'/actions/admin_header.php'; ?>
<main class="content">
<div class="head"><div><h1><i class="fa-solid fa-file-invoice-dollar" style="color:#246bfe"></i> Purchase Orders</h1><p><?=po_e($pharmacy_name)?> â€” consolidated purchasing</p></div><div class="actions no-print"><button class="btn primary" type="button" onclick="openCreateModal()"><i class="fa-solid fa-plus"></i> New Purchase Order</button><button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button></div></div>
<?php if($notice):?><div class="notice ok"><i class="fa-solid fa-circle-check"></i> <?=po_e($notice)?></div><?php endif;?><?php if($error):?><div class="notice err"><i class="fa-solid fa-circle-exclamation"></i> <?=po_e($error)?></div><?php endif;?>
<div class="kpis"><div class="card kpi"><div class="label">Purchase Orders</div><div class="value"><?=number_format((int)($summary['orders']??0))?></div></div><div class="card kpi"><div class="label">Order Value</div><div class="value">K<?=number_format((float)($summary['value']??0),2)?></div></div><div class="card kpi"><div class="label">Pending Value</div><div class="value">K<?=number_format((float)($summary['pending_value']??0),2)?></div></div><div class="card kpi"><div class="label">Received Value</div><div class="value">K<?=number_format((float)($summary['received_value']??0),2)?></div></div></div>
<form class="card filters no-print" method="get"><div class="grid"><div class="field"><label>Search</label><input name="search" value="<?=po_e($search)?>" placeholder="PO number or supplier"></div><div class="field"><label>Branch</label><select name="branch_id"><option value="0">All branches</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>" <?=$branch_filter===(int)$b['id']?'selected':''?>><?=po_e($b['branch_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Supplier</label><select name="supplier_id"><option value="0">All suppliers</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>" <?=$supplier_filter===(int)$s['id']?'selected':''?>><?=po_e($s['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach(['draft','ordered','partial','received','cancelled'] as $st):?><option value="<?=$st?>" <?=$status_filter===$st?'selected':''?>><?=ucfirst($st)?></option><?php endforeach;?></select></div><div class="field"><label>&nbsp;</label><button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button></div></div></form>
<div class="card table-card"><div class="table-head"><span>Purchase Order Register</span><span class="muted"><?=count($orders)?> records</span></div><div class="wrap"><table class="table"><thead><tr><th>#</th><th>PO Number</th><th>Supplier</th><th>Branch</th><th>Date</th><th>Expected</th><th>Status</th><th>Units</th><th>Received</th><th>Total</th><th>Created By</th><th>Actions</th></tr></thead><tbody>
<?php if($orders):$n=1;foreach($orders as $o):?><tr><td><?=$n++?></td><td><span class="badge ordered"><?=po_e($o['po_number'])?></span></td><td><b><?=po_e($o['supplier_name'])?></b></td><td><?=po_e($o['branch_name']?:'â€”')?></td><td><?=po_e(date('d M Y H:i',strtotime($o['po_date'])))?></td><td><?=po_e($o['expected_date']?date('d M Y',strtotime($o['expected_date'])):'â€”')?></td><td><span class="badge <?=po_e($o['status'])?>"><?=po_e(ucfirst($o['status']))?></span></td><td><?=number_format((int)$o['ordered_units'])?></td><td><?=number_format((int)$o['received_units'])?></td><td class="money">K<?=number_format((float)$o['total_cost'],2)?></td><td><?=po_e($o['created_name'])?></td><td><a class="btn" href="?view=<?=$o['id']?>"><i class="fa-solid fa-eye"></i></a><?php if(in_array($o['status'],['draft','ordered','partial'],true)):?> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=po_e($csrf)?>"><input type="hidden" name="action" value="receive_po"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="btn" type="submit" onclick="return confirm('Receive all outstanding quantities and update stock?');"><i class="fa-solid fa-boxes-packing"></i></button></form> <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=po_e($csrf)?>"><input type="hidden" name="action" value="cancel_po"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="btn danger" type="submit" onclick="return confirm('Cancel this purchase order?');"><i class="fa-solid fa-ban"></i></button></form><?php endif;?></td></tr><?php endforeach;else:?><tr><td colspan="12" class="empty">No purchase orders match these filters.</td></tr><?php endif;?></tbody></table></div></div>
</main></div>

<div class="modal" id="createModal" onclick="if(event.target===this)closeCreateModal()"><div class="modal-box"><div class="modal-head"><h2>New Purchase Order</h2><button class="close" type="button" onclick="closeCreateModal()">&times;</button></div><form method="post" class="modal-body" onsubmit="return validatePO()"><input type="hidden" name="csrf" value="<?=po_e($csrf)?>"><input type="hidden" name="action" value="create_po"><div class="form-grid"><div class="form-field"><label>Supplier *</label><select required name="supplier_id"><option value="">Select supplier</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=po_e($s['name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Branch *</label><select required name="branch_id" id="poBranch" onchange="filterProducts()"><option value="">Select branch</option><?php foreach($branches as $b):?><option value="<?=$b['id']?>"><?=po_e($b['branch_name'])?></option><?php endforeach;?></select></div><div class="form-field"><label>Expected Date</label><input type="date" name="expected_date"></div></div><div class="line-card"><div class="line-head"><span>Product</span><span>Quantity</span><span>Unit Cost</span><span></span></div><div id="lines"></div></div><div style="margin-top:10px"><button class="btn" type="button" onclick="addLine()"><i class="fa-solid fa-plus"></i> Add Product</button><span id="poTotal" style="float:right;font-weight:850;padding:11px">Total: K0.00</span></div><div class="modal-actions"><button class="btn" type="button" onclick="closeCreateModal()">Cancel</button><button class="btn primary" type="submit"><i class="fa-solid fa-save"></i> Create PO</button></div></form></div></div>

<?php if($view):?><div class="modal open" id="viewModal" onclick="if(event.target===this)closeViewModal()"><div class="modal-box"><div class="modal-head"><h2><?=po_e($view['display_po'])?></h2><button class="close" type="button" onclick="closeViewModal()">&times;</button></div><div class="modal-body"><div class="detail-grid"><div class="detail"><span>Supplier</span><b><?=po_e($view['supplier_name'])?></b></div><div class="detail"><span>Branch</span><b><?=po_e($view['branch_name']?:'â€”')?></b></div><div class="detail"><span>Status</span><b><?=po_e(ucfirst($view['status']))?></b></div><div class="detail"><span>Total</span><b>K<?=number_format((float)$view['total_cost'],2)?></b></div></div><div class="wrap"><table class="detail-table"><thead><tr><th>Product</th><th>Barcode</th><th>Ordered</th><th>Received</th><th>Unit Cost</th><th>Total</th><th>Batch</th><th>Expiry</th></tr></thead><tbody><?php foreach($view_items as $i):?><tr><td><?=po_e($i['item_name']?:'Product #'.$i['product_id'])?></td><td><?=po_e($i['barcode']?:'â€”')?></td><td><?=number_format((int)$i['quantity'])?></td><td><?=number_format((int)$i['qty_received'])?></td><td>K<?=number_format((float)$i['unit_price'],2)?></td><td>K<?=number_format((int)$i['quantity']*(float)$i['unit_price'],2)?></td><td><?=po_e($i['batch_no']?:'â€”')?></td><td><?=po_e($i['expiry_date']?:'â€”')?></td></tr><?php endforeach;?></tbody></table></div></div></div></div><?php endif;?>
<script>
const products=<?=json_encode($products,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
function openCreateModal(){document.getElementById('createModal').classList.add('open');if(!document.querySelector('#lines .line'))addLine()}
function closeCreateModal(){document.getElementById('createModal').classList.remove('open')}
function closeViewModal(){location.href='purchase_orders.php'}
function productOptions(){const branch=document.getElementById('poBranch').value;return '<option value="">Select product</option>'+products.filter(p=>!branch||Number(p.branch_id)===Number(branch)).map(p=>'<option value="'+p.id+'" data-cost="'+Number(p.cost||0).toFixed(2)+'">'+escapeHtml(p.item_name)+(p.barcode?' â€” '+escapeHtml(p.barcode):'')+'</option>').join('')}
function addLine(){const wrap=document.getElementById('lines');const row=document.createElement('div');row.className='line';row.innerHTML='<select required name="product_id[]" onchange="setCost(this)">'+productOptions()+'</select><input required min="1" type="number" name="quantity[]" value="1" oninput="calcTotal()"><input required min="0" step="0.01" type="number" name="unit_price[]" value="0" oninput="calcTotal()"><button class="remove-line" type="button" onclick="this.parentElement.remove();calcTotal()"><i class="fa-solid fa-trash"></i></button>';wrap.appendChild(row)}
function setCost(sel){const opt=sel.options[sel.selectedIndex];sel.parentElement.querySelector('[name="unit_price[]"]').value=opt.dataset.cost||0;calcTotal()}
function filterProducts(){document.querySelectorAll('#lines select[name="product_id[]"]').forEach(s=>{const old=s.value;s.innerHTML=productOptions();if([...s.options].some(o=>o.value===old))s.value=old});}
function calcTotal(){let t=0;document.querySelectorAll('#lines .line').forEach(r=>{t+=(Number(r.querySelector('[name="quantity[]"]').value)||0)*(Number(r.querySelector('[name="unit_price[]"]').value)||0)});document.getElementById('poTotal').textContent='Total: K'+t.toFixed(2)}
function validatePO(){if(!document.querySelector('#lines .line')){alert('Add at least one product.');return false}return true}
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeCreateModal();const v=document.getElementById('viewModal');if(v)closeViewModal()}})
</script>
</body></html>
