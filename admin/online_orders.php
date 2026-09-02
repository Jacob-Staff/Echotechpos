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
   ECHOTECH POS â€” ADMIN ONLINE ORDERS
   Report-style admin architecture.

   Important:
   - Uses the SAME shared admin header + aside as the dashboard.
   - The page itself supplies only its content.
   - Pharmacy-wide admin view with optional branch filter.
   - Processing -> Completed is the only stock-deducting step.
   - Completed orders are recorded in sales + sales_items.
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

function oo_scalar(
    mysqli $conn,
    string $sql,
    string $types = '',
    array $params = [],
    mixed $default = 0
): mixed {
    $rows = oo_rows($conn, $sql, $types, $params);

    if (!$rows) {
        return $default;
    }

    $value = array_values($rows[0])[0] ?? $default;

    return $value;
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

function oo_payment_display(string $value): string
{
    $normalized = strtolower(trim($value));

    return match ($normalized) {
        'cash',
        'cod',
        'cash on delivery',
        'cash delivery'
            => 'Cash on Delivery',

        'bank',
        'bank transfer',
        'bank / transfer',
        'bank/transfer'
            => 'Bank Transfer',

        'mobile',
        'mobile money',
        'momo'
            => 'Mobile Money',

        default => $value !== '' ? $value : 'Not specified'
    };
}

function oo_payment_db_value(string $value): string
{
    return match ($value) {
        'Cash on Delivery' => 'Cash on Delivery',
        'Bank Transfer' => 'Bank Transfer',
        'Mobile Money' => 'Mobile Money',
        default => ''
    };
}

function oo_csrf_token(): string
{
    if (empty($_SESSION['admin_online_orders_csrf'])) {
        $_SESSION['admin_online_orders_csrf'] =
            bin2hex(random_bytes(32));
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
            'message' =>
                'Security token expired. Refresh the page and try again.'
        ], 419);
    }
}

function oo_date_filter(
    string $period,
    string $date_from,
    string $date_to
): array {
    $today = new DateTimeImmutable('today');

    $from = '';
    $to = '';
    $label = 'All dates';

    $validDate = static function (string $value): bool {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );

        return $date !== false &&
            $date->format('Y-m-d') === $value;
    };

    if ($period === 'today') {
        $from = $today->format('Y-m-d');
        $to = $from;
        $label = 'Today';
    } elseif ($period === 'yesterday') {
        $day = $today->modify('-1 day');
        $from = $day->format('Y-m-d');
        $to = $from;
        $label = 'Yesterday';
    } elseif ($period === 'this_week') {
        $fromDate = $today->modify('monday this week');
        $toDate = $fromDate->modify('+6 days');

        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');
        $label = 'This Week';
    } elseif ($period === 'last_week') {
        $fromDate = $today->modify('monday last week');
        $toDate = $fromDate->modify('+6 days');

        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');
        $label = 'Last Week';
    } elseif ($period === 'this_month') {
        $fromDate = $today->modify('first day of this month');
        $toDate = $today->modify('last day of this month');

        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');
        $label = 'This Month';
    } elseif ($period === 'last_month') {
        $fromDate = $today
            ->modify('first day of last month');

        $toDate = $today
            ->modify('last day of last month');

        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');
        $label = 'Last Month';
    } elseif ($period === 'this_year') {
        $fromDate = $today->setDate(
            (int)$today->format('Y'),
            1,
            1
        );

        $toDate = $today->setDate(
            (int)$today->format('Y'),
            12,
            31
        );

        $from = $fromDate->format('Y-m-d');
        $to = $toDate->format('Y-m-d');
        $label = 'This Year';
    } elseif ($period === 'last_year') {
        $year = (int)$today->format('Y') - 1;

        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $label = 'Last Year';
    } elseif ($period === 'custom') {
        if ($validDate($date_from)) {
            $from = $date_from;
        }

        if ($validDate($date_to)) {
            $to = $date_to;
        }

        if ($from !== '' && $to === '') {
            $to = $from;
        }

        if ($to !== '' && $from === '') {
            $from = $to;
        }

        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from !== '' || $to !== '') {
            $label = 'Custom Range';
        }
    }

    return [
        'from' => $from,
        'to' => $to,
        'label' => $label
    ];
}

function oo_query_params(
    int $pharmacy_id,
    int $branch_id,
    string $status,
    string $payment,
    string $customer_search,
    string $order_search,
    string $date_from,
    string $date_to,
    string $min_amount,
    string $max_amount,
    bool $include_status = true
): array {
    $where = [
        'co.pharmacy_id = ?'
    ];

    $types = 'i';
    $params = [$pharmacy_id];

    if ($branch_id > 0) {
        $where[] = 'co.branch_id = ?';
        $types .= 'i';
        $params[] = $branch_id;
    }

    if ($include_status && $status !== '') {
        $where[] = 'co.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($payment !== '') {
        $where[] = 'co.payment_method = ?';
        $types .= 's';
        $params[] = $payment;
    }

    if ($customer_search !== '') {
        $where[] = '
            (
                c.full_name LIKE ?
                OR c.phone LIKE ?
                OR c.email LIKE ?
            )
        ';

        $needle = '%' . $customer_search . '%';

        $types .= 'sss';
        $params[] = $needle;
        $params[] = $needle;
        $params[] = $needle;
    }

    if ($order_search !== '') {
        $where[] = 'co.order_number LIKE ?';
        $types .= 's';
        $params[] = '%' . $order_search . '%';
    }

    if ($date_from !== '') {
        $where[] = 'co.order_date >= ?';
        $types .= 's';
        $params[] = $date_from . ' 00:00:00';
    }

    if ($date_to !== '') {
        $where[] = 'co.order_date < DATE_ADD(?, INTERVAL 1 DAY)';
        $types .= 's';
        $params[] = $date_to . ' 00:00:00';
    }

    if ($min_amount !== '') {
        $where[] = 'co.total_amount >= ?';
        $types .= 'd';
        $params[] = (float)$min_amount;
    }

    if ($max_amount !== '') {
        $where[] = 'co.total_amount <= ?';
        $types .= 'd';
        $params[] = (float)$max_amount;
    }

    return [
        'sql' => implode(' AND ', $where),
        'types' => $types,
        'params' => $params
    ];
}

$csrf_token = oo_csrf_token();

$action = strtolower(
    trim((string)(
        $_POST['action'] ??
        $_GET['action'] ??
        ''
    ))
);

/* =========================================================
   AJAX: VIEW ORDER
========================================================= */

