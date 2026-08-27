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

/* ---------------------------------------------------------
   ONLINE PAYMENT METHOD NORMALIZATION

   Older online orders may contain slightly different values
   such as:
     Bank
     Bank / Transfer
     Bank Transfer
     Mobile
     Mobile Money
     Cash
     Cash on Delivery

   Always convert them to the single payment-method values
   used by the POS transaction table.
--------------------------------------------------------- */
function oo_normalize_payment_method(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $normalized = strtolower($value);
    $normalized = preg_replace('/[\s_\-\/]+/', ' ', $normalized);
    $normalized = trim((string)$normalized);

    if (
        $normalized === 'cash' ||
        $normalized === 'cod' ||
        $normalized === 'cash on delivery' ||
        $normalized === 'cash delivery'
    ) {
        return 'Online/Cash on Delivery';
    }

    if (
        $normalized === 'mobile' ||
        $normalized === 'mobile money' ||
        $normalized === 'mobilemoney'
    ) {
        return 'Online/Mobile Money';
    }

    if (
        $normalized === 'bank' ||
        $normalized === 'bank transfer' ||
        $normalized === 'bank / transfer' ||
        $normalized === 'banktransfer'
    ) {
        return 'Online/Bank Transfer';
    }

    return '';
}

$action=strtolower(trim((string)($_POST['action']??$_GET['action']??'')));

