<?php
declare(strict_types=1);
/** EchoTech POS - Admin ZRA queue actions */
date_default_timezone_set('Africa/Lusaka');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/conn.php';

$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!in_array($role, ['admin','administrator','management'], true)) {
    http_response_code(403); exit('Access denied.');
}

require_once __DIR__ . '/zra_invoice_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../zra_invoices.php'); exit; }

$stored = (string)($_SESSION['zra_queue_csrf'] ?? '');
$posted = (string)($_POST['csrf_token'] ?? '');
if ($stored === '' || !hash_equals($stored, $posted)) { http_response_code(419); exit('Security token expired. Reload the page and try again.'); }

$pharmacyId = (int)($_SESSION['pharmacy_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

if ($pharmacyId <= 0) { http_response_code(403); exit('Invalid pharmacy context.'); }

if ($action === 'retry_one') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare('SELECT id,pharmacy_id,branch_id FROM pos_zra_invoices WHERE id=? AND pharmacy_id=? LIMIT 1');
    $stmt->bind_param('ii', $id, $pharmacyId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) { header('Location: ../zra_invoices.php?error=Invoice+queue+record+not+found'); exit; }
    $result = pos_zra_process_one($conn, $id);
    $msg = $result['status'] === 'accepted' ? 'ZRA invoice accepted.' : ($result['message'] ?: 'Invoice remains queued.');
    header('Location: ../zra_invoices.php?message=' . rawurlencode($msg)); exit;
}

if ($action === 'retry_due') {
    $stmt = $conn->prepare("SELECT id FROM pos_zra_invoices WHERE pharmacy_id=? AND status IN ('queued','retry_pending','offline') AND (next_retry_at IS NULL OR next_retry_at<=NOW()) ORDER BY id ASC LIMIT 25");
    $stmt->bind_param('i', $pharmacyId); $stmt->execute(); $result = $stmt->get_result();
    $ids=[]; while($r=$result->fetch_assoc()) $ids[]=(int)$r['id']; $stmt->close();
    $accepted=0; $failed=0;
    foreach($ids as $id){ $r=pos_zra_process_one($conn,$id); if(($r['status']??'')==='accepted')$accepted++; else $failed++; }
    header('Location: ../zra_invoices.php?message=' . rawurlencode("Processed ".count($ids)." queued invoice(s): {$accepted} accepted, {$failed} still pending.")); exit;
}

header('Location: ../zra_invoices.php'); exit;