if ($action === 'order') {
    $order_id = (int)(
        $_GET['id'] ??
        $_POST['id'] ??
        0
    );

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
            [
                $order_id,
                $pharmacy_id
            ]
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
                si.barcode
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
        error_log(
            'ECHOTECH ADMIN ONLINE ORDER VIEW: ' .
            $e->getMessage()
        );

        oo_json([
            'success' => false,
            'message' => 'Unable to load this order.'
        ], 500);
    }
}

/* =========================================================
   POST: CHANGE ORDER STATUS
========================================================= */

if ($action === 'update_status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        oo_json([
            'success' => false,
            'message' => 'Invalid request method.'
        ], 405);
    }

    oo_require_csrf();

    $order_id = (int)(
        $_POST['order_id'] ?? 0
    );

    $new_status = trim(
        (string)($_POST['status'] ?? '')
    );

    $allowed_statuses = [
        'Processing',
        'Completed',
        'Cancelled'
    ];

    if (
        $order_id <= 0 ||
        !in_array(
            $new_status,
            $allowed_statuses,
            true
        )
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
         * Lock the order first. We deliberately use the order's
         * branch_id for all stock operations.
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
            throw new RuntimeException(
                'Unable to prepare order lookup.'
            );
        }

        $stmt->bind_param(
            'ii',
            $order_id,
            $pharmacy_id
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error);
        }

        $order = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$order) {
            throw new RuntimeException(
                'Order not found.'
            );
        }

        $current_status = (string)$order['status'];
        $order_branch_id = (int)$order['branch_id'];

        if ($order_branch_id <= 0) {
            throw new RuntimeException(
                'This order is not assigned to a valid branch.'
            );
        }

        $branch_rows = oo_rows(
            $conn,
            "
            SELECT id
            FROM branches
            WHERE id = ?
              AND pharmacy_id = ?
            LIMIT 1
            ",
            'ii',
            [
                $order_branch_id,
                $pharmacy_id
            ]
        );

        if (!$branch_rows) {
            throw new RuntimeException(
                'The order branch does not belong to this pharmacy.'
            );
        }

        /*
         * Allowed workflow:
         * Pending    -> Processing / Cancelled
         * Processing -> Completed / Cancelled
         * Completed  -> terminal
         * Cancelled  -> terminal
         */
        $valid_transition = false;

        if ($new_status === 'Processing') {
            $valid_transition =
                $current_status === 'Pending';
        } elseif ($new_status === 'Completed') {
            $valid_transition =
                $current_status === 'Processing';
        } elseif ($new_status === 'Cancelled') {
            $valid_transition = in_array(
                $current_status,
                [
                    'Pending',
                    'Processing'
                ],
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
         * =====================================================
         * COMPLETION
         *
         * Stock is deducted only here.
         * Every item must be fulfilled or the entire transaction
         * is rolled back.
         * =====================================================
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
                $product_id =
                    (int)($item['product_id'] ?? 0);

                $quantity =
                    (int)($item['quantity'] ?? 0);

                if (
                    $product_id <= 0 ||
                    $quantity <= 0
                ) {
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
                    $nameStmt = $conn->prepare(
                        "
                        SELECT
                            item_name,
                            quantity
                        FROM store_items
                        WHERE id = ?
                          AND pharmacy_id = ?
                          AND branch_id = ?
                        LIMIT 1
                        "
                    );

                    $product_name =
                        'One of the products';

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
                                if (
                                    !empty(
                                        $product['item_name']
                                    )
                                ) {
                                    $product_name =
                                        (string)$product['item_name'];
                                }

                                $available_qty =
                                    (int)(
                                        $product['quantity'] ?? 0
                                    );
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
             * =================================================
             * RECORD COMPLETED ORDER IN NORMAL POS SALES
             *
             * The supplied schema contains:
             * issued_by, invoice, client_reference, total,
             * payment, user_id, total_amount, subtotal,
             * vat_amount, payment_method, amount_received,
             * change_due, sale_date and created_at.
             * =================================================
             */

            $client_reference =
                'ONLINE_ORDER_' . $order_id;

            $online_payment_method =
                oo_payment_db_value(
                    oo_payment_display(
                        (string)(
                            $order['payment_method'] ?? ''
                        )
                    )
                );

            if ($online_payment_method === '') {
                throw new RuntimeException(
                    'Unable to determine the online payment method.'
                );
            }

            $sale_total = round(
                (float)(
                    $order['total_amount'] ?? 0
                ),
                2
            );

            if ($sale_total < 0) {
                throw new RuntimeException(
                    'The order has an invalid total amount.'
                );
            }

            /*
             * Duplicate protection.
             */
            $existingSale = oo_rows(
                $conn,
                "
                SELECT id
                FROM sales
                WHERE pharmacy_id = ?
                  AND branch_id = ?
                  AND client_reference = ?
                LIMIT 1
                FOR UPDATE
                ",
                'iis',
                [
                    $pharmacy_id,
                    $order_branch_id,
                    $client_reference
                ]
            );

            if ($existingSale) {
                $sale_id =
                    (int)$existingSale[0]['id'];
            } else {
                $issued_by =
                    'Online Customer';

                $invoice =
                    (string)$order['order_number'];

                $user_id =
                    (int)(
                        $_SESSION['user_id'] ?? 0
                    );

                $subtotal = $sale_total;
                $vat_amount = 0.00;
                $amount_received = $sale_total;
                $change_due = 0.00;

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

                $sale_id =
                    (int)$conn->insert_id;

                $saleStmt->close();

                if ($sale_id <= 0) {
                    throw new RuntimeException(
                        'Completed online transaction was not recorded.'
                    );
                }

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
         * Only change the order status after all completion
         * operations have succeeded.
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
            'message' => match ($new_status) {
                'Processing' =>
                    'Order accepted and moved to Processing.',

                'Completed' =>
                    'Order completed successfully. Stock was deducted and the sale was recorded.',

                default =>
                    'Order cancelled successfully.'
            },
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
   FILTER INPUT
========================================================= */

$period = trim(
    (string)($_GET['period'] ?? '')
);

$allowed_periods = [
    '',
    'today',
    'yesterday',
    'this_week',
    'last_week',
    'this_month',
    'last_month',
    'this_year',
    'last_year',
    'custom'
];

if (!in_array($period, $allowed_periods, true)) {
    $period = '';
}

$date_from_input = trim(
    (string)($_GET['date_from'] ?? '')
);

$date_to_input = trim(
    (string)($_GET['date_to'] ?? '')
);

$date_range = oo_date_filter(
    $period,
    $date_from_input,
    $date_to_input
);

$date_from = $date_range['from'];
$date_to = $date_range['to'];
$date_range_label = $date_range['label'];

$filter_status = trim(
    (string)($_GET['status'] ?? '')
);

$allowed_statuses = [
    '',
    'Pending',
    'Processing',
    'Completed',
    'Cancelled'
];

if (!in_array(
    $filter_status,
    $allowed_statuses,
    true
)) {
    $filter_status = '';
}

$filter_payment = trim(
    (string)($_GET['payment'] ?? '')
);

$allowed_payments = [
    '',
    'Cash on Delivery',
    'Bank Transfer',
    'Mobile Money'
];

if (!in_array(
    $filter_payment,
    $allowed_payments,
    true
)) {
    $filter_payment = '';
}

$filter_branch = (int)(
    $_GET['branch_id'] ?? 0
);

$customer_search = trim(
    (string)($_GET['customer'] ?? '')
);

$order_search = trim(
    (string)($_GET['order'] ?? '')
);

$min_amount = trim(
    (string)($_GET['min_amount'] ?? '')
);

$max_amount = trim(
    (string)($_GET['max_amount'] ?? '')
);

if (
    $min_amount !== '' &&
    (!is_numeric($min_amount) || (float)$min_amount < 0)
) {
    $min_amount = '';
}

if (
    $max_amount !== '' &&
    (!is_numeric($max_amount) || (float)$max_amount < 0)
) {
    $max_amount = '';
}

/* =========================================================
   PHARMACY NAME â€” REQUIRED BY SHARED DASHBOARD HEADER
========================================================= */

$pharmacy_name = 'PHARMACY POS';

$pharmacy_rows = oo_rows(
    $conn,
    "
    SELECT name
    FROM pharmacies
    WHERE id = ?
    LIMIT 1
    ",
    'i',
    [$pharmacy_id]
);

if (
    $pharmacy_rows &&
    !empty($pharmacy_rows[0]['name'])
) {
    $pharmacy_name =
        (string)$pharmacy_rows[0]['name'];
}

/* =========================================================
   BRANCHES
========================================================= */

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
    $valid_branch_ids[] =
        (int)$branch['id'];
}

if (
    $filter_branch > 0 &&
    !in_array(
        $filter_branch,
        $valid_branch_ids,
        true
    )
) {
    $filter_branch = 0;
}

/* =========================================================
   BASE FILTER
   Status is intentionally excluded from KPI counts so the
   dashboard cards continue to show all statuses in the
   currently selected date/branch/payment/customer/amount
   scope.
========================================================= */

$base = oo_query_params(
    $pharmacy_id,
    $filter_branch,
    '',
    $filter_payment,
    $customer_search,
    $order_search,
    $date_from,
    $date_to,
    $min_amount,
    $max_amount,
    false
);

$order_filter = oo_query_params(
    $pharmacy_id,
    $filter_branch,
    $filter_status,
    $filter_payment,
    $customer_search,
    $order_search,
    $date_from,
    $date_to,
    $min_amount,
    $max_amount,
    true
);

/* =========================================================
   ORDER LIST
========================================================= */

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
    WHERE {$order_filter['sql']}
    ORDER BY co.id DESC
    LIMIT 300
    ",
    $order_filter['types'],
    $order_filter['params']
);

/* =========================================================
   SUMMARY COUNTS / VALUES
========================================================= */

$counts = [
    'Pending' => 0,
    'Processing' => 0,
    'Completed' => 0,
    'Cancelled' => 0
];

$count_rows = oo_rows(
    $conn,
    "
    SELECT
        status,
        COUNT(*) AS total
    FROM clients_orders co
    LEFT JOIN clients c
        ON c.id = co.client_id
    WHERE {$base['sql']}
    GROUP BY status
    ",
    $base['types'],
    $base['params']
);

foreach ($count_rows as $row) {
    $status = (string)$row['status'];

    if (isset($counts[$status])) {
        $counts[$status] =
            (int)$row['total'];
    }
}

$value_rows = oo_rows(
    $conn,
    "
    SELECT
        status,
        COALESCE(SUM(co.total_amount), 0) AS total_value
    FROM clients_orders co
    LEFT JOIN clients c
        ON c.id = co.client_id
    WHERE {$base['sql']}
    GROUP BY status
    ",
    $base['types'],
    $base['params']
);

$pending_value = 0.00;
$processing_value = 0.00;
$completed_value = 0.00;
$all_order_value = 0.00;

foreach ($value_rows as $row) {
    $status =
        (string)$row['status'];

    $value =
        (float)$row['total_value'];

    $all_order_value += $value;

    if ($status === 'Pending') {
        $pending_value = $value;
    } elseif ($status === 'Processing') {
        $processing_value = $value;
    } elseif ($status === 'Completed') {
        $completed_value = $value;
    }
}

$total_online_orders =
    array_sum($counts);

/* =========================================================
   HEADER / ASIDE VARIABLES
========================================================= */

$user_role = current_role();
$user_display_name = current_user();
$branch_count = count($branches);

/*
 * The shared aside uses total_orders for its Transactions badge.
 * This must be the normal POS sales count, not online order count.
 */
$total_orders = (int)oo_scalar(
    $conn,
    "
    SELECT COUNT(id)
    FROM sales
    WHERE pharmacy_id = ?
    ",
    'i',
    [$pharmacy_id],
    0
);

$current_admin_page =
    'online_orders.php';

$admin_page_title =
    'Online Orders';

/* =========================================================
   QUERY STRING HELPERS FOR TABS
========================================================= */

$common_query = [];

if ($filter_branch > 0) {
    $common_query['branch_id'] =
        $filter_branch;
}

if ($period !== '') {
    $common_query['period'] =
        $period;
}

if ($period === 'custom') {
    if ($date_from_input !== '') {
        $common_query['date_from'] =
            $date_from_input;
    }

    if ($date_to_input !== '') {
        $common_query['date_to'] =
            $date_to_input;
    }
}

if ($filter_payment !== '') {
    $common_query['payment'] =
        $filter_payment;
}

if ($customer_search !== '') {
    $common_query['customer'] =
        $customer_search;
}

if ($order_search !== '') {
    $common_query['order'] =
        $order_search;
}

if ($min_amount !== '') {
    $common_query['min_amount'] =
        $min_amount;
}

if ($max_amount !== '') {
    $common_query['max_amount'] =
        $max_amount;
}

function oo_tab_url(
    array $common_query,
    string $status
): string {
    $query = $common_query;

    if ($status !== '') {
        $query['status'] = $status;
    }

    if (!$query) {
        return 'online_orders.php';
    }

    return 'online_orders.php?' .
        http_build_query($query);
}

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Online Orders | <?= oo_e($pharmacy_name) ?>
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        /*
         * IMPORTANT:
         * admin_header.php and admin_aside.php supply the global
         * dashboard shell. This page only supplies page content.
         */
        .admin-main{
            margin-left:var(--sidebar);
            min-height:100vh;
        }

        .content{
            max-width:1600px;
            margin:0 auto;
            padding:26px 28px 40px;
        }

        .oo-page-head{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:18px;
            margin-bottom:17px;
        }

        .oo-page-head h1{
            margin:0;
            color:var(--charcoal);
            font-size:27px;
            line-height:1.15;
            font-weight:800;
            letter-spacing:-.6px;
        }

        .oo-page-head p{
            margin:6px 0 0;
            color:var(--muted);
            font-size:12px;
            line-height:1.55;
            max-width:900px;
        }

        .oo-head-actions{
            display:flex;
            gap:8px;
            flex-shrink:0;
        }

        .oo-btn{
            height:38px;
            padding:0 13px;
            border-radius:8px;
            border:1px solid var(--border);
            background:#fff;
            color:#4e5a66;
            font-size:12px;
            font-weight:700;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:7px;
            cursor:pointer;
            text-decoration:none;
        }

        .oo-btn:hover{
            box-shadow:0 3px 10px rgba(30,50,80,.1);
        }

        .oo-btn.primary{
            background:var(--blue);
            border-color:var(--blue);
            color:#fff;
        }

        .oo-btn.success{
            background:var(--green);
            border-color:var(--green);
            color:#fff;
        }

        .oo-btn.danger{
            background:var(--red);
            border-color:var(--red);
            color:#fff;
        }

        .oo-filter-card,
        .oo-orders-card{
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
        }

        .oo-filter-card{
            margin-bottom:14px;
            padding:15px 17px 16px;
        }

        .oo-filter-title{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-bottom:12px;
        }

        .oo-filter-title b{
            color:var(--charcoal);
            font-size:13px;
        }

        .oo-filter-title span{
            color:var(--muted);
            font-size:10px;
        }

        .oo-filter-grid{
            display:grid;
            grid-template-columns:1.05fr 1fr 1fr 1fr;
            gap:11px;
            align-items:end;
        }

        .oo-field label{
            display:block;
            margin-bottom:6px;
            color:#707b86;
            font-size:9px;
            text-transform:uppercase;
            letter-spacing:.75px;
            font-weight:800;
        }

        .oo-control{
            width:100%;
            height:38px;
            padding:0 10px;
            border:1px solid var(--border);
            border-radius:8px;
            background:#fff;
            color:var(--text);
            font-size:12px;
            outline:none;
        }

        .oo-control:focus{
            border-color:#8bb0ff;
            box-shadow:0 0 0 3px var(--blue-soft);
        }

        .oo-filter-wide{
            grid-column:span 2;
        }

        .oo-filter-actions{
            display:flex;
            gap:8px;
            align-items:end;
            justify-content:flex-end;
        }

        .oo-quick-periods{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
            margin-top:12px;
            padding-top:11px;
            border-top:1px solid #edf0f3;
        }

        .oo-quick{
            height:29px;
            padding:0 10px;
            border:1px solid var(--border);
            background:#fff;
            color:#65717d;
            border-radius:7px;
            font-size:10px;
            font-weight:800;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
        }

        .oo-quick:hover{
            color:var(--blue);
            border-color:#a9c0ec;
        }

        .oo-quick.active{
            background:var(--blue-soft);
            border-color:#b8ccf8;
            color:var(--blue);
        }

        .oo-date-row{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:8px;
        }

        .oo-kpis{
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:13px;
            margin-bottom:14px;
        }

        .oo-kpi{
            position:relative;
            overflow:hidden;
            background:#fff;
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:17px;
            min-height:116px;
            box-shadow:var(--shadow);
        }

        .oo-kpi:after{
            content:"";
            position:absolute;
            width:90px;
            height:90px;
            border-radius:50%;
            right:-34px;
            bottom:-43px;
            background:rgba(36,107,254,.055);
        }

        .oo-kpi-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            color:#727d88;
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:.8px;
            font-weight:800;
        }

        .oo-kpi-icon{
            width:34px;
            height:34px;
            border-radius:8px;
            background:var(--blue-soft);
            display:grid;
            place-items:center;
            color:var(--blue);
            font-size:13px;
        }

        .oo-kpi-value{
            color:var(--charcoal);
            font-size:25px;
            font-weight:800;
            margin-top:9px;
            letter-spacing:-.5px;
        }

        .oo-kpi-sub{
            color:var(--muted);
            font-size:10px;
            margin-top:4px;
        }

        .oo-kpi.green .oo-kpi-icon{
            color:var(--green);
            background:var(--green-soft);
        }

        .oo-kpi.yellow .oo-kpi-icon{
            color:var(--yellow);
            background:var(--yellow-soft);
        }

        .oo-kpi.purple .oo-kpi-icon{
            color:var(--purple);
            background:#f0edff;
        }

        .oo-orders-card{
            overflow:hidden;
        }

        .oo-card-head{
            min-height:54px;
            padding:0 17px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            background:#fff;
            border-bottom:1px solid var(--border);
        }

        .oo-card-head b{
            color:var(--charcoal);
            font-size:13px;
        }

        .oo-card-head span{
            color:var(--muted);
            font-size:10px;
        }

        .oo-tabs{
            display:flex;
            gap:7px;
            overflow:auto;
            padding:12px 16px 0;
        }

        .oo-tab{
            border:1px solid var(--border);
            background:#fff;
            color:#5c6975;
            border-radius:999px;
            padding:7px 11px;
            text-decoration:none;
            font-size:10px;
            font-weight:800;
            white-space:nowrap;
        }

        .oo-tab:hover{
            border-color:#a9c0ec;
            color:var(--blue);
        }

        .oo-tab.active{
            background:var(--blue);
            border-color:var(--blue);
            color:#fff;
        }

        .oo-table-wrap{
            overflow-x:auto;
        }

        .oo-table{
            width:100%;
            border-collapse:collapse;
            min-width:980px;
        }

        .oo-table th{
            padding:12px 15px;
            text-align:left;
            color:#7b8794;
            background:#fbfcfd;
            border-bottom:1px solid var(--border);
            font-size:9px;
            text-transform:uppercase;
            letter-spacing:.06em;
            font-weight:800;
        }

        .oo-table td{
            padding:13px 15px;
            border-bottom:1px solid #edf0f3;
            vertical-align:middle;
            font-size:12px;
        }

        .oo-table tbody tr:hover{
            background:#fbfdff;
        }

        .oo-order-number{
            color:var(--blue);
            font-weight:800;
        }

        .oo-customer{
            color:var(--charcoal);
            font-weight:800;
        }

        .oo-muted{
            color:var(--muted);
            font-size:10px;
            margin-top:3px;
        }

        .oo-payment{
            color:#526170;
            font-weight:700;
            white-space:nowrap;
        }

        .oo-money{
            color:var(--charcoal);
            font-weight:800;
            white-space:nowrap;
        }

        .oo-status{
            display:inline-flex;
            align-items:center;
            padding:5px 9px;
            border-radius:999px;
            font-size:9px;
            font-weight:900;
            white-space:nowrap;
        }

        .oo-status-pending{
            color:#8a5a00;
            background:#fff3cd;
        }

        .oo-status-processing{
            color:#5147a5;
            background:#e9e8ff;
        }

        .oo-status-completed{
            color:#166534;
            background:#dcfce7;
        }

        .oo-status-cancelled{
            color:#991b1b;
            background:#fee2e2;
        }

        .oo-actions{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
        }

        .oo-action{
            height:30px;
            padding:0 9px;
            border-radius:7px;
            border:1px solid var(--border);
            background:#fff;
            color:#526170;
            font-size:10px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:5px;
            cursor:pointer;
        }

        .oo-action:hover{
            box-shadow:0 2px 8px rgba(30,50,80,.08);
        }

        .oo-action.accept{
            color:var(--green);
            border-color:#bce7d4;
            background:#f3fbf7;
        }

        .oo-action.complete{
            color:#126a47;
            border-color:#bce7d4;
            background:#f3fbf7;
        }

        .oo-action.cancel{
            color:var(--red);
            border-color:#f2c8cf;
            background:#fff8f9;
        }

        .oo-action:disabled{
            opacity:.55;
            cursor:not-allowed;
        }

        .oo-empty{
            padding:55px 20px;
            text-align:center;
            color:var(--muted);
        }

        .oo-empty-icon{
            width:52px;
            height:52px;
            margin:0 auto 12px;
            border-radius:12px;
            display:grid;
            place-items:center;
            background:#f0f3f6;
            color:#7b8794;
            font-size:20px;
        }

        .oo-modal{
            position:fixed;
            inset:0;
            z-index:9999;
            display:none;
            align-items:center;
            justify-content:center;
            padding:18px;
            background:rgba(15,23,42,.52);
        }

        .oo-modal.open{
            display:flex;
        }

        .oo-dialog{
            width:min(760px,100%);
            max-height:92vh;
            overflow:auto;
            background:#fff;
            border-radius:14px;
            box-shadow:0 25px 70px rgba(0,0,0,.22);
        }

        .oo-dialog-head{
            min-height:54px;
            padding:0 17px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            border-bottom:1px solid var(--border);
        }

        .oo-dialog-title{
            color:var(--charcoal);
            font-size:14px;
            font-weight:900;
        }

        .oo-close{
            width:33px;
            height:33px;
            border:1px solid var(--border);
            border-radius:50%;
            background:#fff;
            color:#65717d;
            cursor:pointer;
            font-size:17px;
        }

        .oo-dialog-body{
            padding:17px;
        }

        .oo-detail-grid{
            display:grid;
            grid-template-columns:repeat(2,1fr);
            gap:9px;
            margin-bottom:16px;
        }

        .oo-detail{
            padding:10px;
            border:1px solid #edf0f3;
            border-radius:8px;
            background:#f8fafc;
        }

        .oo-detail-label{
            color:#7b8794;
            font-size:9px;
            text-transform:uppercase;
            letter-spacing:.05em;
            font-weight:800;
        }

        .oo-detail-value{
            margin-top:4px;
            color:var(--charcoal);
            font-size:11px;
            font-weight:800;
        }

        .oo-items{
            border-top:1px solid var(--border);
        }

        .oo-item{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            padding:12px 0;
            border-bottom:1px solid #edf0f3;
        }

        .oo-item-name{
            color:var(--charcoal);
            font-size:12px;
            font-weight:800;
        }

        .oo-item-meta{
            color:var(--muted);
            font-size:10px;
            margin-top:3px;
        }

        .oo-item-price{
            color:var(--charcoal);
            font-size:12px;
            font-weight:800;
            text-align:right;
            white-space:nowrap;
        }

        .oo-total{
            display:flex;
            justify-content:flex-end;
            margin-top:14px;
            color:var(--charcoal);
            font-size:17px;
            font-weight:900;
        }

        .oo-loading{
            padding:35px;
            text-align:center;
            color:var(--muted);
        }

        .oo-filter-summary{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:7px;
            margin-top:9px;
        }

        .oo-summary-chip{
            display:inline-flex;
            align-items:center;
            gap:5px;
            height:25px;
            padding:0 8px;
            border:1px solid #dfe7ef;
            border-radius:999px;
            background:#f8fafc;
            color:#637181;
            font-size:9px;
            font-weight:800;
        }

        @media(max-width:1200px){
            .oo-kpis{
                grid-template-columns:repeat(2,1fr);
            }

            .oo-filter-grid{
                grid-template-columns:repeat(2,1fr);
            }

            .oo-filter-wide{
                grid-column:span 1;
            }

            .oo-filter-actions{
                justify-content:flex-start;
            }
        }

        @media(max-width:900px){
            .admin-main{
                margin-left:0;
            }

            .content{
                padding:20px 16px 32px;
            }

            .oo-page-head{
                align-items:flex-start;
            }

            .oo-head-actions{
                width:100%;
            }

            .oo-head-actions .oo-btn{
                flex:1;
            }
        }

        @media(max-width:650px){
            .oo-page-head{
                flex-direction:column;
            }

            .oo-page-head h1{
                font-size:23px;
            }

            .oo-kpis{
                grid-template-columns:1fr;
            }

            .oo-filter-grid{
                grid-template-columns:1fr;
            }

            .oo-filter-wide{
                grid-column:auto;
            }

            .oo-date-row{
                grid-template-columns:1fr 1fr;
            }

            .oo-filter-actions{
                width:100%;
            }

            .oo-filter-actions .oo-btn{
                flex:1;
            }

            .oo-detail-grid{
                grid-template-columns:1fr;
            }
        }

        @media print{
            .admin-aside,
            .admin-header,
            .oo-head-actions,
            .oo-filter-card,
            .oo-tabs,
            .oo-actions{
                display:none !important;
            }

            .admin-main{
                margin-left:0;
            }

            .content{
                padding:0;
                max-width:none;
            }

            body{
                background:#fff;
            }

            .oo-kpi,
            .oo-orders-card{
                box-shadow:none;
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

                <h1>
                    Online Orders
                </h1>

                <p>
                    Centralized management of online customer orders across
                    <?= oo_e($pharmacy_name) ?>. Use the filters below to
                    review daily, weekly, monthly or yearly activity.
                    Completing an order deducts stock and records the sale.
                </p>

                <?php if ($date_range_label !== 'All dates' || $filter_branch > 0 || $filter_payment !== '' || $customer_search !== '' || $order_search !== '' || $min_amount !== '' || $max_amount !== ''): ?>

                    <div class="oo-filter-summary">

                        <?php if ($date_range_label !== 'All dates'): ?>
                            <span class="oo-summary-chip">
                                <i class="fas fa-calendar"></i>
                                <?= oo_e($date_range_label) ?>
                                <?php if ($date_from !== ''): ?>
                                    Â· <?= oo_e($date_from) ?>
                                <?php endif; ?>
                                <?php if ($date_to !== '' && $date_to !== $date_from): ?>
                                    â†’ <?= oo_e($date_to) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($filter_branch > 0): ?>
                            <?php foreach ($branches as $branch): ?>
                                <?php if ((int)$branch['id'] === $filter_branch): ?>
                                    <span class="oo-summary-chip">
                                        <i class="fas fa-store"></i>
                                        <?= oo_e($branch['branch_name']) ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ($filter_payment !== ''): ?>
                            <span class="oo-summary-chip">
                                <i class="fas fa-wallet"></i>
                                <?= oo_e($filter_payment) ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($customer_search !== ''): ?>
                            <span class="oo-summary-chip">
                                <i class="fas fa-user"></i>
                                <?= oo_e($customer_search) ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($order_search !== ''): ?>
                            <span class="oo-summary-chip">
                                <i class="fas fa-hashtag"></i>
                                <?= oo_e($order_search) ?>
                            </span>
                        <?php endif; ?>

                    </div>

                <?php endif; ?>

            </div>

            <div class="oo-head-actions">

                <button
                    type="button"
                    class="oo-btn"
                    onclick="window.print()"
                >
                    <i class="fas fa-print"></i>
                    Print
                </button>

                <button
                    type="button"
                    class="oo-btn primary"
                    onclick="window.location.reload()"
                >
                    <i class="fas fa-rotate"></i>
                    Refresh
                </button>

            </div>

        </div>

        <section class="oo-kpis">

            <div class="oo-kpi">

                <div class="oo-kpi-head">
                    <span>Pending Orders</span>

                    <span class="oo-kpi-icon">
                        <i class="fas fa-clock"></i>
                    </span>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($counts['Pending']) ?>
                </div>

                <div class="oo-kpi-sub">
                    K<?= number_format($pending_value, 2) ?>
                    pending value
                </div>

            </div>

            <div class="oo-kpi green">

                <div class="oo-kpi-head">
                    <span>Processing</span>

                    <span class="oo-kpi-icon">
                        <i class="fas fa-box-open"></i>
                    </span>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($counts['Processing']) ?>
                </div>

                <div class="oo-kpi-sub">
                    K<?= number_format($processing_value, 2) ?>
                    processing value
                </div>

            </div>

            <div class="oo-kpi yellow">

                <div class="oo-kpi-head">
                    <span>Completed</span>

                    <span class="oo-kpi-icon">
                        <i class="fas fa-circle-check"></i>
                    </span>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($counts['Completed']) ?>
                </div>

                <div class="oo-kpi-sub">
                    K<?= number_format($completed_value, 2) ?>
                    completed value
                </div>

            </div>

            <div class="oo-kpi purple">

                <div class="oo-kpi-head">
                    <span>Online Orders</span>

                    <span class="oo-kpi-icon">
                        <i class="fas fa-bag-shopping"></i>
                    </span>
                </div>

                <div class="oo-kpi-value">
                    <?= number_format($total_online_orders) ?>
                </div>

                <div class="oo-kpi-sub">
                    K<?= number_format($all_order_value, 2) ?>
                    total order value
                </div>

            </div>

        </section>

        <section class="oo-filter-card">

            <div class="oo-filter-title">

                <b>
                    <i class="fas fa-sliders"></i>
                    Order Filters
                </b>

                <span>
                    Filter by period, branch, status, payment, customer and value
                </span>

            </div>

            <form method="get">

                <div class="oo-filter-grid">

                    <div class="oo-field">

                        <label for="ooPeriod">
                            Time Period
                        </label>

                        <select
                            class="oo-control"
                            id="ooPeriod"
                            name="period"
                            onchange="toggleCustomDates()"
                        >

                            <option
                                value=""
                                <?= $period === '' ? 'selected' : '' ?>
                            >
                                All Time
                            </option>

                            <option
                                value="today"
                                <?= $period === 'today' ? 'selected' : '' ?>
                            >
                                Today
                            </option>

                            <option
                                value="yesterday"
                                <?= $period === 'yesterday' ? 'selected' : '' ?>
                            >
                                Yesterday
                            </option>

                            <option
                                value="this_week"
                                <?= $period === 'this_week' ? 'selected' : '' ?>
                            >
                                This Week
                            </option>

                            <option
                                value="last_week"
                                <?= $period === 'last_week' ? 'selected' : '' ?>
                            >
                                Last Week
                            </option>

                            <option
                                value="this_month"
                                <?= $period === 'this_month' ? 'selected' : '' ?>
                            >
                                This Month
                            </option>

                            <option
                                value="last_month"
                                <?= $period === 'last_month' ? 'selected' : '' ?>
                            >
                                Last Month
                            </option>

                            <option
                                value="this_year"
                                <?= $period === 'this_year' ? 'selected' : '' ?>
                            >
                                This Year
                            </option>

                            <option
                                value="last_year"
                                <?= $period === 'last_year' ? 'selected' : '' ?>
                            >
                                Last Year
                            </option>

                            <option
                                value="custom"
                                <?= $period === 'custom' ? 'selected' : '' ?>
                            >
                                Custom Date Range
                            </option>

                        </select>

                    </div>

                    <div class="oo-field">

                        <label for="ooBranch">
                            Branch
                        </label>

                        <select
                            class="oo-control"
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
                                        â€” <?= oo_e($branch['branch_code']) ?>
                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="oo-field">

                        <label for="ooStatus">
                            Status
                        </label>

                        <select
                            class="oo-control"
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
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="oo-field">

                        <label for="ooPayment">
                            Payment Method
                        </label>

                        <select
                            class="oo-control"
                            id="ooPayment"
                            name="payment"
                        >

                            <option
                                value=""
                                <?= $filter_payment === '' ? 'selected' : '' ?>
                            >
                                All Payment Methods
                            </option>

                            <?php foreach (array_slice($allowed_payments, 1) as $payment): ?>

                                <option
                                    value="<?= oo_e($payment) ?>"
                                    <?= $filter_payment === $payment ? 'selected' : '' ?>
                                >
                                    <?= oo_e($payment) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div
                        class="oo-field oo-filter-wide"
                        id="customDates"
                        style="<?= $period === 'custom' ? '' : 'display:none;' ?>"
                    >

                        <label>
                            Custom Dates
                        </label>

                        <div class="oo-date-row">

                            <input
                                type="date"
                                class="oo-control"
                                name="date_from"
                                value="<?= oo_e($date_from_input) ?>"
                                aria-label="Date from"
                            >

                            <input
                                type="date"
                                class="oo-control"
                                name="date_to"
                                value="<?= oo_e($date_to_input) ?>"
                                aria-label="Date to"
                            >

                        </div>

                    </div>

                    <div class="oo-field">

                        <label for="ooCustomer">
                            Customer Search
                        </label>

                        <input
                            type="search"
                            class="oo-control"
                            id="ooCustomer"
                            name="customer"
                            value="<?= oo_e($customer_search) ?>"
                            placeholder="Name, phone or email"
                            autocomplete="off"
                        >

                    </div>

                    <div class="oo-field">

                        <label for="ooOrder">
                            Order Number
                        </label>

                        <input
                            type="search"
                            class="oo-control"
                            id="ooOrder"
                            name="order"
                            value="<?= oo_e($order_search) ?>"
                            placeholder="Search order number"
                            autocomplete="off"
                        >

                    </div>

                    <div class="oo-field">

                        <label>
                            Order Value
                        </label>

                        <div class="oo-date-row">

                            <input
                                type="number"
                                class="oo-control"
                                name="min_amount"
                                min="0"
                                step="0.01"
                                value="<?= oo_e($min_amount) ?>"
                                placeholder="Min K"
                            >

                            <input
                                type="number"
                                class="oo-control"
                                name="max_amount"
                                min="0"
                                step="0.01"
                                value="<?= oo_e($max_amount) ?>"
                                placeholder="Max K"
                            >

                        </div>

                    </div>

                    <div class="oo-filter-actions">

                        <button
                            type="submit"
                            class="oo-btn primary"
                        >
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>

                        <a
                            href="online_orders.php"
                            class="oo-btn"
                        >
                            <i class="fas fa-xmark"></i>
                            Clear
                        </a>

                    </div>

                </div>

            </form>

            <div class="oo-quick-periods">

                <a
                    href="online_orders.php"
                    class="oo-quick <?= $period === '' ? 'active' : '' ?>"
                >
                    All Time
                </a>

                <?php
                $quick_periods = [
                    'today' => 'Today',
                    'yesterday' => 'Yesterday',
                    'this_week' => 'This Week',
                    'last_week' => 'Last Week',
                    'this_month' => 'This Month',
                    'last_month' => 'Last Month',
                    'this_year' => 'This Year',
                    'last_year' => 'Last Year'
                ];
                ?>

                <?php foreach ($quick_periods as $key => $label): ?>

                    <?php
                    $quick_query = $common_query;
                    $quick_query['period'] = $key;
                    unset(
                        $quick_query['status']
                    );
                    ?>

                    <a
                        href="online_orders.php?<?= oo_e(http_build_query($quick_query)) ?>"
                        class="oo-quick <?= $period === $key ? 'active' : '' ?>"
                    >
                        <?= oo_e($label) ?>
                    </a>

                <?php endforeach; ?>

                <a
                    href="online_orders.php?<?= oo_e(http_build_query(
                        array_merge(
                            $common_query,
                            ['period' => 'custom']
                        )
                    )) ?>"
                    class="oo-quick <?= $period === 'custom' ? 'active' : '' ?>"
                >
                    Custom
                </a>

            </div>

        </section>

        <section class="oo-orders-card">

            <div class="oo-card-head">

                <div>
                    <b>
                        Online Customer Orders
                    </b>

                    <span style="display:block;margin-top:3px">
                        <?= oo_e($date_range_label) ?>
                        Â· Up to 300 latest matching orders
                    </span>
                </div>

                <span>
                    <?= number_format(count($orders)) ?>
                    displayed
                </span>

            </div>

            <div class="oo-tabs">

                <a
                    class="oo-tab <?= $filter_status === '' ? 'active' : '' ?>"
                    href="<?= oo_e(
                        oo_tab_url(
                            $common_query,
                            ''
                        )
                    ) ?>"
                >
                    All
                    (<?= number_format($total_online_orders) ?>)
                </a>

                <?php foreach (array_keys($counts) as $status): ?>

                    <a
                        class="oo-tab <?= $filter_status === $status ? 'active' : '' ?>"
                        href="<?= oo_e(
                            oo_tab_url(
                                $common_query,
                                $status
                            )
                        ) ?>"
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

                                    <div class="oo-muted">
                                        Try changing the date, branch,
                                        payment, status or search filters.
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
                                'Pending' =>
                                    'oo-status-pending',

                                'Processing' =>
                                    'oo-status-processing',

                                'Completed' =>
                                    'oo-status-completed',

                                'Cancelled' =>
                                    'oo-status-cancelled',

                                default =>
                                    'oo-status-pending'
                            };

                            $payment =
                                (string)(
                                    $order['payment_method'] ?? ''
                                );

                            $payment_display =
                                oo_payment_display($payment);
                            ?>

                            <tr>

                                <td>

                                    <div class="oo-order-number">
                                        #<?= oo_e(
                                            $order['order_number']
                                        ) ?>
                                    </div>

                                    <div class="oo-muted">
                                        ID <?= (int)$order['id'] ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="oo-customer">
                                        <?= oo_e(
                                            $order['full_name'] ?:
                                            'Customer'
                                        ) ?>
                                    </div>

                                    <?php if (!empty($order['phone'])): ?>

                                        <div class="oo-muted">
                                            <?= oo_e(
                                                $order['phone']
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="oo-customer">
                                        <?= oo_e(
                                            $order['branch_name'] ?:
                                            'Unknown branch'
                                        ) ?>
                                    </div>

                                </td>

                                <td>

                                    <div class="oo-payment">
                                        <?= oo_e(
                                            $payment_display
                                        ) ?>
                                    </div>

                                </td>

                                <td>
                                    <?= oo_e(
                                        $order['order_date']
                                    ) ?>
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

                                    <span
                                        class="oo-status <?= $status_class ?>"
                                    >
                                        <?= oo_e($status) ?>
                                    </span>

                                </td>

                                <td>

                                    <div class="oo-actions">

                                        <button
                                            type="button"
                                            class="oo-action"
                                            onclick="viewOrder(
                                                <?= (int)$order['id'] ?>
                                            )"
                                        >
                                            <i class="fas fa-eye"></i>
                                            View
                                        </button>

                                        <?php if ($status === 'Pending'): ?>

                                            <button
                                                type="button"
                                                class="oo-action accept"
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
                                                class="oo-action cancel"
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
                                                class="oo-action complete"
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
                                                class="oo-action cancel"
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
const OO_CSRF =
    <?= json_encode($csrf_token) ?>;

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
    const number =
        Number(value ?? 0);

    return 'K' +
        number.toLocaleString(
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

function toggleCustomDates() {
    const period =
        document.getElementById('ooPeriod');

    const custom =
        document.getElementById('customDates');

    if (!period || !custom) {
        return;
    }

    custom.style.display =
        period.value === 'custom'
            ? ''
            : 'none';
}

async function viewOrder(id) {
    const body =
        document.getElementById('ooBody');

    body.innerHTML =
        '<div class="oo-loading">' +
        '<i class="fas fa-spinner fa-spin"></i> ' +
        'Loading order...' +
        '</div>';

    const modal =
        document.getElementById('ooModal');

    modal.classList.add('open');
    modal.setAttribute(
        'aria-hidden',
        'false'
    );

    try {
        const response =
            await fetch(
                'online_orders.php?action=order&id=' +
                encodeURIComponent(id),
                {
                    headers: {
                        'Accept':
                            'application/json'
                    },
                    cache: 'no-store'
                }
            );

        const data =
            await response.json();

        if (!data.success) {
            throw new Error(
                data.message ||
                'Unable to load order.'
            );
        }

        const order =
            data.order;

        const items =
            Array.isArray(data.items)
                ? data.items
                : [];

        document.getElementById(
            'ooTitle'
        ).textContent =
            '#' +
            (order.order_number || order.id);

        let html =
            '<div class="oo-detail-grid">';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">' +
            'Customer' +
            '</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(
                order.full_name ||
                'Customer'
            ) +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">' +
            'Phone' +
            '</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(
                order.phone ||
                'Not provided'
            ) +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">' +
            'Email' +
            '</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(
                order.email ||
                'Not provided'
            ) +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">' +
            'Branch' +
            '</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(
                order.branch_name ||
                'Unknown branch'
            ) +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">' +
            'Payment Method' +
            '</div>' +
            '<div class="oo-detail-value">' +
            ooEsc(
                order.payment_method ||
                'Not specified'
            ) +
            '</div>' +
            '</div>';

        html +=
            '<div class="oo-detail">' +
            '<div class="oo-detail-label">' +
            'Status' +
            '</div>' +
            '<div class="oo-detail-value">' +
            '<span class="' +
            ooStatusClass(
                order.status
            ) +
            '">' +
            ooEsc(
                order.status ||
                ''
            ) +
            '</span>' +
            '</div>' +
            '</div>';

        html += '</div>';

        html +=
            '<div class="oo-items">';

        if (!items.length) {
            html +=
                '<div class="oo-empty">' +
                'This order has no items.' +
                '</div>';
        } else {
            items.forEach(
                function (item) {
                    const qty =
                        Number(
                            item.quantity || 0
                        );

                    const price =
                        Number(
                            item.price_at_purchase ||
                            0
                        );

                    const lineTotal =
                        qty * price;

                    html +=
                        '<div class="oo-item">' +
                        '<div>' +
                        '<div class="oo-item-name">' +
                        ooEsc(
                            item.item_name ||
                            'Product'
                        ) +
                        '</div>' +
                        '<div class="oo-item-meta">' +
                        ooEsc(
                            item.strength ||
                            ''
                        ) +
                        (
                            item.strength
                                ? ' Â· '
                                : ''
                        ) +
                        'Qty: ' +
                        qty +
                        (
                            item.barcode
                                ? ' Â· Barcode: ' +
                                  ooEsc(
                                      item.barcode
                                  )
                                : ''
                        ) +
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
                }
            );
        }

        html += '</div>';

        html +=
            '<div class="oo-total">' +
            'Total: ' +
            ooMoney(
                order.total_amount
            ) +
            '</div>';

        body.innerHTML = html;

    } catch (error) {
        body.innerHTML =
            '<div class="oo-empty">' +
            '<div class="oo-empty-icon">' +
            '<i class="fas fa-triangle-exclamation"></i>' +
            '</div>' +
            '<strong>' +
            'Unable to load order' +
            '</strong>' +
            '<div class="oo-muted">' +
            ooEsc(
                error.message ||
                'Please try again.'
            ) +
            '</div>' +
            '</div>';
    }
}