if($action==='update_status'){
    if($_SERVER['REQUEST_METHOD']!=='POST') oo_json(['success'=>false,'message'=>'Invalid request method.'],405);
    $orderId=(int)($_POST['order_id']??0);
    $status=trim((string)($_POST['status']??''));
    $allowed=['Pending','Processing','Completed','Cancelled'];
    if($orderId<=0||!in_array($status,$allowed,true)) oo_json(['success'=>false,'message'=>'Invalid order or status.'],422);

    $conn->begin_transaction();
    try{
        /*
         * Lock the order first. The Processing -> Completed transition
         * is the ONLY point at which online-order stock is deducted.
         * Pending and cancelled orders therefore do not consume stock.
         */
        $stmt=$conn->prepare("SELECT id,status FROM clients_orders WHERE id=? AND pharmacy_id=? AND branch_id=? LIMIT 1 FOR UPDATE");
        if(!$stmt) throw new RuntimeException('Unable to prepare order lookup.');
        $stmt->bind_param('iii',$orderId,$pharmacyId,$branchId);
        if(!$stmt->execute()) throw new RuntimeException('Unable to load order.');
        $order=$stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$order) throw new RuntimeException('Order not found for this branch.');

        $current=(string)$order['status'];
        $valid=false;
        if($status==='Processing') $valid=($current==='Pending');
        elseif($status==='Completed') $valid=($current==='Processing');
        elseif($status==='Cancelled') $valid=in_array($current,['Pending','Processing'],true);
        elseif($status==='Pending') $valid=false;

        if(!$valid) {
            throw new RuntimeException(
                'This order cannot be moved from '.$current.' to '.$status.'.'
            );
        }

        /* ---------------------------------------------------------
           COMPLETING AN ORDER = DEDUCT STOCK

           The order is locked above, then every product row is
           locked and checked. If ANY item cannot be fulfilled,
           the whole transaction rolls back and the order remains
           Processing. This prevents partial stock deductions.
        --------------------------------------------------------- */
        if($status==='Completed') {

            $itemsStmt=$conn->prepare("
                SELECT product_id, quantity
                FROM clients_order_items
                WHERE order_id=?
                  AND pharmacy_id=?
                  AND branch_id=?
                ORDER BY id ASC
                FOR UPDATE
            ");

            if(!$itemsStmt) {
                throw new RuntimeException('Unable to prepare order items.');
            }

            $itemsStmt->bind_param('iii',$orderId,$pharmacyId,$branchId);

            if(!$itemsStmt->execute()) {
                $itemsStmt->close();
                throw new RuntimeException('Unable to load order items.');
            }

            $itemsResult=$itemsStmt->get_result();
            $orderItems=$itemsResult->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();

            if(!$orderItems) {
                throw new RuntimeException('This order has no items to complete.');
            }

            $stockStmt=$conn->prepare("
                UPDATE store_items
                SET quantity = quantity - ?
                WHERE id = ?
                  AND pharmacy_id = ?
                  AND branch_id = ?
                  AND quantity >= ?
                LIMIT 1
            ");

            if(!$stockStmt) {
                throw new RuntimeException('Unable to prepare stock update.');
            }

            foreach($orderItems as $item) {
                $productId=(int)($item['product_id']??0);
                $qty=(int)($item['quantity']??0);

                if($productId<=0 || $qty<=0) {
                    $stockStmt->close();
                    throw new RuntimeException('This order contains an invalid product quantity.');
                }

                $stockStmt->bind_param(
                    'iiiii',
                    $qty,
                    $productId,
                    $pharmacyId,
                    $branchId,
                    $qty
                );

                if(!$stockStmt->execute() || $stockStmt->affected_rows!==1) {
                    $stockStmt->close();

                    $nameStmt=$conn->prepare("
                        SELECT item_name
                        FROM store_items
                        WHERE id=? AND pharmacy_id=? AND branch_id=?
                        LIMIT 1
                    ");

                    $productName='One of the products';
                    if($nameStmt) {
                        $nameStmt->bind_param('iii',$productId,$pharmacyId,$branchId);
                        $nameStmt->execute();
                        $nameRow=$nameStmt->get_result()->fetch_assoc();
                        if($nameRow && !empty($nameRow['item_name'])) {
                            $productName=(string)$nameRow['item_name'];
                        }
                        $nameStmt->close();
                    }

                    throw new RuntimeException(
                        $productName.' does not have enough stock to complete this order.'
                    );
                }
            }

            $stockStmt->close();
        }

        /* ---------------------------------------------------------
           COMPLETED ONLINE ORDER = RECORD REAL POS TRANSACTION

           This runs ONLY when the order moves Processing -> Completed.
           sale_date and created_at are NOW(), so Today's Transactions
           shows the actual completion time, not the order placement time.

           The stock deduction, sales record, sales items and status
           change all share this transaction. If any step fails, all
           changes roll back together.
        --------------------------------------------------------- */
        $saleId = null;

        if ($status === 'Completed') {
            $clientReference = 'ONLINE_ORDER_' . $orderId;
            $issuedBy = 'Online Customer';
            $saleTotal = round((float)($order['total_amount'] ?? 0), 2);
            $zero = 0.00;

            $orderPayment = trim((string)($order['payment_method'] ?? ''));
            $onlinePaymentMethod = oo_normalize_payment_method($orderPayment);

            if ($onlinePaymentMethod === '') {
                throw new RuntimeException(
                    'Unable to determine the online payment method for this order. ' .
                    'Stored payment method: ' . ($orderPayment !== '' ? $orderPayment : 'empty')
                );
            }

            /* Prevent duplicate sales for the same completed order. */
            $existingSaleStmt = $conn->prepare("
                SELECT id
                FROM sales
                WHERE client_reference = ?
                  AND pharmacy_id = ?
                  AND branch_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$existingSaleStmt) {
                throw new RuntimeException('Unable to verify existing online transaction.');
            }

            $existingSaleStmt->bind_param(
                'sii',
                $clientReference,
                $pharmacyId,
                $branchId
            );

            if (!$existingSaleStmt->execute()) {
                $existingSaleStmt->close();
                throw new RuntimeException('Unable to verify existing online transaction.');
            }

            $existingSale = $existingSaleStmt->get_result()->fetch_assoc();
            $existingSaleStmt->close();

            if ($existingSale) {
                $saleId = (int)$existingSale['id'];
            } else {
                $saleStmt = $conn->prepare("
                    INSERT INTO sales (
                        pharmacy_id,
                        branch_id,
                        issued_by,
                        invoice,
                        client_reference,
                        total,
                        payment,
                        user_id,
                        total_amount,
                        subtotal,
                        vat_amount,
                        payment_method,
                        amount_received,
                        change_due,
                        sale_date,
                        created_at
                    )
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ");

                if (!$saleStmt) {
                    throw new RuntimeException(
                        'Unable to prepare completed online transaction: ' . $conn->error
                    );
                }

                $saleStmt->bind_param(
                    'iisssddidddsdd',
                    $pharmacyId,
                    $branchId,
                    $issuedBy,
                    $order['order_number'],
                    $clientReference,
                    $saleTotal,
                    $saleTotal,
                    $userId,
                    $saleTotal,
                    $saleTotal,
                    $zero,
                    $onlinePaymentMethod,
                    $saleTotal,
                    $zero
                );

                if (!$saleStmt->execute()) {
                    $error = $saleStmt->error;
                    $saleStmt->close();
                    throw new RuntimeException(
                        'Unable to record completed online transaction: ' . $error
                    );
                }

                $saleId = (int)$conn->insert_id;
                $saleStmt->close();

                if ($saleId <= 0) {
                    throw new RuntimeException('Completed online transaction was not recorded.');
                }

                $saleItemStmt = $conn->prepare("
                    INSERT INTO sales_items (
                        sale_id,
                        pharmacy_id,
                        branch_id,
                        product_id,
                        quantity,
                        unit_price
                    )
                    SELECT
                        ?,
                        pharmacy_id,
                        branch_id,
                        product_id,
                        quantity,
                        price_at_purchase
                    FROM clients_order_items
                    WHERE order_id = ?
                      AND pharmacy_id = ?
                      AND branch_id = ?
                    ORDER BY id ASC
                ");

                if (!$saleItemStmt) {
                    throw new RuntimeException(
                        'Unable to prepare completed online transaction items: ' . $conn->error
                    );
                }

                $saleItemStmt->bind_param(
                    'iiii',
                    $saleId,
                    $orderId,
                    $pharmacyId,
                    $branchId
                );

                if (!$saleItemStmt->execute()) {
                    $error = $saleItemStmt->error;
                    $saleItemStmt->close();
                    throw new RuntimeException(
                        'Unable to record completed online transaction items: ' . $error
                    );
                }

                if ($saleItemStmt->affected_rows <= 0) {
                    $saleItemStmt->close();
                    throw new RuntimeException('Completed online transaction has no sale items.');
                }

                $saleItemStmt->close();
            }
        }

        /* Change status only after stock deduction AND transaction recording succeed. */
        $stmt=$conn->prepare("
            UPDATE clients_orders
            SET status=?
            WHERE id=? AND pharmacy_id=? AND branch_id=?
            LIMIT 1
        ");

        if(!$stmt) throw new RuntimeException('Unable to prepare order status update.');

        $stmt->bind_param('siii',$status,$orderId,$pharmacyId,$branchId);

        if(!$stmt->execute() || $stmt->affected_rows!==1) {
            $stmt->close();
            throw new RuntimeException('Order status was not changed.');
        }

        $stmt->close();
        $conn->commit();

        $message = $status==='Completed'
            ? 'Order completed and pharmacy stock has been deducted.'
            : 'Order updated.';

        oo_json([
            'success'=>true,
            'message'=>$message,
            'status'=>$status,
            'stock_deducted'=>($status==='Completed'),
            'sale_id'=>($status==='Completed' ? $saleId : null)
        ]);

    }catch(Throwable $e){
        try{$conn->rollback();}catch(Throwable $ignore){}
        oo_json(['success'=>false,'message'=>$e->getMessage()],422);
    }
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
