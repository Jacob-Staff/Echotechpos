<?php
/**
 * EchoTech POS - Live Online Order Count
 *
 * Returns Pending + Processing customer orders for the
 * currently authenticated pharmacy and branch.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('Africa/Lusaka');

require_once "../includes/conn.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0 || !isset($conn)) {
    echo json_encode([
        'status' => 'error',
        'count' => 0
    ]);
    exit;
}

$sql = "
    SELECT COUNT(*) AS total
    FROM clients_orders
    WHERE pharmacy_id = ?
      AND branch_id = ?
      AND status IN ('Pending', 'Processing')
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'count' => 0
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    'ii',
    $pharmacy_id,
    $branch_id
);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);

    echo json_encode([
        'status' => 'error',
        'count' => 0
    ]);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$row = $result ? mysqli_fetch_assoc($result) : null;

mysqli_stmt_close($stmt);

echo json_encode([
    'status' => 'success',
    'count' => (int)($row['total'] ?? 0)
]);
?>