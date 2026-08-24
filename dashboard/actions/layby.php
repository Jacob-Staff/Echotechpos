<?php
declare(strict_types=1);

ini_set('display_errors','0');
error_reporting(E_ALL);

if(session_status()===PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id=(int)($_SESSION['pharmacy_id']??0);
$branch_id=(int)($_SESSION['branch_id']??0);
$user_id=(int)($_SESSION['user_id']??0);

if(!$pharmacy_id||!$branch_id||!$user_id){
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Session expired. Please log in again.']);
    exit;
}

$action=trim((string)($_POST['action']??$_GET['action']??''));

function out(array $data, int $code=200): never {
    http_response_code($code);
    echo json_encode($data,JSON_UNESCAPED_UNICODE);
    exit;
}
function positiveFloat($v): float { return max(0,(float)$v); }

try {
    switch($action){

    case 'products':
        $q=trim((string)($_POST['query']??''));
        if(mb_strlen($q)<2) out(['status'=>'success','products'=>[]]);

        $like='%'.$q.'%';
        $sql="SELECT id,item_name,price,barcode,quantity
              FROM store_items
              WHERE pharmacy_id=? AND branch_id=? AND is_active=1
                AND quantity>0
                AND (item_name LIKE ? OR barcode LIKE ?)
              ORDER BY CASE WHEN item_name LIKE ? THEN 0 ELSE 1 END,item_name ASC
              LIMIT 30";
        $stmt=$conn->prepare($sql);
        if(!$stmt) throw new Exception('Unable to prepare product search.');
        $stmt->bind_param('iisss',$pharmacy_id,$branch_id,$like,$like,$like);
        $stmt->execute();
        $res=$stmt->get_result();$products=[];
        while($r=$res->fetch_assoc()) $products[]=$r;
        $stmt->close();
        out(['status'=>'success','products'=>$products]);

    case 'list':
        $status=trim((string)($_POST['status']??'Active'));
        $search=trim((string)($_POST['search']??''));

        $where=["l.pharmacy_id=?","l.branch_id=?"];
        $params=[$pharmacy_id,$branch_id];$types='ii';

        if($status==='Completed') $where[]="l.balance_due<=0";
        elseif($status==='Active') $where[]="l.balance_due>0";
        elseif($status==='Cancelled') $where[]="l.status='Cancelled'";

        if($search!==''){
            $where[]="(l.customer_name LIKE ? OR l.customer_phone LIKE ?)";
            $s='%'.$search.'%';$params[]=$s;$params[]=$s;$types.='ss';
        }

        $sql="SELECT l.id,l.customer_name,l.customer_phone,l.total_amount,l.deposit,l.balance_due,l.due_date,l.status,l.created_at
              FROM laybys l WHERE ".implode(' AND ',$where)." ORDER BY l.id DESC";
        $stmt=$conn->prepare($sql);
        $stmt->bind_param($types,...$params);$stmt->execute();$res=$stmt->get_result();

        $records=[];$active=0;$completed=0;$balance=0;$paid=0;
        while($r=$res->fetch_assoc()){
            $r['total_amount']=(float)$r['total_amount'];$r['deposit']=(float)$r['deposit'];$r['balance_due']=(float)$r['balance_due'];
            if($r['balance_due']<=0||$r['status']==='Completed') $completed++; else {$active++;$balance+=$r['balance_due'];}
            $paid+=$r['deposit'];$records[]=$r;
        }
        $stmt->close();

        // KPI totals are branch-wide, not only the currently selected status.
        $q="SELECT
            SUM(CASE WHEN balance_due>0 AND status<>'Cancelled' THEN 1 ELSE 0 END) active_count,
            SUM(CASE WHEN balance_due<=0 OR status='Completed' THEN 1 ELSE 0 END) completed_count,
            COALESCE(SUM(CASE WHEN status<>'Cancelled' THEN balance_due ELSE 0 END),0) balance_total,
            COALESCE(SUM(CASE WHEN status<>'Cancelled' THEN deposit ELSE 0 END),0) paid_total
            FROM laybys WHERE pharmacy_id=? AND branch_id=?";
        $st=$conn->prepare($q);$st->bind_param('ii',$pharmacy_id,$branch_id);$st->execute();$k=$st->get_result()->fetch_assoc();$st->close();

        out(['status'=>'success','records'=>$records,'stats'=>[
            'active'=>(int)($k['active_count']??0),
            'completed'=>(int)($k['completed_count']??0),
            'balance'=>(float)($k['balance_total']??0),
            'paid'=>(float)($k['paid_total']??0)
        ]]);

    case 'view':
        $id=(int)($_POST['id']??0);
        if($id<=0) out(['status'=>'error','message'=>'Invalid lay-by ID.'],400);

        $stmt=$conn->prepare("SELECT * FROM laybys WHERE id=? AND pharmacy_id=? AND branch_id=? LIMIT 1");
        $stmt->bind_param('iii',$id,$pharmacy_id,$branch_id);$stmt->execute();$layby=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$layby) out(['status'=>'error','message'=>'Lay-by not found or access denied.'],404);

        $stmt=$conn->prepare("SELECT product_name,price,qty,total FROM layby_items WHERE layby_id=? AND pharmacy_id=? AND branch_id=? ORDER BY id ASC");
        $stmt->bind_param('iii',$id,$pharmacy_id,$branch_id);$stmt->execute();$ri=$stmt->get_result();$items=[];
        while($r=$ri->fetch_assoc()){$r['price']=(float)$r['price'];$r['qty']=(int)$r['qty'];$r['total']=(float)$r['total'];$items[]=$r;}$stmt->close();

        $stmt=$conn->prepare("SELECT payment_amount,payment_date,method,notes FROM layby_payments WHERE layby_id=? AND pharmacy_id=? AND branch_id=? ORDER BY payment_date ASC,id ASC");
        $stmt->bind_param('iii',$id,$pharmacy_id,$branch_id);$stmt->execute();$rp=$stmt->get_result();$payments=[];
        while($r=$rp->fetch_assoc()){
            $r['payment_amount']=(float)$r['payment_amount'];
            $r['payment_date_formatted']=date('d M Y, h:i A',strtotime($r['payment_date']));
            $payments[]=$r;
        }$stmt->close();

        $layby['total_amount']=(float)$layby['total_amount'];$layby['deposit']=(float)$layby['deposit'];$layby['balance_due']=(float)$layby['balance_due'];
        $layby['created_at_formatted']=date('d M Y, h:i A',strtotime($layby['created_at']));

        out(['status'=>'success','layby'=>$layby,'items'=>$items,'payments'=>$payments]);

    case 'create':
        $name=trim((string)($_POST['customer_name']??''));
        $phone=trim((string)($_POST['customer_phone']??''));
        $deposit=positiveFloat($_POST['deposit']??0);
        $total=positiveFloat($_POST['total_amount']??0);
        $due=trim((string)($_POST['due_date']??''));
        $cart=json_decode((string)($_POST['cart']??'[]'),true);

        if($name===''||$phone===''||$due==='') out(['status'=>'error','message'=>'Customer name, phone and due date are required.'],422);
        if($total<=0) out(['status'=>'error','message'=>'Lay-by total must be greater than zero.'],422);
        if($deposit>$total) out(['status'=>'error','message'=>'Deposit cannot exceed the total amount.'],422);
        if(!is_array($cart)||!count($cart)) out(['status'=>'error','message'=>'Please add at least one product.'],422);

        $d=DateTime::createFromFormat('Y-m-d',$due);
        if(!$d||$d->format('Y-m-d')!==$due) out(['status'=>'error','message'=>'Invalid due date.'],422);

        $conn->begin_transaction();
        try{
            // Re-price and verify every item from the live branch stock.
            $validated=[];$calculatedTotal=0.0;
            $stockStmt=$conn->prepare("SELECT id,item_name,price,quantity FROM store_items WHERE id=? AND pharmacy_id=? AND branch_id=? AND is_active=1 FOR UPDATE");
            if(!$stockStmt) throw new Exception('Unable to validate products.');

            foreach($cart as $item){
                $pid=(int)($item['id']??0);$qty=(int)($item['qty']??0);
                if($pid<=0||$qty<=0) throw new Exception('Invalid product or quantity.');

                $stockStmt->bind_param('iii',$pid,$pharmacy_id,$branch_id);$stockStmt->execute();
                $p=$stockStmt->get_result()->fetch_assoc();
                if(!$p) throw new Exception('A selected product is no longer available.');
                if((int)$p['quantity']<$qty) throw new Exception('Insufficient stock for '.$p['item_name'].'. Available: '.$p['quantity']);

                $price=(float)$p['price'];$sub=$price*$qty;$calculatedTotal+=$sub;
                $validated[]=['id'=>$pid,'name'=>$p['item_name'],'price'=>$price,'qty'=>$qty,'total'=>$sub];
            }
            $stockStmt->close();

            $total=$calculatedTotal;
            if($deposit>$total) throw new Exception('Deposit cannot exceed the verified cart total.');
            $balance=$total-$deposit;
            $status=$balance<=0?'Completed':'Pending';

            $stmt=$conn->prepare("INSERT INTO laybys (pharmacy_id,branch_id,user_id,customer_name,customer_phone,total_amount,deposit,balance_due,due_date,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->bind_param('iiissdddss',$pharmacy_id,$branch_id,$user_id,$name,$phone,$total,$deposit,$balance,$due,$status);
            if(!$stmt->execute()) throw new Exception('Failed to create lay-by agreement.');
            $laybyId=$stmt->insert_id;$stmt->close();

            $itemStmt=$conn->prepare("INSERT INTO layby_items (layby_id,product_name,price,qty,total,pharmacy_id,branch_id) VALUES (?,?,?,?,?,?,?)");
            $updateStock=$conn->prepare("UPDATE store_items SET quantity=quantity-? WHERE id=? AND pharmacy_id=? AND branch_id=? AND quantity>=?");
            if(!$itemStmt||!$updateStock) throw new Exception('Unable to prepare lay-by items.');

            foreach($validated as $i){
                $itemStmt->bind_param('isdidii',$laybyId,$i['name'],$i['price'],$i['qty'],$i['total'],$pharmacy_id,$branch_id);
                if(!$itemStmt->execute()) throw new Exception('Failed to save lay-by item.');

                $updateStock->bind_param('iiiii',$i['qty'],$i['id'],$pharmacy_id,$branch_id,$i['qty']);
                if(!$updateStock->execute()||$updateStock->affected_rows!==1) throw new Exception('Stock changed while creating the lay-by for '.$i['name'].'.');
            }
            $itemStmt->close();$updateStock->close();

            if($deposit>0){
                $pay=$conn->prepare("INSERT INTO layby_payments (pharmacy_id,layby_id,branch_id,user_id,payment_amount,payment_date,method,notes) VALUES (?,?,?,?,?,NOW(),'Cash','Initial Deposit')");
                $pay->bind_param('iiiid',$pharmacy_id,$laybyId,$branch_id,$user_id,$deposit);
                if(!$pay->execute()) throw new Exception('Failed to record initial deposit.');
                $pay->close();
            }

            $conn->commit();
            out(['status'=>'success','message'=>'Lay-by created successfully.','layby_id'=>$laybyId]);
        }catch(Throwable $e){$conn->rollback();throw $e;}

    case 'pay':
        $id=(int)($_POST['layby_id']??0);
        $amount=positiveFloat($_POST['payment_amount']??0);
        $method=trim((string)($_POST['method']??'Cash'));
        $allowed=['Cash','Airtel Money','MTN Money','Bank Transfer','POS Card'];
        if(!in_array($method,$allowed,true)) $method='Cash';
        if($id<=0||$amount<=0) out(['status'=>'error','message'=>'Invalid payment.'],422);

        $conn->begin_transaction();
        try{
            $stmt=$conn->prepare("SELECT deposit,balance_due,status FROM laybys WHERE id=? AND pharmacy_id=? AND branch_id=? FOR UPDATE");
            $stmt->bind_param('iii',$id,$pharmacy_id,$branch_id);$stmt->execute();$l=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$l) throw new Exception('Lay-by not found.');
            $balance=(float)$l['balance_due'];
            if($balance<=0) throw new Exception('This lay-by is already fully paid.');
            if($amount>$balance+0.00001) throw new Exception('Payment cannot exceed the balance due of K'.number_format($balance,2).'.');

            $newDeposit=(float)$l['deposit']+$amount;$newBalance=$balance-$amount;
            if(abs($newBalance)<0.005)$newBalance=0;
            $newStatus=$newBalance<=0?'Completed':'Pending';

            $pay=$conn->prepare("INSERT INTO layby_payments (pharmacy_id,layby_id,branch_id,user_id,payment_amount,payment_date,method,notes) VALUES (?,?,?,?,?,NOW(),?,?)");
            $notes='Installment Payment';$pay->bind_param('iiiidss',$pharmacy_id,$id,$branch_id,$user_id,$amount,$method,$notes);
            if(!$pay->execute()) throw new Exception('Failed to record payment.');$pay->close();

            $up=$conn->prepare("UPDATE laybys SET deposit=?,balance_due=?,status=? WHERE id=? AND pharmacy_id=? AND branch_id=?");
            $up->bind_param('ddsiii',$newDeposit,$newBalance,$newStatus,$id,$pharmacy_id,$branch_id);
            if(!$up->execute()) throw new Exception('Failed to update lay-by balance.');$up->close();

            $conn->commit();
            out(['status'=>'success','message'=>'Payment recorded successfully.','balance_due'=>$newBalance,'status'=>$newStatus]);
        }catch(Throwable $e){$conn->rollback();throw $e;}

    case 'update':
        $id=(int)($_POST['id']??0);
        $name=trim((string)($_POST['customer_name']??''));
        $phone=trim((string)($_POST['customer_phone']??''));
        $due=trim((string)($_POST['due_date']??''));

        if($id<=0||$name===''||$phone===''||$due===''){
            out(['status'=>'error','message'=>'Customer name, phone and due date are required.'],422);
        }

        $d=DateTime::createFromFormat('Y-m-d',$due);
        if(!$d||$d->format('Y-m-d')!==$due){
            out(['status'=>'error','message'=>'Invalid due date.'],422);
        }

        $stmt=$conn->prepare("
            UPDATE laybys
            SET customer_name=?, customer_phone=?, due_date=?
            WHERE id=? AND pharmacy_id=? AND branch_id=?
        ");
        $stmt->bind_param('sssiii',$name,$phone,$due,$id,$pharmacy_id,$branch_id);

        if(!$stmt->execute() || $stmt->affected_rows<0){
            $stmt->close();
            throw new Exception('Failed to update lay-by.');
        }
        $stmt->close();

        out(['status'=>'success','message'=>'Lay-by details updated successfully.']);

    case 'delete':
        $id=(int)($_POST['id']??0);
        if($id<=0) out(['status'=>'error','message'=>'Invalid lay-by ID.'],422);

        $conn->begin_transaction();
        try{
            $stmt=$conn->prepare("SELECT id,balance_due,status FROM laybys WHERE id=? AND pharmacy_id=? AND branch_id=? FOR UPDATE");
            $stmt->bind_param('iii',$id,$pharmacy_id,$branch_id);
            $stmt->execute();
            $found=$stmt->get_result()->fetch_assoc();
            $stmt->close();

            if(!$found) throw new Exception('Lay-by not found or access denied.');

            /*
             * Stock was reserved/deducted when the lay-by was created.
             * If it is still unpaid, deleting the agreement releases that
             * reservation. A fully paid lay-by is already a completed sale,
             * so deleting its history must NOT put the stock back.
             */
            $restoreStock=((float)$found['balance_due']>0 && $found['status']!=='Completed');

            if($restoreStock){
                /*
                 * The current database schema stores product_name rather than
                 * product_id in layby_items. Restore against the matching
                 * product in the same pharmacy/branch.
                 */
                $items=$conn->prepare("
                    SELECT li.product_name, li.qty
                    FROM layby_items li
                    WHERE li.layby_id=? AND li.pharmacy_id=? AND li.branch_id=?
                ");
                $items->bind_param('iii',$id,$pharmacy_id,$branch_id);
                $items->execute();
                $ri=$items->get_result();

                $restore=$conn->prepare("
                    UPDATE store_items
                    SET quantity=quantity+?
                    WHERE id=(
                        SELECT id FROM (
                            SELECT id
                            FROM store_items
                            WHERE pharmacy_id=? AND branch_id=? AND item_name=?
                            ORDER BY id ASC
                            LIMIT 1
                        ) AS matched_product
                    )
                ");

                while($item=$ri->fetch_assoc()){
                    $qty=(int)$item['qty'];
                    $name=(string)$item['product_name'];

                    if($qty>0){
                        $restore->bind_param('iiis',$qty,$pharmacy_id,$branch_id,$name);
                        if(!$restore->execute()){
                            throw new Exception('Failed to restore stock for '.$name.'.');
                        }
                    }
                }

                $restore->close();
                $items->close();
            }

            $pstmt=$conn->prepare("DELETE FROM layby_payments WHERE layby_id=? AND pharmacy_id=? AND branch_id=?");
            $pstmt->bind_param('iii',$id,$pharmacy_id,$branch_id);
            if(!$pstmt->execute()) throw new Exception('Failed to remove payment history.');
            $pstmt->close();

            $istmt=$conn->prepare("DELETE FROM layby_items WHERE layby_id=? AND pharmacy_id=? AND branch_id=?");
            $istmt->bind_param('iii',$id,$pharmacy_id,$branch_id);
            if(!$istmt->execute()) throw new Exception('Failed to remove lay-by items.');
            $istmt->close();

            $d=$conn->prepare("DELETE FROM laybys WHERE id=? AND pharmacy_id=? AND branch_id=?");
            $d->bind_param('iii',$id,$pharmacy_id,$branch_id);
            if(!$d->execute()||$d->affected_rows!==1) throw new Exception('Failed to delete lay-by.');
            $d->close();

            $conn->commit();

            out([
                'status'=>'success',
                'message'=>$restoreStock
                    ? 'Lay-by deleted and reserved stock restored.'
                    : 'Fully paid lay-by history deleted. Stock was not restored.'
            ]);
        }catch(Throwable $e){
            $conn->rollback();
            throw $e;
        }

    case 'clear_completed':
        $conn->begin_transaction();
        try{
            $stmt=$conn->prepare("
                SELECT id
                FROM laybys
                WHERE pharmacy_id=? AND branch_id=? AND balance_due<=0
            ");
            $stmt->bind_param('ii',$pharmacy_id,$branch_id);
            $stmt->execute();
            $res=$stmt->get_result();
            $ids=[];

            while($r=$res->fetch_assoc()) $ids[]=(int)$r['id'];
            $stmt->close();

            /*
             * IMPORTANT:
             * Fully paid lay-bys represent completed sales. Their stock was
             * already deducted at creation and must remain deducted.
             * Clearing only removes the historical lay-by records.
             */
            foreach($ids as $id){
                $p=$conn->prepare("DELETE FROM layby_payments WHERE layby_id=? AND pharmacy_id=? AND branch_id=?");
                $p->bind_param('iii',$id,$pharmacy_id,$branch_id);
                if(!$p->execute()) throw new Exception('Failed to clear payment history.');
                $p->close();

                $i=$conn->prepare("DELETE FROM layby_items WHERE layby_id=? AND pharmacy_id=? AND branch_id=?");
                $i->bind_param('iii',$id,$pharmacy_id,$branch_id);
                if(!$i->execute()) throw new Exception('Failed to clear lay-by items.');
                $i->close();

                $d=$conn->prepare("DELETE FROM laybys WHERE id=? AND pharmacy_id=? AND branch_id=? AND balance_due<=0");
                $d->bind_param('iii',$id,$pharmacy_id,$branch_id);
                if(!$d->execute()) throw new Exception('Failed to clear completed lay-by.');
                $d->close();
            }

            $conn->commit();
            out([
                'status'=>'success',
                'message'=>count($ids).' fully paid lay-by(s) cleared.',
                'count'=>count($ids)
            ]);
        }catch(Throwable $e){
            $conn->rollback();
            throw $e;
        }

    default:
        out(['status'=>'error','message'=>'Unknown lay-by action.'],400);
    }
}catch(Throwable $e){
    out(['status'=>'error','message'=>$e->getMessage()],500);
}
