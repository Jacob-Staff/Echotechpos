<?php
declare(strict_types=1);
/**
 * EchoTech POS - Phase 3 POS/ZRA bridge
 *
 * This service is intentionally additive. The completed POS sale is always
 * committed first. ZRA/VSDC failure never rolls back a valid sale.
 *
 * The actual VSDC wire contract remains isolated in the Phase 2 connector.
 * This file calls zra_phase2_submit_invoice() when that connector is present.
 */

function pos_zra_json_encode(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function pos_zra_table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$safe}'");
    return $r instanceof mysqli_result && $r->num_rows > 0;
}

function pos_zra_audit(mysqli $conn, int $pharmacyId, int $branchId, int $saleId, ?int $zraId, string $action, string $status, string $message = '', ?string $responseCode = null): void
{
    if (!pos_zra_table_exists($conn, 'pos_zra_audit_log')) return;
    $stmt = @$conn->prepare('INSERT INTO pos_zra_audit_log (pharmacy_id,branch_id,sale_id,zra_invoice_id,action,status,message,response_code) VALUES (?,?,?,?,?,?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('iiiissss', $pharmacyId, $branchId, $saleId, $zraId, $action, $status, $message, $responseCode);
    @$stmt->execute();
    $stmt->close();
}

function pos_zra_build_payload(mysqli $conn, int $pharmacyId, int $branchId, int $saleId): array
{
    $saleStmt = $conn->prepare('SELECT s.id,s.pharmacy_id,s.branch_id,s.invoice,s.created_at,s.subtotal,s.vat_amount,s.total,s.payment_method FROM sales s WHERE s.id=? AND s.pharmacy_id=? AND s.branch_id=? LIMIT 1');
    if (!$saleStmt) throw new RuntimeException('Unable to prepare ZRA sale lookup.');
    $saleStmt->bind_param('iii', $saleId, $pharmacyId, $branchId);
    $saleStmt->execute();
    $sale = $saleStmt->get_result()->fetch_assoc();
    $saleStmt->close();
    if (!$sale) throw new RuntimeException('Sale was not found for this pharmacy and branch.');

    $itemStmt = $conn->prepare('SELECT si.product_id,si.quantity,si.unit_price,st.item_name FROM sales_items si INNER JOIN store_items st ON st.id=si.product_id WHERE si.sale_id=? AND si.pharmacy_id=? AND si.branch_id=? ORDER BY si.id ASC');
    if (!$itemStmt) throw new RuntimeException('Unable to prepare ZRA item lookup.');
    $itemStmt->bind_param('iii', $saleId, $pharmacyId, $branchId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'product_id' => (int)$row['product_id'],
            'description' => (string)$row['item_name'],
            'quantity' => (float)$row['quantity'],
            'unit_price' => round((float)$row['unit_price'], 2),
            'amount' => round((float)$row['unit_price'] * (float)$row['quantity'], 2),
        ];
    }
    $itemStmt->close();

    $settings = [];
    if (pos_zra_table_exists($conn, 'compliance_settings')) {
        $stmt = @$conn->prepare('SELECT * FROM compliance_settings WHERE pharmacy_id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $pharmacyId);
            $stmt->execute();
            $settings = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }

    return [
        'sale_id' => (int)$sale['id'],
        'pharmacy_id' => $pharmacyId,
        'branch_id' => $branchId,
        'local_invoice_no' => (string)$sale['invoice'],
        'invoice_date' => (string)$sale['created_at'],
        'currency' => 'ZMW',
        'payment_method' => (string)$sale['payment_method'],
        'subtotal' => round((float)$sale['subtotal'], 2),
        'vat_amount' => round((float)$sale['vat_amount'], 2),
        'total' => round((float)$sale['total'], 2),
        'taxpayer_tpin' => (string)($settings['tpin'] ?? ''),
        'items' => $items,
    ];
}

function pos_zra_environment(mysqli $conn, int $pharmacyId): string
{
    if (!pos_zra_table_exists($conn, 'compliance_settings')) return 'test';
    $stmt = @$conn->prepare('SELECT smart_invoice_environment FROM compliance_settings WHERE pharmacy_id=? LIMIT 1');
    if (!$stmt) return 'test';
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $env = strtolower(trim((string)($row['smart_invoice_environment'] ?? 'test')));
    return in_array($env, ['production','prod','live'], true) ? 'production' : 'test';
}

/** Queue a completed sale. Safe to call after the sales transaction commits. */
function pos_zra_queue_sale(mysqli $conn, int $pharmacyId, int $branchId, int $saleId): array
{
    if (!pos_zra_table_exists($conn, 'pos_zra_invoices')) {
        return ['queued' => false, 'message' => 'Phase 3 queue table is not installed.'];
    }

    $payload = pos_zra_build_payload($conn, $pharmacyId, $branchId, $saleId);
    $json = pos_zra_json_encode($payload);
    $environment = pos_zra_environment($conn, $pharmacyId);

    $stmt = $conn->prepare('SELECT id,status FROM pos_zra_invoices WHERE pharmacy_id=? AND branch_id=? AND sale_id=? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to check ZRA queue.');
    $stmt->bind_param('iii', $pharmacyId, $branchId, $saleId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        return ['queued' => true, 'id' => (int)$existing['id'], 'status' => (string)$existing['status'], 'duplicate' => true];
    }

    $stmt = $conn->prepare('INSERT INTO pos_zra_invoices (pharmacy_id,branch_id,sale_id,local_invoice_no,status,environment,next_retry_at,request_payload) VALUES (?,?,?,?,\'queued\',?,NOW(),?)');
    if (!$stmt) throw new RuntimeException('Unable to create ZRA queue record.');
    $localInvoice = (string)$payload['local_invoice_no'];
    $stmt->bind_param('iiisss', $pharmacyId, $branchId, $saleId, $localInvoice, $environment, $json);
    $stmt->execute();
    $zraId = (int)$conn->insert_id;
    $stmt->close();

    pos_zra_audit($conn, $pharmacyId, $branchId, $saleId, $zraId, 'sale_queued', 'queued', 'Completed POS sale added to Smart Invoice queue.');
    return ['queued' => true, 'id' => $zraId, 'status' => 'queued', 'duplicate' => false];
}

/**
 * Phase 2 connector hook.
 *
 * Expected return:
 * [
 *   'success'=>true/false,
 *   'status'=>'accepted'|'rejected'|'pending',
 *   'zra_invoice_no'=>..., 'sdc_id'=>..., 'signature'=>..., 'internal_data'=>...,
 *   'response_code'=>..., 'message'=>..., 'raw'=>...
 * ]
 *
 * We deliberately do not invent ZRA endpoint paths here. ZRA's current VSDC
 * specification defines the VSDC API as REST/JSON and API-key based, while
 * the concrete endpoint/device details come from the taxpayer's VSDC instance.
 */
function pos_zra_submit_via_phase2(array $payload): array
{
    $candidates = [
        __DIR__ . '/zra_client.php',
        __DIR__ . '/zra_vsdс.php',
        __DIR__ . '/zra_vsdc.php',
        __DIR__ . '/zra_service.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) require_once $file;
    }

    if (function_exists('zra_phase2_submit_invoice')) {
        $result = zra_phase2_submit_invoice($payload);
        return is_array($result) ? $result : ['success'=>false,'status'=>'pending','message'=>'Phase 2 connector returned an invalid response.'];
    }

    return [
        'success' => false,
        'status' => 'pending',
        'message' => 'Phase 2 VSDC connector is not available yet; invoice remains queued.',
    ];
}

function pos_zra_process_one(mysqli $conn, int $zraId): array
{
    $stmt = $conn->prepare('SELECT * FROM pos_zra_invoices WHERE id=? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to load ZRA queue record.');
    $stmt->bind_param('i', $zraId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return ['success'=>false,'message'=>'Queue record not found.'];

    $payload = json_decode((string)$row['request_payload'], true);
    if (!is_array($payload)) {
        $payload = pos_zra_build_payload($conn, (int)$row['pharmacy_id'], (int)$row['branch_id'], (int)$row['sale_id']);
    }

    $attempt = (int)$row['attempt_count'] + 1;
    $environment = (string)$row['environment'];
    $stmt = $conn->prepare("UPDATE pos_zra_invoices SET status='submitting',attempt_count=?,last_attempt_at=NOW(),updated_at=NOW() WHERE id=?");
    if ($stmt) { $stmt->bind_param('ii',$attempt,$zraId); $stmt->execute(); $stmt->close(); }

    try {
        $response = pos_zra_submit_via_phase2($payload);
        $success = !empty($response['success']) && in_array((string)($response['status'] ?? 'accepted'), ['accepted','success'], true);
        $status = strtolower((string)($response['status'] ?? ($success ? 'accepted' : 'retry_pending')));
        $message = (string)($response['message'] ?? '');
        $code = isset($response['response_code']) ? (string)$response['response_code'] : null;
        $raw = pos_zra_json_encode($response);

        if ($success) {
            $zraInvoiceNo = (string)($response['zra_invoice_no'] ?? $response['invoice_no'] ?? '');
            $sdcId = (string)($response['sdc_id'] ?? $response['zra_sdc_id'] ?? '');
            $signature = (string)($response['signature'] ?? $response['fiscal_signature'] ?? '');
            $internal = (string)($response['internal_data'] ?? '');
            $receiptUrl = (string)($response['receipt_url'] ?? '');
            $stmt = $conn->prepare("UPDATE pos_zra_invoices SET status='accepted',zra_invoice_no=?,zra_sdc_id=?,fiscal_signature=?,internal_data=?,receipt_url=?,response_code=?,response_message=?,response_payload=?,submitted_at=NOW(),next_retry_at=NULL,updated_at=NOW() WHERE id=?");
            if (!$stmt) throw new RuntimeException('Unable to save accepted ZRA response.');
            $stmt->bind_param('ssssssssi',$zraInvoiceNo,$sdcId,$signature,$internal,$receiptUrl,$code,$message,$raw,$zraId);
            $stmt->execute(); $stmt->close();
            pos_zra_audit($conn,(int)$row['pharmacy_id'],(int)$row['branch_id'],(int)$row['sale_id'],$zraId,'zra_submission','accepted',$message,$code);
            return ['success'=>true,'status'=>'accepted','message'=>$message,'zra_invoice_no'=>$zraInvoiceNo,'sdc_id'=>$sdcId,'signature'=>$signature];
        }

        $retryDelay = min(3600, max(60, 60 * $attempt));
        $next = date('Y-m-d H:i:s', time() + $retryDelay);
        $finalStatus = ($status === 'rejected' || $attempt >= 10) ? 'rejected' : 'retry_pending';
        $stmt = $conn->prepare('UPDATE pos_zra_invoices SET status=?,response_code=?,response_message=?,response_payload=?,last_error=?,next_retry_at=?,updated_at=NOW() WHERE id=?');
        if ($stmt) { $stmt->bind_param('ssssssi',$finalStatus,$code,$message,$raw,$message,$next,$zraId); $stmt->execute(); $stmt->close(); }
        pos_zra_audit($conn,(int)$row['pharmacy_id'],(int)$row['branch_id'],(int)$row['sale_id'],$zraId,'zra_submission',$finalStatus,$message,$code);
        return ['success'=>false,'status'=>$finalStatus,'message'=>$message ?: 'ZRA submission was not accepted.'];
    } catch (Throwable $e) {
        $delay = min(3600, max(60, 60 * $attempt));
        $next = date('Y-m-d H:i:s', time() + $delay);
        $message = $e->getMessage();
        $stmt = $conn->prepare("UPDATE pos_zra_invoices SET status='retry_pending',last_error=?,response_message=?,next_retry_at=?,updated_at=NOW() WHERE id=?");
        if ($stmt) { $stmt->bind_param('sssi',$message,$message,$next,$zraId); $stmt->execute(); $stmt->close(); }
        pos_zra_audit($conn,(int)$row['pharmacy_id'],(int)$row['branch_id'],(int)$row['sale_id'],$zraId,'zra_submission','retry_pending',$message);
        return ['success'=>false,'status'=>'retry_pending','message'=>'ZRA connection failed. The invoice remains queued for retry.'];
    }
}
