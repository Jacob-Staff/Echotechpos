<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

require_once __DIR__ . '/../includes/conn.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);

if ($pharmacy_id <= 0) {
    header('Location: ../index.php?error=session_expired');
    exit;
}

/* =========================================================
   ECHOTECH POS — ADMIN ONLINE ORDERS
   Report-style admin architecture:
   - shared admin_header.php
   - shared admin_aside.php
   - pharmacy/branch tenancy enforced in every query
   - Processing -> Completed is the only stock-deducting step
   - Completed orders create a normal sales transaction
========================================================= */

function oo_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function oo_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $refs = [$types];

    foreach ($params as &$value) {
        $refs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function oo_rows(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = []
): array {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }

    if ($types !== '') {
        $bindParams = $params;
        oo_bind($stmt, $types, $bindParams);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error);
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $stmt->close();

    return $rows;
}

function oo_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function oo_payment_method(string $value): string
{
    $normalized = strtolower(trim($value));
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
        $normalized === 'bank' ||
        $normalized === 'bank transfer' ||
        $normalized === 'banktransfer'
    ) {
        return 'Online/Bank Transfer';
    }

    if (
        $normalized === 'mobile' ||
        $normalized === 'mobile money' ||
        $normalized === 'mobilemoney'
    ) {
        return 'Online/Mobile Money';
    }

    return '';
}

function oo_csrf_token(): string
{
    if (empty($_SESSION['admin_online_orders_csrf'])) {
        $_SESSION['admin_online_orders_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['admin_online_orders_csrf'];
}

function oo_require_csrf(): void
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['admin_online_orders_csrf'] ?? '');

    if (
        $stored === '' ||
        $posted === '' ||
        !hash_equals($stored, $posted)
    ) {
        oo_json([
            'success' => false,
            'message' => 'Security token expired. Please refresh the page and try again.'
        ], 419);
    }
}

$csrf_token = oo_csrf_token();

$action = strtolower(
    trim((string)($_POST['action'] ?? $_GET['action'] ?? ''))
);

/* =========================================================
   AJAX: VIEW ORDER
========================================================= */

