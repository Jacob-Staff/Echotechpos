<?php
/**
 * Optional ZRA Integration hook for process_sale.php / online_orders.php.
 * Include this file only AFTER the local sale transaction has committed.
 * It creates a queued ZRA declaration; it does not transmit inline.
 *
 * Usage after a successful $saleId:
 *   require_once __DIR__ . '/zra_sales.php';
 *   zra_queue_sale($conn, $saleId, $pharmacyId, $branchId, $userId);
 */
declare(strict_types=1);
require_once __DIR__.'/zra_client.php';
function zra_queue_sale(mysqli $conn,int $saleId,int $pharmacyId,int $branchId,int $userId):array {
    if($saleId<=0||$pharmacyId<=0||$branchId<=0)throw new InvalidArgumentException('Invalid sale tenant context.');
    $settings=[];$s=$conn->prepare('SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1');if(!$s)throw new RuntimeException('Unable to load compliance settings.');$s->bind_param('i',$pharmacyId);$s->execute();$settings=$s->get_result()->fetch_assoc()?:[];$s->close();
    $saleStmt=$conn->prepare('SELECT * FROM sales WHERE id=? AND pharmacy_id=? AND branch_id=? LIMIT 1');if(!$saleStmt)throw new RuntimeException('Unable to load sale.');$saleStmt->bind_param('iii',$saleId,$pharmacyId,$branchId);$saleStmt->execute();$sale=$saleStmt->get_result()->fetch_assoc();$saleStmt->close();if(!$sale)throw new RuntimeException('Sale does not belong to this pharmacy/branch.');
    $bhf='';if($s=$conn->prepare('SELECT zra_bhf_id FROM compliance_branches WHERE pharmacy_id=? AND branch_id=? LIMIT 1')){$s->bind_param('ii',$pharmacyId,$branchId);$s->execute();$bhf=(string)(($s->get_result()->fetch_assoc()['zra_bhf_id']??''));$s->close();}if(!preg_match('/^.{3}$/',$bhf))throw new RuntimeException('ZRA bhfId is not configured for this branch.');
    $s=$conn->prepare('SELECT si.product_id,si.quantity,si.unit_price,st.item_name,st.tax_rate,st.barcode,st.zra_item_cd,st.zra_item_cls_cd,st.zra_pkg_unit_cd,st.zra_qty_unit_cd,st.zra_tax_type_cd FROM sales_items si INNER JOIN store_items st ON st.id=si.product_id WHERE si.sale_id=? AND si.pharmacy_id=? AND si.branch_id=? ORDER BY si.id');if(!$s)throw new RuntimeException('Unable to load sale items.');$s->bind_param('iii',$saleId,$pharmacyId,$branchId);$s->execute();$r=$s->get_result();$items=[];while($x=$r->fetch_assoc())$items[]=$x;$s->close();
    $payload=zra_build_sale_payload($sale,$items,$settings,$bhf);$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$ref='SALE:'.$pharmacyId.':'.$branchId.':'.$saleId;$endpoint='/trnsSales/saveSales';
    $s=$conn->prepare('INSERT INTO zra_queue (pharmacy_id,branch_id,sale_id,local_reference,endpoint,payload,status,next_attempt_at,created_by) VALUES (?,?,?,?,?,?,"queued",NOW(),?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),endpoint=VALUES(endpoint),status=IF(status="submitted",status,"queued"),updated_at=NOW()');if(!$s)throw new RuntimeException('Unable to create ZRA queue record.');$s->bind_param('iiisssi',$pharmacyId,$branchId,$saleId,$ref,$endpoint,$json,$userId);if(!$s->execute()){ $e=$s->error;$s->close();throw new RuntimeException('Unable to queue sale: '.$e);} $id=(int)$s->insert_id;$s->close();return ['queue_id'=>$id,'reference'=>$ref];
}