function closeOrder() {
    const modal =
        document.getElementById('ooModal');

    modal.classList.remove('open');

    modal.setAttribute(
        'aria-hidden',
        'true'
    );
}

async function changeStatus(
    id,
    status
) {
    let message =
        'Change this order to ' +
        status +
        '?';

    if (status === 'Processing') {
        message =
            'Accept this online order and move it to Processing?';
    }

    if (status === 'Completed') {
        message =
            'Complete this order?\n\n' +
            'The ordered quantities will be deducted ' +
            'from the order branch stock and the completed ' +
            'sale will be recorded in Transactions and Sales Report.';
    }

    if (status === 'Cancelled') {
        message =
            'Cancel this online order?\n\n' +
            'No stock will be deducted.';
    }

    if (!window.confirm(message)) {
        return;
    }

    const formData =
        new FormData();

    formData.append(
        'action',
        'update_status'
    );

    formData.append(
        'order_id',
        String(id)
    );

    formData.append(
        'status',
        status
    );

    formData.append(
        'csrf_token',
        OO_CSRF
    );

    try {
        const response =
            await fetch(
                'online_orders.php',
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            );

        const data =
            await response.json();

        if (!data.success) {
            throw new Error(
                data.message ||
                'Unable to update order.'
            );
        }

        window.alert(
            data.message ||
            'Order updated successfully.'
        );

        window.location.reload();

    } catch (error) {
        window.alert(
            error.message ||
            'Unable to update the order.'
        );
    }
}

document
    .getElementById('ooModal')
    .addEventListener(
        'click',
        function (event) {
            if (
                event.target.id ===
                'ooModal'
            ) {
                closeOrder();
            }
        }
    );

document.addEventListener(
    'keydown',
    function (event) {
        if (event.key === 'Escape') {
            closeOrder();
        }
    }
);

toggleCustomDates();

/*
 * Refresh online order status every 60 seconds.
 * This is intentionally the same lightweight refresh behaviour
 * as the previous online-order workflow.
 */
setTimeout(
    function () {
        window.location.reload();
    },
    60000
);
</script>

</body>
</html>
