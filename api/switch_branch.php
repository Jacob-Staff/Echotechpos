<?php
/**
 * ============================================================
 * PUBLIC ONLINE STORE - BRANCH SWITCH
 * ============================================================
 * This endpoint is independent of the page currently being viewed.
 * The shared store header submits here, the selected branch is
 * validated, saved to the public-store session, and the visitor is
 * redirected to the Online Store for that branch.
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$conn_file = realpath(__DIR__ . '/../includes/conn.php');
if (!$conn_file) {
    $conn_file = realpath(__DIR__ . '/includes/conn.php');
}

if (!$conn_file || !is_file($conn_file)) {
    http_response_code(500);
    exit('Database connection file not found.');
}

require_once $conn_file;

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

$requested_branch = (int)($_GET['bid'] ?? 0);

if ($requested_branch <= 0) {
    header('Location: /api/online_store.php');
    exit;
}

/*
 * Existing public-store branch.
 * If it is still valid, the new branch must belong to the same pharmacy.
 */
$current_branch_id = (int)($_SESSION['current_branch_id'] ?? 0);
$current_pharmacy_id = 0;

if ($current_branch_id > 0) {
    $stmt = $conn->prepare(
        "SELECT pharmacy_id
         FROM branches
         WHERE id = ?
           AND is_active = 1
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param('i', $current_branch_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $current_pharmacy_id = (int)($row['pharmacy_id'] ?? 0);
    }
}

/*
 * Validate the requested branch.
 */
if ($current_pharmacy_id > 0) {
    $stmt = $conn->prepare(
        "SELECT id, pharmacy_id, branch_name
         FROM branches
         WHERE id = ?
           AND pharmacy_id = ?
           AND is_active = 1
         LIMIT 1"
    );

    if (!$stmt) {
        http_response_code(500);
        exit('Unable to verify the selected branch.');
    }

    $stmt->bind_param('ii', $requested_branch, $current_pharmacy_id);
} else {
    /* First public-store branch selection. */
    $stmt = $conn->prepare(
        "SELECT id, pharmacy_id, branch_name
         FROM branches
         WHERE id = ?
           AND is_active = 1
         LIMIT 1"
    );

    if (!$stmt) {
        http_response_code(500);
        exit('Unable to verify the selected branch.');
    }

    $stmt->bind_param('i', $requested_branch);
}

$stmt->execute();
$selected_branch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$selected_branch) {
    header('Location: /api/online_store.php?branch_error=invalid');
    exit;
}

/* Save ONLY the public-store branch context. */
$_SESSION['current_branch_id'] = (int)$selected_branch['id'];

/*
 * Always reload the Online Store itself, regardless of the page where
 * the user changed the branch.
 */
header(
    'Location: /api/online_store.php?bid=' .
    (int)$selected_branch['id']
);
exit;