if ($action === 'order') {
    $order_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

    if ($order_id <= 0) {
        oo_json([
            'success' => false,
            'message' => 'Invalid order.'
        ], 422);
    }

    try {
        $orders = oo_rows(
            $conn,
            "
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
                c.email
            FROM clients_orders co
            LEFT JOIN branches b
                ON b.id = co.branch_id
               AND b.pharmacy_id = co.pharmacy_id
            LEFT JOIN clients c
                ON c.id = co.client_id
            WHERE co.id = ?
              AND co.pharmacy_id = ?
            LIMIT 1
            ",
            'ii',
            [$order_id, $pharmacy_id]
        );

        if (!$orders) {
            oo_json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        $order = $orders[0];

        $items = oo_rows(
            $conn,
            "
            SELECT
                oi.id,
                oi.product_id,
                oi.quantity,
                oi.price_at_purchase,
                si.item_name,
                si.strength,
                si.barcode,
                si.image_path
            FROM clients_order_items oi
            LEFT JOIN store_items si
                ON si.id = oi.product_id
            WHERE oi.order_id = ?
              AND oi.pharmacy_id = ?
              AND oi.branch_id = ?
            ORDER BY oi.id ASC
            ",
            'iii',
            [
                $order_id,
                $pharmacy_id,
                (int)$order['branch_id']
            ]
        );

        oo_json([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
    } catch (Throwable $e) {
        oo_json([
            'success' => false,
            'message' => 'Unable to load this order.'
        ], 500);
    }
}

/* =========================================================
   POST: CHANGE STATUS
========================================================= */

if ($action === 'update_status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        oo_json([
            'success' => false,
            'message' => 'Invalid request method.'
        ], 405);
    }

    oo_require_csrf();

    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = trim((string)($_POST['status'] ?? ''));

    $allowed_statuses = [
        'Processing',
        'Completed',
        'Cancelled'
    ];

    if (
        $order_id <= 0 ||
        !in_array($new_status, $allowed_statuses, true)
    ) {
        oo_json([
            'success' => false,
            'message' => 'Invalid order or status.'
        ], 422);
    }

    $transaction_started = false;

    try {
        $conn->begin_transaction();
        $transaction_started = true;

        /*
         * Lock the order and use its own branch_id.
         * Admin users can manage orders across branches belonging
         * to the same pharmacy, while stock is always changed
         * only in the order's branch.
         */
        $stmt = $conn->prepare(
            "
            SELECT
                id,
                order_number,
                client_id,
                total_amount,
                payment_method,
                status,
                branch_id
            FROM clients_orders
            WHERE id = ?
              AND pharmacy_id = ?
            LIMIT 1
            FOR UPDATE
            "
        );

        if (!$stmt) {
            throw new RuntimeException('Unable to prepare order lookup.');
        }

        $stmt->bind_param('ii', $order_id, $pharmacy_id);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }

        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $current_status = (string)$order['status'];
        $order_branch_id = (int)$order['branch_id'];

        if ($order_branch_id <= 0) {
            throw new RuntimeException(
                'This order is not assigned to a valid branch.'
            );
        }

        /*
         * Verify the branch belongs to this pharmacy.
         */
        $branchStmt = $conn->prepare(
            "
            SELECT id, branch_name
            FROM branches
            WHERE id = ?
              AND pharmacy_id = ?
            LIMIT 1
            "
        );

        if (!$branchStmt) {
            throw new RuntimeException('Unable to verify order branch.');
        }

        $branchStmt->bind_param(
            'ii',
            $order_branch_id,
            $pharmacy_id
        );

        if (!$branchStmt->execute()) {
            $error = $branchStmt->error;
            $branchStmt->close();
            throw new RuntimeException($error);
        }

        $branch = $branchStmt->get_result()->fetch_assoc();
        $branchStmt->close();

        if (!$branch) {
            throw new RuntimeException(
                'The order branch does not belong to this pharmacy.'
            );
        }

        /*
         * Valid workflow:
         *
         * Pending -> Processing
         * Pending -> Cancelled
         * Processing -> Completed
         * Processing -> Cancelled
         *
         * Completed and Cancelled are terminal.
         */
        $valid_transition = false;

        if ($new_status === 'Processing') {
            $valid_transition = ($current_status === 'Pending');
        } elseif ($new_status === 'Completed') {
            $valid_transition = ($current_status === 'Processing');
        } elseif ($new_status === 'Cancelled') {
            $valid_transition = in_array(
                $current_status,
                ['Pending', 'Processing'],
                true
            );
        }

        if (!$valid_transition) {
            throw new RuntimeException(
                'This order cannot be moved from ' .
                $current_status .
                ' to ' .
                $new_status .
                '.'
            );
        }

        $sale_id = null;

        /*
         * =========================================================
         * COMPLETION WORKFLOW
         *
         * ONLY Processing -> Completed deducts stock.
         * Every item is checked before the status is changed.
         * If one item cannot be fulfilled, the entire transaction
         * rolls back and the order remains Processing.
         * =========================================================
         */
        if ($new_status === 'Completed') {
            $itemsStmt = $conn->prepare(
                "
                SELECT
                    id,
                    product_id,
                    quantity,
                    price_at_purchase
                FROM clients_order_items
                WHERE order_id = ?
                  AND pharmacy_id = ?
                  AND branch_id = ?
                ORDER BY id ASC
                FOR UPDATE
                "
            );

            if (!$itemsStmt) {
                throw new RuntimeException(
                    'Unable to prepare order items.'
                );
            }

            $itemsStmt->bind_param(
                'iii',
                $order_id,
                $pharmacy_id,
                $order_branch_id
            );

            if (!$itemsStmt->execute()) {
                $error = $itemsStmt->error;
                $itemsStmt->close();
                throw new RuntimeException($error);
            }

            $order_items = $itemsStmt
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

            $itemsStmt->close();

            if (!$order_items) {
                throw new RuntimeException(
                    'This order has no items to complete.'
                );
            }

            /*
             * Lock/check each product in the order branch.
             */
            $stockStmt = $conn->prepare(
                "
                UPDATE store_items
                SET quantity = quantity - ?
                WHERE id = ?
                  AND pharmacy_id = ?
                  AND branch_id = ?
                  AND quantity >= ?
                LIMIT 1
                "
            );

            if (!$stockStmt) {
                throw new RuntimeException(
                    'Unable to prepare stock update.'
                );
            }

            foreach ($order_items as $item) {
                $product_id = (int)($item['product_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);

                if ($product_id <= 0 || $quantity <= 0) {
                    $stockStmt->close();

                    throw new RuntimeException(
                        'This order contains an invalid product quantity.'
                    );
                }

                $stockStmt->bind_param(
                    'iiiii',
                    $quantity,
                    $product_id,
                    $pharmacy_id,
                    $order_branch_id,
                    $quantity
                );

                if (
                    !$stockStmt->execute() ||
                    $stockStmt->affected_rows !== 1
                ) {
                    /*
                     * Fetch a useful product name for the error.
                     */
                    $nameStmt = $conn->prepare(
                        "
                        SELECT item_name, quantity
                        FROM store_items
                        WHERE id = ?
                          AND pharmacy_id = ?
                          AND branch_id = ?
                        LIMIT 1
                        "
                    );

                    $product_name = 'One of the products';
                    $available_qty = null;

                    if ($nameStmt) {
                        $nameStmt->bind_param(
                            'iii',
                            $product_id,
                            $pharmacy_id,
                            $order_branch_id
                        );

                        if ($nameStmt->execute()) {
                            $product = $nameStmt
                                ->get_result()
                                ->fetch_assoc();

                            if ($product) {
                                if (!empty($product['item_name'])) {
                                    $product_name =
                                        (string)$product['item_name'];
                                }

                                $available_qty =
                                    (int)($product['quantity'] ?? 0);
                            }
                        }

                        $nameStmt->close();
                    }

                    $stockStmt->close();

                    if ($available_qty !== null) {
                        throw new RuntimeException(
                            $product_name .
                            ' does not have enough stock. ' .
                            'Required: ' .
                            $quantity .
                            ', available: ' .
                            $available_qty .
                            '.'
                        );
                    }

                    throw new RuntimeException(
                        $product_name .
                        ' is not available in the order branch.'
                    );
                }
            }

            $stockStmt->close();

            /*
             * =====================================================
             * COMPLETED ONLINE ORDER -> REAL POS SALES TRANSACTION
             *
             * This makes the completed order appear in the normal
             * Transactions/Sales Report workflow.
             *
             * client_reference is unique per online order logically:
             * ONLINE_ORDER_{order_id}
             * =====================================================
             */
            $client_reference = 'ONLINE_ORDER_' . $order_id;

            $online_payment_method = oo_payment_method(
                (string)($order['payment_method'] ?? '')
            );

            if ($online_payment_method === '') {
                throw new RuntimeException(
                    'Unable to determine the online payment method.'
                );
            }

            $sale_total = round(
                (float)($order['total_amount'] ?? 0),
                2
            );

            if ($sale_total < 0) {
                throw new RuntimeException(
                    'The order has an invalid total amount.'
                );
            }

            /*
             * Prevent duplicate sales records.
             */
            $existingSaleStmt = $conn->prepare(
                "
                SELECT id
                FROM sales
                WHERE pharmacy_id = ?
                  AND branch_id = ?
                  AND client_reference = ?
                LIMIT 1
                FOR UPDATE
                "
            );

            if (!$existingSaleStmt) {
                throw new RuntimeException(
                    'Unable to verify existing transaction.'
                );
            }

            $existingSaleStmt->bind_param(
                'iis',
                $pharmacy_id,
                $order_branch_id,
                $client_reference
            );

            if (!$existingSaleStmt->execute()) {
                $error = $existingSaleStmt->error;
                $existingSaleStmt->close();
                throw new RuntimeException($error);
            }

            $existing_sale = $existingSaleStmt
                ->get_result()
                ->fetch_assoc();

            $existingSaleStmt->close();

            if ($existing_sale) {
                $sale_id = (int)$existing_sale['id'];
            } else {
                $issued_by = 'Online Customer';
                $invoice = (string)$order['order_number'];
                $user_id = (int)($_SESSION['user_id'] ?? 0);
                $subtotal = $sale_total;
                $vat_amount = 0.00;
                $amount_received = $sale_total;
                $change_due = 0.00;

                /*
                 * Keep the same sales columns used by the existing
                 * EchoTech transaction/report workflow.
                 */
                $saleStmt = $conn->prepare(
                    "
                    INSERT INTO sales
                    (
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
                    VALUES
                    (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, NOW(), NOW()
                    )
                    "
                );

                if (!$saleStmt) {
                    throw new RuntimeException(
                        'Unable to prepare completed online transaction: ' .
                        $conn->error
                    );
                }

                $saleStmt->bind_param(
                    'iisssddidddsdd',
                    $pharmacy_id,
                    $order_branch_id,
                    $issued_by,
                    $invoice,
                    $client_reference,
                    $sale_total,
                    $sale_total,
                    $user_id,
                    $sale_total,
                    $subtotal,
                    $vat_amount,
                    $online_payment_method,
                    $amount_received,
                    $change_due
                );

                if (!$saleStmt->execute()) {
                    $error = $saleStmt->error;
                    $saleStmt->close();

                    throw new RuntimeException(
                        'Unable to record completed online transaction: ' .
                        $error
                    );
                }

                $sale_id = (int)$conn->insert_id;
                $saleStmt->close();

                if ($sale_id <= 0) {
                    throw new RuntimeException(
                        'Completed online transaction was not recorded.'
                    );
                }

                /*
                 * Copy the order items into sales_items.
                 */
                $saleItemStmt = $conn->prepare(
                    "
                    INSERT INTO sales_items
                    (
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
                    "
                );

                if (!$saleItemStmt) {
                    throw new RuntimeException(
                        'Unable to prepare completed transaction items: ' .
                        $conn->error
                    );
                }

                $saleItemStmt->bind_param(
                    'iiii',
                    $sale_id,
                    $order_id,
                    $pharmacy_id,
                    $order_branch_id
                );

                if (!$saleItemStmt->execute()) {
                    $error = $saleItemStmt->error;
                    $saleItemStmt->close();

                    throw new RuntimeException(
                        'Unable to record completed online transaction items: ' .
                        $error
                    );
                }

                if ($saleItemStmt->affected_rows <= 0) {
                    $saleItemStmt->close();

                    throw new RuntimeException(
                        'Completed online transaction has no sale items.'
                    );
                }

                $saleItemStmt->close();
            }
        }

        /*
         * Change order status only after all completion work succeeds.
         */
        $statusStmt = $conn->prepare(
            "
            UPDATE clients_orders
            SET status = ?
            WHERE id = ?
              AND pharmacy_id = ?
              AND branch_id = ?
            LIMIT 1
            "
        );

        if (!$statusStmt) {
            throw new RuntimeException(
                'Unable to prepare order status update.'
            );
        }

        $statusStmt->bind_param(
            'siii',
            $new_status,
            $order_id,
            $pharmacy_id,
            $order_branch_id
        );

        if (
            !$statusStmt->execute() ||
            $statusStmt->affected_rows !== 1
        ) {
            $statusStmt->close();

            throw new RuntimeException(
                'Order status was not changed.'
            );
        }

        $statusStmt->close();

        $conn->commit();
        $transaction_started = false;

        oo_json([
            'success' => true,
            'message' => $new_status === 'Completed'
                ? 'Order completed successfully. Stock was deducted and the sale was recorded.'
                : (
                    $new_status === 'Processing'
                        ? 'Order accepted and moved to Processing.'
                        : 'Order cancelled successfully.'
                ),
            'status' => $new_status,
            'sale_id' => $sale_id
        ]);
    } catch (Throwable $e) {
        if ($transaction_started) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }

        error_log(
            'ECHOTECH ADMIN ONLINE ORDERS: ' .
            $e->getMessage()
        );

        oo_json([
            'success' => false,
            'message' => $e->getMessage()
        ], 422);
    }
}

/* =========================================================
   FILTERS
========================================================= */

$filter_status = trim(
    (string)($_GET['status'] ?? '')
);

$allowed_filters = [
    '',
    'Pending',
    'Processing',
    'Completed',
    'Cancelled'
];

if (!in_array($filter_status, $allowed_filters, true)) {
    $filter_status = '';
}

$filter_branch = (int)($_GET['branch_id'] ?? 0);

$branches = oo_rows(
    $conn,
    "
    SELECT
        id,
        branch_code,
        branch_name,
        location
    FROM branches
    WHERE pharmacy_id = ?
    ORDER BY branch_name ASC
    ",
    'i',
    [$pharmacy_id]
);

$valid_branch_ids = [];

foreach ($branches as $branch) {
    $valid_branch_ids[] = (int)$branch['id'];
}

if (
    $filter_branch > 0 &&
    !in_array($filter_branch, $valid_branch_ids, true)
) {
    $filter_branch = 0;
}

/* =========================================================
   ORDER LIST
========================================================= */

$where = [
    'co.pharmacy_id = ?'
];

$types = 'i';
$params = [$pharmacy_id];

if ($filter_branch > 0) {
    $where[] = 'co.branch_id = ?';
    $types .= 'i';
    $params[] = $filter_branch;
}

if ($filter_status !== '') {
    $where[] = 'co.status = ?';
    $types .= 's';
    $params[] = $filter_status;
}

$where_sql = implode(' AND ', $where);

$orders = oo_rows(
    $conn,
    "
    SELECT
        co.id,
        co.client_id,
        co.order_number,
        co.total_amount,
        co.payment_method,
        co.status,
        co.order_date,
        co.branch_id,
        b.branch_name,
        c.full_name,
        c.phone
    FROM clients_orders co
    LEFT JOIN branches b
        ON b.id = co.branch_id
       AND b.pharmacy_id = co.pharmacy_id
    LEFT JOIN clients c
        ON c.id = co.client_id
    WHERE {$where_sql}
    ORDER BY co.id DESC
    LIMIT 300
    ",
    $types,
    $params
);

/* =========================================================
   SUMMARY COUNTS
========================================================= */

$counts = [
    'Pending' => 0,
    'Processing' => 0,
    'Completed' => 0,
    'Cancelled' => 0
];

$summary_where = 'pharmacy_id = ?';
$summary_types = 'i';
$summary_params = [$pharmacy_id];

if ($filter_branch > 0) {
    $summary_where .= ' AND branch_id = ?';
    $summary_types .= 'i';
    $summary_params[] = $filter_branch;
}

$summary_rows = oo_rows(
    $conn,
    "
    SELECT
        status,
        COUNT(*) AS total
    FROM clients_orders
    WHERE {$summary_where}
    GROUP BY status
    ",
    $summary_types,
    $summary_params
);

foreach ($summary_rows as $row) {
    $status = (string)$row['status'];

    if (isset($counts[$status])) {
        $counts[$status] = (int)$row['total'];
    }
}

$total_online_orders = array_sum($counts);

$pending_value = 0.00;
$processing_value = 0.00;
$completed_value = 0.00;

$value_rows = oo_rows(
    $conn,
    "
    SELECT
        status,
        COALESCE(SUM(total_amount), 0) AS total_value
    FROM clients_orders
    WHERE {$summary_where}
    GROUP BY status
    ",
    $summary_types,
    $summary_params
);

foreach ($value_rows as $row) {
    $status = (string)$row['status'];
    $value = (float)$row['total_value'];

    if ($status === 'Pending') {
        $pending_value = $value;
    } elseif ($status === 'Processing') {
        $processing_value = $value;
    } elseif ($status === 'Completed') {
        $completed_value = $value;
    }
}

$user_role = current_role();
$user_display_name = current_user();
$branch_count = count($branches);
$total_orders = $total_online_orders;

$current_admin_page = 'online_orders.php';
$admin_page_title = 'Online Orders';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Online Orders | PHARMACY POS</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        :root{
            --oo-blue:#17324d;
            --oo-blue-2:#214a70;
            --oo-bg:#f4f6f9;
            --oo-card:#ffffff;
            --oo-text:#17202a;
            --oo-muted:#718096;
            --oo-border:#e5e9ef;
            --oo-green:#0f9d67;
            --oo-red:#c0392b;
            --oo-yellow:#a66a00;
            --oo-purple:#5b50b6;
        }

        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            background:var(--oo-bg);
            color:var(--oo-text);
            font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;
        }

        .admin-main{
            min-height:100vh;
        }

        .content{
            padding:24px;
        }

        .oo-page-head{
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:18px;
            margin-bottom:20px;
            flex-wrap:wrap;
        }

        .oo-page-title{
            margin:0;
            color:var(--oo-blue);
            font-size:25px;
            line-height:1.2;
            font-weight:800;
        }

        .oo-page-subtitle{
            margin-top:6px;
            color:var(--oo-muted);
            font-size:13px;
        }

        .oo-refresh{
            border:1px solid var(--oo-border);
            background:#fff;
            color:var(--oo-blue);
            border-radius:9px;
            padding:9px 13px;
            font-weight:700;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:7px;
        }

        .oo-refresh:hover{
            background:#f8fafc;
        }

        .oo-kpis{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .oo-kpi{
            background:var(--oo-card);
            border:1px solid var(--oo-border);
            border-radius:13px;
            padding:16px;
            box-shadow:0 2px 10px rgba(15,23,42,.035);
        }

        .oo-kpi-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
        }

        .oo-kpi-label{
            color:var(--oo-muted);
            font-size:12px;
            font-weight:700;
        }

        .oo-kpi-icon{
            width:34px;
            height:34px;
            border-radius:9px;
            display:grid;
            place-items:center;
            background:#edf3f8;
            color:var(--oo-blue);
        }

        .oo-kpi-value{
            margin-top:8px;
            font-size:25px;
            font-weight:800;
            color:var(--oo-blue);
        }

        .oo-kpi-note{
            margin-top:4px;
            font-size:11px;
            color:var(--oo-muted);
        }

        .oo-card{
            background:#fff;
            border:1px solid var(--oo-border);
            border-radius:14px;
            box-shadow:0 2px 12px rgba(15,23,42,.035);
        }

        .oo-filter-card{
            padding:16px;
            margin-bottom:16px;
        }

        .oo-filter-grid{
            display:grid;
            grid-template-columns:1.2fr 1fr auto;
            gap:12px;
            align-items:end;
        }

        .oo-field label{
            display:block;
            margin-bottom:6px;
            font-size:11px;
            color:#697586;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.04em;
        }

        .oo-select{
            width:100%;
            min-height:40px;
            border:1px solid #dce3ea;
            border-radius:8px;
            background:#fff;
            padding:8px 11px;
            color:#253142;
            outline:none;
        }

        .oo-filter-actions{
            display:flex;
            gap:8px;
        }

        .oo-btn{
            border:0;
            border-radius:8px;
            padding:9px 13px;
            font-size:12px;
            font-weight:800;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            white-space:nowrap;
        }

        .oo-btn-primary{
            background:var(--oo-blue);
            color:#fff;
        }

        .oo-btn-light{
            background:#fff;
            color:#4a5568;
            border:1px solid var(--oo-border);
        }

        .oo-btn-success{
            background:var(--oo-green);
            color:#fff;
        }

        .oo-btn-danger{
            background:var(--oo-red);
            color:#fff;
        }

        .oo-orders-card{
            overflow:hidden;
        }

        .oo-card-head{
            padding:16px 18px;
            border-bottom:1px solid var(--oo-border);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .oo-card-title{
            margin:0;
            color:var(--oo-blue);
            font-size:15px;
            font-weight:800;
        }

        .oo-card-meta{
            color:var(--oo-muted);
            font-size:12px;
        }

        .oo-tabs{
            display:flex;
            gap:7px;
            overflow:auto;
            padding:12px 16px 0;
        }

        .oo-tab{
            border:1px solid #dfe6ed;
            background:#fff;
            color:#526173;
            border-radius:999px;
            padding:7px 12px;
            text-decoration:none;
            font-weight:800;
            font-size:11px;
            white-space:nowrap;
        }

        .oo-tab.active{
            background:var(--oo-blue);
            color:#fff;
            border-color:var(--oo-blue);
        }

        .oo-table-wrap{
            overflow-x:auto;
        }

        .oo-table{
            width:100%;
            border-collapse:collapse;
            min-width:850px;
        }

        .oo-table th{
            padding:12px 15px;
            text-align:left;
            color:#7b8794;
            background:#fbfcfd;
            border-bottom:1px solid var(--oo-border);
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:.055em;
            font-weight:800;
        }

        .oo-table td{
            padding:13px 15px;
            border-bottom:1px solid #edf1f5;
            vertical-align:middle;
            font-size:12px;
        }

        .oo-table tbody tr:hover{
            background:#fbfdff;
        }

        .oo-order-number{
            color:var(--oo-blue);
            font-weight:800;
        }

        .oo-customer{
            font-weight:800;
            color:#263445;
        }

        .oo-small{
            color:var(--oo-muted);
            font-size:11px;
            margin-top:2px;
        }

        .oo-money{
            font-weight:800;
            color:#243447;
            white-space:nowrap;
        }

        .oo-status{
            display:inline-flex;
            align-items:center;
            padding:5px 9px;
            border-radius:999px;
            font-size:10px;
            font-weight:900;
            white-space:nowrap;
        }

        .oo-status-pending{
            background:#fff3cd;
            color:#8a5a00;
        }

        .oo-status-processing{
            background:#e9e8ff;
            color:#5147a5;
        }

        .oo-status-completed{
            background:#dcfce7;
            color:#166534;
        }

        .oo-status-cancelled{
            background:#fee2e2;
            color:#991b1b;
        }

        .oo-payment{
            color:#465568;
            font-weight:700;
        }

        .oo-actions{
            display:flex;
            gap:6px;
            flex-wrap:wrap;
        }

        .oo-empty{
            padding:55px 20px;
            text-align:center;
            color:var(--oo-muted);
        }

        .oo-empty-icon{
            width:52px;
            height:52px;
            margin:0 auto 12px;
            border-radius:14px;
            display:grid;
            place-items:center;
            background:#eef3f7;
            color:#75879a;
            font-size:20px;
        }

        .oo-modal{
            position:fixed;
            inset:0;
            background:rgba(15,23,42,.52);
            display:none;
            align-items:center;
            justify-content:center;
            padding:18px;
            z-index:9999;
        }

        .oo-modal.open{
            display:flex;
        }

        .oo-dialog{
            width:min(760px,100%);
            max-height:92vh;
            overflow:auto;
            background:#fff;
            border-radius:16px;
            box-shadow:0 25px 70px rgba(0,0,0,.22);
        }

        .oo-dialog-head{
            padding:16px 18px;
            border-bottom:1px solid var(--oo-border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }

        .oo-dialog-title{
            color:var(--oo-blue);
            font-weight:900;
            font-size:16px;
        }

        .oo-close{
            border:0;
            background:#f1f4f7;
            color:#536273;
            width:34px;
            height:34px;
            border-radius:50%;
            cursor:pointer;
            font-size:18px;
        }

        .oo-dialog-body{
            padding:18px;
        }

        .oo-detail-grid{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:10px;
            margin-bottom:16px;
        }

        .oo-detail{
            background:#f8fafc;
            border:1px solid #edf1f5;
            border-radius:10px;
            padding:11px;
        }

        .oo-detail-label{
            color:#7b8794;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.04em;
        }

        .oo-detail-value{
            margin-top:4px;
            color:#263445;
            font-size:12px;
            font-weight:800;
        }

        .oo-items{
            border-top:1px solid var(--oo-border);
        }

        .oo-item{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:15px;
            padding:12px 0;
            border-bottom:1px solid #edf1f5;
        }

        .oo-item-name{
            font-weight:800;
            color:#263445;
        }

        .oo-item-meta{
            margin-top:3px;
            color:var(--oo-muted);
            font-size:11px;
        }

        .oo-item-price{
            text-align:right;
            white-space:nowrap;
            font-weight:800;
            color:#263445;
        }

        .oo-total{
            display:flex;
            justify-content:flex-end;
            margin-top:15px;
            font-size:17px;
            color:var(--oo-blue);
            font-weight:900;
        }

        .oo-loading{
            padding:35px;
            text-align:center;
            color:var(--oo-muted);
        }

        @media(max-width:1000px){
            .oo-kpis{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }

            .oo-filter-grid{
                grid-template-columns:1fr 1fr;
            }

            .oo-filter-actions{
                grid-column:1/-1;
            }
        }

        @media(max-width:700px){
            .content{
                padding:15px;
            }

            .oo-page-title{
                font-size:21px;
            }

            .oo-kpis{
                grid-template-columns:1fr 1fr;
                gap:9px;
            }

            .oo-kpi{
                padding:13px;
            }

            .oo-kpi-value{
                font-size:21px;
            }

            .oo-filter-grid{
                grid-template-columns:1fr;
            }

            .oo-filter-actions{
                grid-column:auto;
            }

            .oo-filter-actions .oo-btn{
                flex:1;
            }

            .oo-detail-grid{
                grid-template-columns:1fr;
            }

            .oo-dialog-body{
                padding:14px;
            }
        }
    </style>
</head>

<body>

<?php require __DIR__ . '/actions/admin_aside.php'; ?>

<div class="admin-main">

    <?php require __DIR__ . '/actions/admin_header.php'; ?>

    <main class="content">

        <div class="oo-page-head">
            <div>
                <h1 class="oo-page-title">
                    Online Orders
                </h1>

                <div class="oo-page-subtitle">
                    Manage customer orders received from the online pharmacy store.
                    Completing an order deducts stock and records it in Transactions and Sales Report.
                </div>
            </div>

            <button
                type="button"
                class="oo-refresh"
                onclick="window.location.reload()"
            >
                <i class="fas fa-rotate"></i>
                Refresh
            </button>
        </div>

        <section class="oo-kpis">

            <div class="oo-kpi">
                <div class="oo-kpi-top">
                    <div class="oo-kpi-label">Pending Orders</div>
                    <div class="oo-kpi-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($counts['Pending']) ?>
                </div>

                <div class="oo-kpi-note">
                    K<?= number_format($pending_value, 2) ?> order value
                </div>
            </div>

            <div class="oo-kpi">
                <div class="oo-kpi-top">
                    <div class="oo-kpi-label">Processing</div>
                    <div class="oo-kpi-icon">
                        <i class="fas fa-box-open"></i>
                    </div>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($counts['Processing']) ?>
                </div>

                <div class="oo-kpi-note">
                    K<?= number_format($processing_value, 2) ?> order value
                </div>
            </div>

            <div class="oo-kpi">
                <div class="oo-kpi-top">
                    <div class="oo-kpi-label">Completed</div>
                    <div class="oo-kpi-icon">
                        <i class="fas fa-circle-check"></i>
                    </div>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($counts['Completed']) ?>
                </div>

                <div class="oo-kpi-note">
                    K<?= number_format($completed_value, 2) ?> completed value
                </div>
            </div>

            <div class="oo-kpi">
                <div class="oo-kpi-top">
                    <div class="oo-kpi-label">All Online Orders</div>
                    <div class="oo-kpi-icon">
                        <i class="fas fa-bag-shopping"></i>
                    </div>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($total_online_orders) ?>
                </div>

                <div class="oo-kpi-note">
                    Pharmacy-wide order count
                </div>
            </div>

        </section>

        <section class="oo-card oo-filter-card">

            <form method="get">

                <div class="oo-filter-grid">

                    <div class="oo-field">
                        <label for="ooBranch">Branch</label>

                        <select
                            class="oo-select"
                            id="ooBranch"
                            name="branch_id"
                        >
                            <option value="0">
                                All Branches
                            </option>

                            <?php foreach ($branches as $branch): ?>
                                <option
                                    value="<?= (int)$branch['id'] ?>"
                                    <?= $filter_branch === (int)$branch['id'] ? 'selected' : '' ?>
                                >
                                    <?= oo_e($branch['branch_name']) ?>
                                    <?php if (!empty($branch['branch_code'])): ?>
                                        — <?= oo_e($branch['branch_code']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="oo-field">
                        <label for="ooStatus">Status</label>

                        <select
                            class="oo-select"
                            id="ooStatus"
                            name="status"
                        >
                            <option
                                value=""
                                <?= $filter_status === '' ? 'selected' : '' ?>
                            >
                                All Statuses
                            </option>

                            <?php foreach (array_keys($counts) as $status): ?>
                                <option
                                    value="<?= oo_e($status) ?>"
                                    <?= $filter_status === $status ? 'selected' : '' ?>
                                >
                                    <?= oo_e($status) ?>
                                    (<?= number_format($counts[$status]) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="oo-filter-actions">

                        <button
                            type="submit"
                            class="oo-btn oo-btn-primary"
                        >
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>

                        <?php if ($filter_branch > 0 || $filter_status !== ''): ?>
                            <a
                                href="online_orders.php"
                                class="oo-btn oo-btn-light"
                                title="Clear filters"
                            >
                                <i class="fas fa-xmark"></i>
                                Reset
                            </a>
                        <?php endif; ?>

                    </div>

                </div>

            </form>

        </section>

        <section class="oo-card oo-orders-card">

            <div class="oo-card-head">

                <div>
                    <h2 class="oo-card-title">
                        Customer Orders
                    </h2>

                    <div class="oo-card-meta">
                        Showing up to 300 latest orders for the selected scope.
                    </div>
                </div>

                <div class="oo-card-meta">
                    <?= number_format(count($orders)) ?> displayed
                </div>

            </div>

            <div class="oo-tabs">

                <?php
                $all_query = [];

                if ($filter_branch > 0) {
                    $all_query['branch_id'] = $filter_branch;
                }

                $all_url = 'online_orders.php';

                if ($all_query) {
                    $all_url .= '?' . http_build_query($all_query);
                }
                ?>

                <a
                    class="oo-tab <?= $filter_status === '' ? 'active' : '' ?>"
                    href="<?= oo_e($all_url) ?>"
                >
                    All
                    (<?= number_format($total_online_orders) ?>)
                </a>

                <?php foreach (array_keys($counts) as $status): ?>

                    <?php
                    $status_query = [
                        'status' => $status
                    ];

                    if ($filter_branch > 0) {
                        $status_query['branch_id'] = $filter_branch;
                    }

                    $status_url =
                        'online_orders.php?' .
                        http_build_query($status_query);
                    ?>

                    <a
                        class="oo-tab <?= $filter_status === $status ? 'active' : '' ?>"
                        href="<?= oo_e($status_url) ?>"
                    >
                        <?= oo_e($status) ?>
                        (<?= number_format($counts[$status]) ?>)
                    </a>

                <?php endforeach; ?>

            </div>

            <div class="oo-table-wrap">

                <table class="oo-table">

                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th>Payment</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (!$orders): ?>

                        <tr>
                            <td colspan="8">
                                <div class="oo-empty">

                                    <div class="oo-empty-icon">
                                        <i class="fas fa-bag-shopping"></i>
                                    </div>

                                    <strong>
                                        No online orders found
                                    </strong>

                                    <div class="oo-small">
                                        Try changing the branch or status filter.
                                    </div>

                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($orders as $order): ?>

                            <?php
                            $status =
                                (string)$order['status'];

                            $status_class = match ($status) {
                                'Pending' => 'oo-status-pending',
                                'Processing' => 'oo-status-processing',
                                'Completed' => 'oo-status-completed',
                                'Cancelled' => 'oo-status-cancelled',
                                default => 'oo-status-pending'
                            };

                            $payment =
                                (string)($order['payment_method'] ?? '');

                            $payment_display = match (
                                strtolower(trim($payment))
                            ) {
                                'cash',
                                'cod',
                                'cash on delivery'
                                    => 'Cash on Delivery',

                                'bank',
                                'bank transfer',
                                'bank / transfer'
                                    => 'Bank Transfer',

                                'mobile',
                                'mobile money',
                                'momo'
                                    => 'Mobile Money',

                                default => (
                                    $payment !== ''
                                        ? $payment
                                        : 'Not specified'
                                )
                            };
                            ?>

                            <tr>

                                <td>
                                    <div class="oo-order-number">
                                        #<?= oo_e($order['order_number']) ?>
                                    </div>

                                    <div class="oo-small">
                                        ID <?= (int)$order['id'] ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="oo-customer">
                                        <?= oo_e(
                                            $order['full_name'] ?: 'Customer'
                                        ) ?>
                                    </div>

                                    <?php if (!empty($order['phone'])): ?>
                                        <div class="oo-small">
                                            <?= oo_e($order['phone']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="oo-customer">
                                        <?= oo_e(
                                            $order['branch_name'] ?: 'Unknown branch'
                                        ) ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="oo-payment">
                                        <?= oo_e($payment_display) ?>
                                    </div>
                                </td>

                                <td>
                                    <div>
                                        <?= oo_e($order['order_date']) ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="oo-money">
                                        K<?= number_format(
                                            (float)$order['total_amount'],
                                            2
                                        ) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="oo-status <?= $status_class ?>">
                                        <?= oo_e($status) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="oo-actions">

                                        <button
                                            type="button"
                                            class="oo-btn oo-btn-light"
                                            onclick="viewOrder(<?= (int)$order['id'] ?>)"
                                        >
                                            <i class="fas fa-eye"></i>
                                            View
                                        </button>

                                        <?php if ($status === 'Pending'): ?>

                                            <button
                                                type="button"
                                                class="oo-btn oo-btn-success"
                                                onclick="changeStatus(
                                                    <?= (int)$order['id'] ?>,
                                                    'Processing'
                                                )"
                                            >
                                                <i class="fas fa-check"></i>
                                                Accept
                                            </button>

                                            <button
                                                type="button"
                                                class="oo-btn oo-btn-danger"
                                                onclick="changeStatus(
                                                    <?= (int)$order['id'] ?>,
                                                    'Cancelled'
                                                )"
                                            >
                                                <i class="fas fa-xmark"></i>
                                                Cancel
                                            </button>

                                        <?php elseif ($status === 'Processing'): ?>

                                            <button
                                                type="button"
                                                class="oo-btn oo-btn-success"
                                                onclick="changeStatus(
                                                    <?= (int)$order['id'] ?>,
                                                    'Completed'
                                                )"
                                            >
                                                <i class="fas fa-circle-check"></i>
                                                Complete
                                            </button>

                                            <button
                                                type="button"
                                                class="oo-btn oo-btn-danger"
                                                onclick="changeStatus(
                                                    <?= (int)$order['id'] ?>,
                                                    'Cancelled'
                                                )"
                                            >
                                                <i class="fas fa-xmark"></i>
                                                Cancel
                                            </button>

                                        <?php endif; ?>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

<div
    class="oo-modal"
    id="ooModal"
    aria-hidden="true"
>
    <div class="oo-dialog">

        <div class="oo-dialog-head">

            <div
                class="oo-dialog-title"
                id="ooTitle"
            >
                Order
            </div>

            <button
                type="button"
                class="oo-close"
                onclick="closeOrder()"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div
            class="oo-dialog-body"
            id="ooBody"
        >
            <div class="oo-loading">
                Loading order...
            </div>
        </div>

    </div>
</div>

<script>
const OO_CSRF = <?= json_encode($csrf_token) ?>;

function ooEsc(value) {
    return String(value ?? '').replace(
        /[&<>"']/g,
        function (match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[match];
        }
    );
}

function ooMoney(value) {
    const number = Number(value ?? 0);

    return 'K' + number.toLocaleString(
        'en-ZM',
        {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }
    );
}

function ooStatusClass(status) {
    if (status === 'Pending') {
        return 'oo-status oo-status-pending';
    }

    if (status === 'Processing') {
        return 'oo-status oo-status-processing';
    }

    if (status === 'Completed') {
        return 'oo-status oo-status-completed';
    }

    return 'oo-status oo-status-cancelled';
}

function openOrderModal() {
    const modal = document.getElementById('ooModal');

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeOrder() {
    const modal = document.getElementById('ooModal');

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
}

async function viewOrder(id) {
    const body = document.getElementById('ooBody');

    body.innerHTML =
        '<div class="oo-loading">' +
        '<i class="fas fa-spinner fa-spin"></i> Loading order...' +
        '</div>';

    openOrderModal();

    try {
        const response = await fetch(
            'online_orders.php?action=order&id=' +
            encodeURIComponent(id),
            {
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (!data.success) {
            throw new Error(
                data.message || 'Unable to load order.'
            );
        }

        const order = data.order;
        const items = Array.isArray(data.items)
            ? data.items
            : [];

        document.getElementById('ooTitle').textContent =
            '#' + (order.order_number || order.id);

        const payment = order.payment_method || 'Not specified';

        let html = '';

        html += '<div class="oo-detail-grid">';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">Customer</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(order.full_name || 'Customer') +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">Phone</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(order.phone || 'Not provided') +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">Branch</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(order.branch_name || 'Unknown branch') +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">Payment Method</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(payment) +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">Order Date</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(order.order_date || '') +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">Status</div>' +
            '<div class="oo-detail-value">' +
            '<span class="' +
            ooStatusClass(order.status) +
            '">' +
            ooEsc(order.status || '') +
            '</span>' +
            '</div>' +
            '</div>';

        html += '</div>';

        html += '<div class="oo-items">';

        if (!items.length) {
            html +=
                '<div class="oo-empty">' +
                'This order has no items.' +
                '</div>';
        } else {
            items.forEach(function (item) {
                const qty = Number(item.quantity || 0);
                const price = Number(item.price_at_purchase || 0);
                const lineTotal = qty * price;

                html +=
                    '<div class="oo-item">' +
                    '<div>' +
                    '<div class="oo-item-name">' +
                    ooEsc(item.item_name || 'Product') +
                    '</div>' +
                    '<div class="oo-item-meta">' +
                    ooEsc(item.strength || '') +
                    (item.strength ? ' · ' : '') +
                    'Qty: ' + qty +
                    (item.barcode ? ' · Barcode: ' + ooEsc(item.barcode) : '') +
                    '</div>' +
                    '</div>' +
                    '<div class="oo-item-price">' +
                    ooMoney(lineTotal) +
                    '<div class="oo-item-meta">' +
                    ooMoney(price) +
                    ' each' +
                    '</div>' +
                    '</div>' +
                    '</div>';
            });
        }

        html += '</div>';

        html +=
            '<div class="oo-total">' +
            'Total: ' +
            ooMoney(order.total_amount) +
            '</div>';

        body.innerHTML = html;

    } catch (error) {
        body.innerHTML =
            '<div class="oo-empty">' +
            '<div class="oo-empty-icon">' +
            '<i class="fas fa-triangle-exclamation"></i>' +
            '</div>' +
            '<strong>Unable to load order</strong>' +
            '<div class="oo-small">' +
            ooEsc(error.message || 'Please try again.') +
            '</div>' +
            '</div>';
    }
}

async function changeStatus(id, status) {
    let message = 'Change this order to ' + status + '?';

    if (status === 'Processing') {
        message =
            'Accept this online order and move it to Processing?';
    }

    if (status === 'Completed') {
        message =
            'Complete this order?\n\n' +
            'This will deduct the ordered quantities from the ' +
            'order branch stock and record the completed sale in ' +
            'Transactions and Sales Report.';
    }

    if (status === 'Cancelled') {
        message =
            'Cancel this online order?\n\n' +
            'No stock will be deducted.';
    }

    if (!window.confirm(message)) {
        return;
    }

    const buttons = document.querySelectorAll(
        'button[onclick*="changeStatus(' +
        id +
        ',"]'
    );

    buttons.forEach(function (button) {
        button.disabled = true;
    });

    const formData = new FormData();

    formData.append('action', 'update_status');
    formData.append('order_id', String(id));
    formData.append('status', status);
    formData.append('csrf_token', OO_CSRF);

    try {
        const response = await fetch(
            'online_orders.php',
            {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            }
        );

        const data = await response.json();

        if (!data.success) {
            throw new Error(
                data.message || 'Unable to update order.'
            );
        }

        window.alert(data.message || 'Order updated successfully.');

        window.location.reload();

    } catch (error) {
        window.alert(
            error.message ||
            'Unable to update the order.'
        );

        buttons.forEach(function (button) {
            button.disabled = false;
        });
    }
}

document
    .getElementById('ooModal')
    .addEventListener('click', function (event) {
        if (event.target.id === 'ooModal') {
            closeOrder();
        }
    });

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeOrder();
    }
});

/*
 * Refresh every 60 seconds so newly submitted online orders
 * appear without requiring manual navigation.
 */
setTimeout(function () {
    window.location.reload();
}, 60000);
</script>

</body>
</html>
