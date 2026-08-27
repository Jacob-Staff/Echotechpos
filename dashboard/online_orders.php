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
        /*
         * Lock the order first. The Processing -> Completed transition
         * is the ONLY point at which online-order stock is deducted.
         * Pending and cancelled orders therefore do not consume stock.
         */
        $stmt=$conn->prepare("
            SELECT
                id,
                order_number,
                total_amount,
                payment_method,
                status
            FROM clients_orders
            WHERE id=?
              AND pharmacy_id=?
              AND branch_id=?
            LIMIT 1
            FOR UPDATE
        ");
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

            /*
             * EchoTech supports exactly three online payment choices:
             *   1. Cash on Delivery
             *   2. Bank Transfer
             *   3. Mobile Money
             *
             * The order lookup above MUST select payment_method.
             * Otherwise $order['payment_method'] is always empty and
             * every completion incorrectly fails.
             */
            if ($orderPayment === 'Cash on Delivery') {
                $onlinePaymentMethod = 'Online/Cash on Delivery';
            } elseif ($orderPayment === 'Bank Transfer') {
                $onlinePaymentMethod = 'Online/Bank Transfer';
            } elseif ($orderPayment === 'Mobile Money') {
                $onlinePaymentMethod = 'Online/Mobile Money';
            } else {
                throw new RuntimeException(
                    'Unable to determine the online payment method for this order. ' .
                    'Stored payment method: ' .
                    ($orderPayment !== '' ? $orderPayment : 'empty')
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
    $stmt=$conn->prepare("
        SELECT
            co.id,
            co.client_id,
            co.order_number,
            co.total_amount,
            co.payment_method,
            co.status,
            co.order_date,
            co.pharmacy_id,
            co.branch_id,
            b.branch_name,
            c.full_name,
            c.phone,
            c.email,
            cu.address AS delivery_address
        FROM clients_orders co
        LEFT JOIN branches b
            ON b.id = co.branch_id
        LEFT JOIN clients c
            ON c.id = co.client_id
        LEFT JOIN customers cu
            ON cu.client_id = co.client_id
           AND cu.pharmacy_id = co.pharmacy_id
           AND cu.branch_id = co.branch_id
        WHERE co.id = ?
          AND co.pharmacy_id = ?
          AND co.branch_id = ?
        LIMIT 1
    ");
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
.oo-wrap{
    padding:18px;
    background:#f5f7fa;
    min-height:calc(100vh - 70px);
}
.oo-card{
    background:#fff;
    border:1px solid #e5e9ef;
    border-radius:14px;
    box-shadow:0 3px 14px rgba(15,23,42,.04);
}
.oo-title{
    font-weight:800;
    color:#17324d;
}
.oo-tabs{
    display:flex;
    gap:8px;
    overflow:auto;
    scrollbar-width:thin;
}
.oo-tab{
    border:1px solid #dfe6ed;
    background:#fff;
    color:#526173;
    border-radius:999px;
    padding:8px 14px;
    text-decoration:none;
    font-weight:700;
    font-size:13px;
    white-space:nowrap;
}
.oo-tab:hover{
    color:#17324d;
    text-decoration:none;
    border-color:#c8d5e2;
}
.oo-tab.active{
    background:#17324d;
    color:#fff;
    border-color:#17324d;
}
.oo-badge{
    padding:5px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
}
.oo-pending{background:#fff3cd;color:#8a5a00}
.oo-processing{background:#e8e9ff;color:#5147a5}
.oo-completed{background:#dcfce7;color:#166534}
.oo-cancelled{background:#fee2e2;color:#991b1b}

.oo-table th{
    font-size:11px;
    text-transform:uppercase;
    color:#7b8794;
    letter-spacing:.04em;
}
.oo-table td{vertical-align:middle}
.oo-btn{
    border:0;
    border-radius:8px;
    padding:7px 11px;
    font-weight:700;
    font-size:12px;
    cursor:pointer;
}
.oo-primary{background:#17324d;color:#fff}
.oo-success{background:#0f9d67;color:#fff}
.oo-danger{background:#c0392b;color:#fff}
.oo-btn:hover{filter:brightness(.96)}

.oo-modal{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.58);
    backdrop-filter:blur(3px);
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:9999;
}
.oo-modal.open{display:flex}

.oo-dialog{
    width:min(760px,100%);
    max-height:92vh;
    overflow:hidden;
    background:#fff;
    border-radius:20px;
    box-shadow:0 25px 70px rgba(15,23,42,.25);
    animation:ooModalIn .18s ease-out;
}
@keyframes ooModalIn{
    from{opacity:0;transform:translateY(10px) scale(.985)}
    to{opacity:1;transform:translateY(0) scale(1)}
}

.oo-modal-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:17px 20px;
    border-bottom:1px solid #edf1f5;
    background:#fbfcfe;
}
.oo-modal-order{
    display:flex;
    flex-direction:column;
    gap:4px;
}
.oo-modal-order-label{
    color:#8a96a3;
    font-size:10px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
}
.oo-modal-order-number{
    color:#17324d;
    font-size:17px;
    font-weight:850;
}
.oo-close{
    width:34px;
    height:34px;
    border:1px solid #e1e7ed;
    border-radius:50%;
    background:#fff;
    color:#596777;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:18px;
}
.oo-close:hover{
    background:#f1f4f7;
}

.oo-modal-body{
    padding:20px;
    max-height:calc(92vh - 78px);
    overflow:auto;
}

.oo-info-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    margin-bottom:18px;
}
.oo-info-box{
    border:1px solid #e7ecf1;
    border-radius:13px;
    padding:13px 14px;
    background:#fff;
}
.oo-info-box.wide{
    grid-column:1 / -1;
}
.oo-info-label{
    display:flex;
    align-items:center;
    gap:7px;
    color:#8793a0;
    font-size:10px;
    font-weight:800;
    letter-spacing:.05em;
    text-transform:uppercase;
    margin-bottom:6px;
}
.oo-info-label i{
    color:#2878e8;
    font-size:12px;
}
.oo-info-value{
    color:#243447;
    font-size:13px;
    font-weight:650;
    line-height:1.45;
    word-break:break-word;
}
.oo-address{
    min-height:44px;
    white-space:pre-wrap;
}
.oo-address.empty{
    color:#9aa5b1;
    font-weight:500;
}

.oo-items-title{
    display:flex;
    align-items:center;
    gap:8px;
    color:#17324d;
    font-size:14px;
    font-weight:850;
    margin-bottom:9px;
}
.oo-items{
    border:1px solid #e7ecf1;
    border-radius:13px;
    overflow:hidden;
}
.oo-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:13px 14px;
    border-bottom:1px solid #edf1f5;
}
.oo-item:last-child{border-bottom:0}
.oo-item-name{
    color:#26384a;
    font-size:13px;
    font-weight:750;
}
.oo-item-meta{
    margin-top:3px;
    color:#8a96a3;
    font-size:11px;
}
.oo-item-total{
    color:#138a61;
    font-size:13px;
    font-weight:850;
    white-space:nowrap;
}
.oo-total-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    margin-top:14px;
    padding:15px 16px;
    border-radius:13px;
    background:#f6f8fb;
}
.oo-total-label{
    color:#697687;
    font-size:12px;
    font-weight:700;
}
.oo-total-value{
    color:#17324d;
    font-size:19px;
    font-weight:900;
}

/* Custom confirmation */
.oo-confirm{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.60);
    backdrop-filter:blur(4px);
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    z-index:10001;
}
.oo-confirm.open{display:flex}
.oo-confirm-box{
    width:min(430px,100%);
    background:#fff;
    border-radius:20px;
    padding:24px;
    box-shadow:0 25px 70px rgba(15,23,42,.28);
    text-align:center;
    animation:ooModalIn .18s ease-out;
}
.oo-confirm-icon{
    width:54px;
    height:54px;
    margin:0 auto 13px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
}
.oo-confirm-icon.processing{
    background:#eef0ff;
    color:#5147a5;
}
.oo-confirm-icon.completed{
    background:#e8f8f0;
    color:#0f9d67;
}
.oo-confirm-icon.cancelled{
    background:#fff0ef;
    color:#c0392b;
}
.oo-confirm-title{
    color:#17324d;
    font-size:18px;
    font-weight:850;
    margin-bottom:7px;
}
.oo-confirm-text{
    color:#738091;
    font-size:13px;
    line-height:1.55;
    margin-bottom:20px;
}
.oo-confirm-order{
    color:#17324d;
    font-weight:800;
}
.oo-confirm-actions{
    display:flex;
    justify-content:center;
    gap:9px;
}
.oo-confirm-btn{
    min-width:105px;
    padding:10px 16px;
    border:0;
    border-radius:10px;
    font-size:12px;
    font-weight:800;
    cursor:pointer;
}
.oo-confirm-cancel{
    background:#eef1f4;
    color:#566475;
}
.oo-confirm-proceed.processing{
    background:#5147a5;
    color:#fff;
}
.oo-confirm-proceed.completed{
    background:#0f9d67;
    color:#fff;
}
.oo-confirm-proceed.cancelled{
    background:#c0392b;
    color:#fff;
}

.oo-loading{
    padding:42px 15px;
    text-align:center;
    color:#8793a0;
}
.oo-spinner{
    width:30px;
    height:30px;
    margin:0 auto 10px;
    border:3px solid #e5eaf0;
    border-top-color:#2878e8;
    border-radius:50%;
    animation:ooSpin .7s linear infinite;
}
@keyframes ooSpin{to{transform:rotate(360deg)}}

@media(max-width:700px){
    .oo-wrap{padding:12px}
    .oo-table thead{display:none}
    .oo-table,.oo-table tbody,.oo-table tr,.oo-table td{display:block;width:100%}
    .oo-table tr{padding:14px;border-bottom:1px solid #edf1f5}
    .oo-table td{border:0!important;padding:4px 0}
    .oo-table td:before{
        content:attr(data-label);
        display:inline-block;
        width:100px;
        color:#8793a0;
        font-size:11px;
        font-weight:700;
    }
    .oo-actions{margin-top:8px}
    .oo-info-grid{grid-template-columns:1fr}
    .oo-info-box.wide{grid-column:auto}
    .oo-modal{padding:10px}
    .oo-dialog{border-radius:17px}
    .oo-modal-body{padding:15px}
    .oo-confirm-box{padding:20px}
}
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
<!-- ORDER DETAILS MODAL -->
<div class="oo-modal" id="ooModal" aria-hidden="true">
    <div class="oo-dialog" role="dialog" aria-modal="true" aria-labelledby="ooTitle">

        <div class="oo-modal-head">
            <div class="oo-modal-order">
                <span class="oo-modal-order-label">Order details</span>
                <span class="oo-modal-order-number" id="ooTitle">Order</span>
            </div>

            <button
                type="button"
                class="oo-close"
                onclick="closeOrder()"
                aria-label="Close"
            >Ã—</button>
        </div>

        <div class="oo-modal-body" id="ooBody">
            <div class="oo-loading">
                <div class="oo-spinner"></div>
                Loading order...
            </div>
        </div>

    </div>
</div>

<!-- CUSTOM STATUS CONFIRMATION -->
<div class="oo-confirm" id="ooConfirm" aria-hidden="true">
    <div class="oo-confirm-box" role="dialog" aria-modal="true">

        <div class="oo-confirm-icon processing" id="ooConfirmIcon">
            <i class="mdi mdi-help-circle-outline"></i>
        </div>

        <div class="oo-confirm-title" id="ooConfirmTitle">
            Update order?
        </div>

        <div class="oo-confirm-text" id="ooConfirmText">
            Are you sure you want to update this order?
        </div>

        <div class="oo-confirm-actions">
            <button
                type="button"
                class="oo-confirm-btn oo-confirm-cancel"
                onclick="closeConfirm()"
            >
                Keep Order
            </button>

            <button
                type="button"
                class="oo-confirm-btn oo-confirm-proceed processing"
                id="ooConfirmProceed"
                onclick="confirmStatusChange()"
            >
                Confirm
            </button>
        </div>

    </div>
</div>

<script>
function esc(v){
    return String(v ?? '').replace(
        /[&<>"']/g,
        function(m){
            return {
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                '"':'&quot;',
                "'":'&#039;'
            }[m];
        }
    );
}

function money(v){
    return 'K' + Number(v || 0).toFixed(2);
}

function statusClass(status){
    return String(status || '').toLowerCase();
}

function closeOrder(){
    const modal = document.getElementById('ooModal');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden','true');
}

async function viewOrder(id){

    const modal = document.getElementById('ooModal');
    const body = document.getElementById('ooBody');

    modal.classList.add('open');
    modal.setAttribute('aria-hidden','false');

    body.innerHTML = `
        <div class="oo-loading">
            <div class="oo-spinner"></div>
            Loading order details...
        </div>
    `;

    try {

        const response = await fetch(
            'online_orders.php?action=order&id=' +
            encodeURIComponent(id) +
            '&_=' + Date.now(),
            {
                method:'GET',
                cache:'no-store',
                headers:{
                    'Accept':'application/json'
                }
            }
        );

        const data = await response.json();

        if(!data.success){
            throw new Error(
                data.message || 'Unable to load order.'
            );
        }

        const o = data.order || {};
        const items = Array.isArray(data.items)
            ? data.items
            : [];

        const address = String(
            o.delivery_address || ''
        ).trim();

        const payment = String(
            o.payment_method || ''
        ).trim();

        const status = String(
            o.status || ''
        ).trim();

        let itemsHtml = '';

        if(items.length){

            itemsHtml = items.map(function(i){

                const itemName =
                    i.item_name || 'Product';

                const strength =
                    i.strength || '';

                const qty =
                    Number(i.quantity || 0);

                const unitPrice =
                    Number(i.price_at_purchase || 0);

                const lineTotal =
                    unitPrice * qty;

                return `
                    <div class="oo-item">
                        <div>
                            <div class="oo-item-name">
                                ${esc(itemName)}
                            </div>

                            <div class="oo-item-meta">
                                ${strength ? esc(strength) + ' Â· ' : ''}
                                Qty ${qty} Â· ${money(unitPrice)} each
                            </div>
                        </div>

                        <div class="oo-item-total">
                            ${money(lineTotal)}
                        </div>
                    </div>
                `;
            }).join('');

        } else {

            itemsHtml = `
                <div class="oo-loading">
                    No products recorded for this order.
                </div>
            `;
        }

        const addressHtml = address
            ? esc(address)
            : '<span class="oo-address empty">No delivery address was provided.</span>';

        body.innerHTML = `

            <div class="oo-info-grid">

                <div class="oo-info-box">
                    <div class="oo-info-label">
                        <i class="mdi mdi-account-outline"></i>
                        Customer
                    </div>
                    <div class="oo-info-value">
                        ${esc(o.full_name || 'Customer')}
                    </div>
                </div>

                <div class="oo-info-box">
                    <div class="oo-info-label">
                        <i class="mdi mdi-phone-outline"></i>
                        Phone
                    </div>
                    <div class="oo-info-value">
                        ${esc(o.phone || 'â€”')}
                    </div>
                </div>

                <div class="oo-info-box">
                    <div class="oo-info-label">
                        <i class="mdi mdi-credit-card-outline"></i>
                        Payment
                    </div>
                    <div class="oo-info-value">
                        ${esc(payment || 'â€”')}
                    </div>
                </div>

                <div class="oo-info-box">
                    <div class="oo-info-label">
                        <i class="mdi mdi-progress-check"></i>
                        Status
                    </div>
                    <div class="oo-info-value">
                        <span class="oo-badge oo-${statusClass(status)}">
                            ${esc(status || 'â€”')}
                        </span>
                    </div>
                </div>

                <div class="oo-info-box">
                    <div class="oo-info-label">
                        <i class="mdi mdi-map-marker-outline"></i>
                        Delivery Address
                    </div>
                    <div class="oo-info-value oo-address">
                        ${addressHtml}
                    </div>
                </div>

                <div class="oo-info-box">
                    <div class="oo-info-label">
                        <i class="mdi mdi-store-outline"></i>
                        Branch
                    </div>
                    <div class="oo-info-value">
                        ${esc(o.branch_name || 'â€”')}
                    </div>
                </div>

                <div class="oo-info-box wide">
                    <div class="oo-info-label">
                        <i class="mdi mdi-clock-outline"></i>
                        Order Date
                    </div>
                    <div class="oo-info-value">
                        ${esc(o.order_date || 'â€”')}
                    </div>
                </div>

            </div>

            <div class="oo-items-title">
                <i class="mdi mdi-cart-outline"></i>
                Ordered Products
            </div>

            <div class="oo-items">
                ${itemsHtml}
            </div>

            <div class="oo-total-row">
                <span class="oo-total-label">
                    Order Total
                </span>
                <span class="oo-total-value">
                    ${money(o.total_amount)}
                </span>
            </div>
        `;

        document.getElementById('ooTitle').textContent =
            '#' + (o.order_number || '');

    } catch(error) {

        body.innerHTML = `
            <div class="oo-loading">
                <i
                    class="mdi mdi-alert-circle-outline"
                    style="font-size:30px;color:#c0392b;"
                ></i>

                <div style="margin-top:8px;font-weight:800;color:#26384a;">
                    Unable to load order
                </div>

                <div style="margin-top:5px;">
                    ${esc(error.message)}
                </div>
            </div>
        `;
    }
}

let pendingStatusChange = {
    id: 0,
    status: ''
};

function changeStatus(id,status){

    pendingStatusChange = {
        id: Number(id),
        status: String(status)
    };

    const confirmBox =
        document.getElementById('ooConfirm');

    const icon =
        document.getElementById('ooConfirmIcon');

    const title =
        document.getElementById('ooConfirmTitle');

    const message =
        document.getElementById('ooConfirmText');

    const proceed =
        document.getElementById('ooConfirmProceed');

    icon.className =
        'oo-confirm-icon ' +
        statusClass(status);

    proceed.className =
        'oo-confirm-btn oo-confirm-proceed ' +
        statusClass(status);

    if(status === 'Processing'){

        icon.innerHTML =
            '<i class="mdi mdi-play-circle-outline"></i>';

        title.textContent =
            'Accept this order?';

        message.innerHTML =
            'This will move the order to ' +
            '<strong>Processing</strong> so the pharmacy can prepare it.';

        proceed.textContent =
            'Accept Order';

    } else if(status === 'Completed'){

        icon.innerHTML =
            '<i class="mdi mdi-check-circle-outline"></i>';

        title.textContent =
            'Complete this order?';

        message.innerHTML =
            'The order will be marked ' +
            '<strong>Completed</strong> and the ordered stock will be deducted.';

        proceed.textContent =
            'Complete Order';

    } else if(status === 'Cancelled'){

        icon.innerHTML =
            '<i class="mdi mdi-close-circle-outline"></i>';

        title.textContent =
            'Cancel this order?';

        message.innerHTML =
            'This will permanently move the order to ' +
            '<strong>Cancelled</strong>. The order will not be completed.';

        proceed.textContent =
            'Cancel Order';

    } else {

        icon.innerHTML =
            '<i class="mdi mdi-help-circle-outline"></i>';

        title.textContent =
            'Update order?';

        message.innerHTML =
            'Change this order to <strong>' +
            esc(status) +
            '</strong>?';

        proceed.textContent =
            'Confirm';
    }

    confirmBox.classList.add('open');
    confirmBox.setAttribute('aria-hidden','false');
}

function closeConfirm(){

    const confirmBox =
        document.getElementById('ooConfirm');

    confirmBox.classList.remove('open');
    confirmBox.setAttribute('aria-hidden','true');

    pendingStatusChange = {
        id:0,
        status:''
    };
}

async function confirmStatusChange(){

    const id =
        pendingStatusChange.id;

    const status =
        pendingStatusChange.status;

    if(!id || !status){
        closeConfirm();
        return;
    }

    const proceed =
        document.getElementById('ooConfirmProceed');

    proceed.disabled = true;
    proceed.textContent = 'Updating...';

    try {

        const fd = new FormData();

        fd.append('action','update_status');
        fd.append('order_id',String(id));
        fd.append('status',status);

        const response = await fetch(
            'online_orders.php',
            {
                method:'POST',
                body:fd,
                cache:'no-store'
            }
        );

        const data =
            await response.json();

        if(!data.success){
            throw new Error(
                data.message ||
                'Unable to update order.'
            );
        }

        closeConfirm();

        /*
         * Keep the current page/filter and update the list without
         * using the browser's default alert/confirm dialogs.
         */
        window.location.reload();

    } catch(error) {

        proceed.disabled = false;

        if(status === 'Processing'){
            proceed.textContent = 'Accept Order';
        } else if(status === 'Completed'){
            proceed.textContent = 'Complete Order';
        } else if(status === 'Cancelled'){
            proceed.textContent = 'Cancel Order';
        } else {
            proceed.textContent = 'Confirm';
        }

        document.getElementById('ooConfirmText').innerHTML =
            '<span style="color:#c0392b;font-weight:700;">' +
            esc(error.message) +
            '</span>';
    }
}

document.getElementById('ooModal')
    .addEventListener('click',function(e){

        if(e.target.id === 'ooModal'){
            closeOrder();
        }
    });

document.getElementById('ooConfirm')
    .addEventListener('click',function(e){

        if(e.target.id === 'ooConfirm'){
            closeConfirm();
        }
    });

document.addEventListener('keydown',function(e){

    if(e.key === 'Escape'){

        const confirmBox =
            document.getElementById('ooConfirm');

        const orderModal =
            document.getElementById('ooModal');

        if(confirmBox.classList.contains('open')){
            closeConfirm();
        } else if(orderModal.classList.contains('open')){
            closeOrder();
        }
    }
});

/*
 * Keep the existing 60-second refresh as a fallback so counts/list
 * remain fresh even if the operator leaves this page open.
 */
setTimeout(function(){
    location.reload();
},60000);
</script>
<?php if(file_exists('../includes/footer.php'))require_once '../includes/footer.php'; ?>
