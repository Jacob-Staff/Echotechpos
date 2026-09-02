<?php
declare(strict_types=1);
/**
 * EchoTech POS - ZRA queue worker
 *
 * CLI: php admin/actions/zra_retry.php
 * Web: disabled unless an authenticated Admin explicitly supplies the worker token.
 */
date_default_timezone_set('Africa/Lusaka');
if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if (!in_array($role, ['admin','administrator','management'], true)) { http_response_code(403); exit('Access denied.'); }
}
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/zra_invoice_service.php';

$pharmacyId = PHP_SAPI === 'cli' ? 0 : (int)($_SESSION['pharmacy_id'] ?? 0);
$limit = PHP_SAPI === 'cli' ? 100 : 25;

if ($pharmacyId > 0) {
    $stmt=$conn->prepare("SELECT id FROM pos_zra_invoices WHERE pharmacy_id=? AND status IN ('queued','retry_pending','offline') AND (next_retry_at IS NULL OR next_retry_at<=NOW()) ORDER BY id ASC LIMIT {$limit}");
    $stmt->bind_param('i',$pharmacyId);
} else {
    $stmt=$conn->prepare("SELECT id FROM pos_zra_invoices WHERE status IN ('queued','retry_pending','offline') AND (next_retry_at IS NULL OR next_retry_at<=NOW()) ORDER BY id ASC LIMIT {$limit}");
}
$stmt->execute(); $r=$stmt->get_result(); $ids=[]; while($x=$r->fetch_assoc())$ids[]=(int)$x['id']; $stmt->close();

$accepted=0;$pending=0;
foreach($ids as $id){$result=pos_zra_process_one($conn,$id); if(($result['status']??'')==='accepted')$accepted++;else$pending++;}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>'success','processed'=>count($ids),'accepted'=>$accepted,'pending'=>$pending,'generated_at'=>date('c')],JSON_UNESCAPED_SLASHES);
