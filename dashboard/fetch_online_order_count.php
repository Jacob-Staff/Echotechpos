<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once "../includes/conn.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0 || !isset($conn)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'count' => 0,
        'message' => 'Missing pharmacy or branch session.'
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
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'count' => 0,
        'message' => 'Unable to prepare online order count query.'
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'ii', $pharmacy_id, $branch_id);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'count' => 0,
        'message' => 'Unable to execute online order count query.'
    ]);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$row = $result ? mysqli_fetch_assoc($result) : null;

if ($result) {
    mysqli_free_result($result);
}

mysqli_stmt_close($stmt);

echo json_encode([
    'status' => 'success',
    'count' => (int)($row['total'] ?? 0)
]);
