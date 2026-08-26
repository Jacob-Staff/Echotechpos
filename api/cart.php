<?php
/**
 * BIGE50 ONLINE CART
 *
 * ONE FILE does both jobs:
 *   1. Normal GET  -> renders the cart page.
 *   2. POST action -> returns JSON for add/update/remove/clear/checkout.
 *
 * This removes the dependency on cart_handler.php and cart_api.php.
 * POS cart is not touched.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

/* ---------------------------------------------------------
   DATABASE
--------------------------------------------------------- */
$conn_file = realpath(__DIR__ . '/../includes/conn.php');

if (!$conn_file) {
    $conn_file = realpath(__DIR__ . '/includes/conn.php');
}

if (!$conn_file || !is_file($conn_file)) {
    http_response_code(500);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection file not found.'
        ]);
        exit;
    }

    die('Database connection file (conn.php) not found.');
}

require_once $conn_file;

if (!isset($conn) || !($conn instanceof mysqli)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection unavailable.'
        ]);
        exit;
    }

    die('Database connection unavailable.');
}

/* ---------------------------------------------------------
   AJAX / JSON HELPERS
--------------------------------------------------------- */
function bige_cart_json(
    bool $success,
    string $message = '',
    array $extra = []
): never {
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'status'  => $success ? 'success' : 'error',
                'message' => $message
            ],
            $extra
        ),
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function bige_cart_fail(string $message, array $extra = []): never
{
    bige_cart_json(false, $message, $extra);
}

function bige_cart_check_csrf(): void
{
    $sessionToken = (string)($_SESSION['online_cart_csrf'] ?? '');
    $postedToken  = (string)($_POST['csrf'] ?? '');

    if ($sessionToken === '' || $postedToken === '' ||
        !hash_equals($sessionToken, $postedToken)) {

        bige_cart_fail(
            'Security token expired. Please refresh the page and try again.'
        );
    }
}

/* ---------------------------------------------------------
   CSRF
--------------------------------------------------------- */
if (empty($_SESSION['online_cart_csrf'])) {
    $_SESSION['online_cart_csrf'] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION['online_cart_csrf'];

/* ---------------------------------------------------------
   CART HELPERS
--------------------------------------------------------- */
function bige_cart_branch(mysqli $db, int $branchId): array
{
    $stmt = $db->prepare(
        "SELECT id, pharmacy_id, branch_name
         FROM branches
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to verify branch.');
    }

    $stmt->bind_param('i', $branchId);
    $stmt->execute();

    $branch = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt->close();

    if (!$branch) {
        throw new RuntimeException(
            'The selected branch is unavailable.'
        );
    }

    return $branch;
}

function bige_cart_get(int $branchId): array
{
    if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
        $_SESSION['carts'] = [];
    }

    if (
        !isset($_SESSION['carts'][$branchId]) ||
        !is_array($_SESSION['carts'][$branchId])
    ) {
        $_SESSION['carts'][$branchId] = [];
    }

    return $_SESSION['carts'][$branchId];
}

function bige_cart_summary(array $cart): array
{
    $count = 0;
    $subtotal = 0.0;

    foreach ($cart as $item) {
        $qty = max(0, (int)($item['qty'] ?? 0));
        $price = (float)($item['price'] ?? 0);

        $count += $qty;
        $subtotal += $price * $qty;
    }

    $subtotal = round($subtotal, 2);

    return [
        'items'      => array_values($cart),
        'cart_count' => $count,
        'subtotal'   => $subtotal,
        'total'      => $subtotal
    ];
}

/**
 * IMPORTANT:
 * Uses the same online-store rules:
 * - correct branch
 * - active
 * - online
 * - stock available
 * - not expired
 *
 * The zero-date check is converted to text so strict MySQL mode
 * does not choke on legacy 0000-00-00 dates.
 */
function bige_cart_product(
    mysqli $db,
    int $productId,
    int $branchId,
    bool $lock = false
): ?array {

    $sql = "
        SELECT
            id,
            pharmacy_id,
            item_name,
            price,
            online_price,
            quantity,
            image,
            product_image,
            category,
            strength
        FROM store_items
        WHERE id = ?
          AND branch_id = ?
          AND is_active = 1
          AND is_online = 1
          AND quantity > 0
          AND (
                expiry_date IS NULL
                OR LEFT(CAST(expiry_date AS CHAR), 10) = '0000-00-00'
                OR LEFT(CAST(expiry_date AS CHAR), 10) >= ?
          )
        LIMIT 1
    ";

    if ($lock) {
        $sql = str_replace(
            'LIMIT 1',
            'LIMIT 1 FOR UPDATE',
            $sql
        );
    }

    $today = date('Y-m-d');

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iis', $productId, $branchId, $today);
    $stmt->execute();

    $product = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return $product ?: null;
}

function bige_cart_price(array $product): float
{
    $normal = (float)($product['price'] ?? 0);
    $online = (float)($product['online_price'] ?? 0);

    if ($online > 0 && $online < $normal) {
        return round($online, 2);
    }

    return round($normal, 2);
}

function bige_cart_order_number(mysqli $db): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {

        $number =
            'ORD-' .
            date('YmdHis') .
            random_int(10, 99);

        $stmt = $db->prepare(
            "SELECT id
             FROM clients_orders
             WHERE order_number = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Unable to prepare order number.'
            );
        }

        $stmt->bind_param('s', $number);
        $stmt->execute();

        $exists = $stmt->get_result()->num_rows > 0;

        $stmt->close();

        if (!$exists) {
            return $number;
        }
    }

    throw new RuntimeException(
        'Unable to generate a unique order number.'
    );
}

/* ---------------------------------------------------------
   POST = CART API
--------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    bige_cart_check_csrf();

    $action = strtolower(
        trim((string)($_POST['action'] ?? ''))
    );

    $branchId = (int)(
        $_POST['bid']
        ?? $_POST['branch_id']
        ?? $_SESSION['current_branch_id']
        ?? 0
    );

    if ($branchId <= 0) {
        bige_cart_fail('No active branch selected.');
    }

    try {

        $branch = bige_cart_branch($conn, $branchId);

        $pharmacyId = (int)$branch['pharmacy_id'];

        $_SESSION['current_branch_id'] = $branchId;

        $cart = bige_cart_get($branchId);

        /* -------------------------------------------------
           ADD
        ------------------------------------------------- */
        if ($action === 'add') {

            $productId = (int)(
                $_POST['product_id']
                ?? $_POST['item_id']
                ?? 0
            );

            $addQty = max(
                1,
                (int)($_POST['qty'] ?? 1)
            );

            if ($productId <= 0) {
                bige_cart_fail('Invalid product.');
            }

            $product = bige_cart_product(
                $conn,
                $productId,
                $branchId
            );

            if (!$product) {
                bige_cart_fail(
                    'This product is unavailable or out of stock.'
                );
            }

            $stock = (int)$product['quantity'];

            $existingQty = (int)(
                $cart[$productId]['qty'] ?? 0
            );

            $newQty = $existingQty + $addQty;

            if ($newQty > $stock) {
                bige_cart_fail(
                    "Only {$stock} unit(s) are available.",
                    ['available' => $stock]
                );
            }

            $cart[$productId] = [
                'id'       => (int)$product['id'],
                'name'     => (string)$product['item_name'],
                'price'    => bige_cart_price($product),
                'qty'      => $newQty,
                'branch'   => $branchId,
                'image'    => (string)(
                    $product['image']
                    ?: ($product['product_image'] ?? '')
                ),
                'category' => (string)(
                    $product['category'] ?? ''
                ),
                'strength' => (string)(
                    $product['strength'] ?? ''
                ),
                'stock'    => $stock
            ];

            $_SESSION['carts'][$branchId] = $cart;

            bige_cart_json(
                true,
                'Item added to cart.',
                bige_cart_summary($cart)
            );
        }

        /* -------------------------------------------------
           GET CART
        ------------------------------------------------- */
        if ($action === 'get') {

            bige_cart_json(
                true,
                'Cart loaded.',
                bige_cart_summary($cart)
            );
        }

        /* -------------------------------------------------
           UPDATE QUANTITY
        ------------------------------------------------- */
        if ($action === 'update') {

            $productId = (int)(
                $_POST['product_id']
                ?? $_POST['item_id']
                ?? 0
            );

            $qty = (int)($_POST['qty'] ?? 0);

            if (
                $productId <= 0 ||
                !isset($cart[$productId])
            ) {
                bige_cart_fail(
                    'Item is not in the cart.'
                );
            }

            if ($qty <= 0) {

                unset($cart[$productId]);

                $_SESSION['carts'][$branchId] = $cart;

                bige_cart_json(
                    true,
                    'Item removed.',
                    bige_cart_summary($cart)
                );
            }

            $product = bige_cart_product(
                $conn,
                $productId,
                $branchId
            );

            if (!$product) {

                unset($cart[$productId]);

                $_SESSION['carts'][$branchId] = $cart;

                bige_cart_fail(
                    'This product is no longer available.'
                );
            }

            $stock = (int)$product['quantity'];

            if ($qty > $stock) {
                bige_cart_fail(
                    "Only {$stock} unit(s) are available.",
                    ['available' => $stock]
                );
            }

            $cart[$productId]['qty'] = $qty;
            $cart[$productId]['price'] =
                bige_cart_price($product);

            $cart[$productId]['stock'] = $stock;

            $_SESSION['carts'][$branchId] = $cart;

            bige_cart_json(
                true,
                'Cart updated.',
                bige_cart_summary($cart)
            );
        }

        /* -------------------------------------------------
           REMOVE
        ------------------------------------------------- */
        if ($action === 'remove') {

            $productId = (int)(
                $_POST['product_id']
                ?? $_POST['item_id']
                ?? 0
            );

            unset($cart[$productId]);

            $_SESSION['carts'][$branchId] = $cart;

            bige_cart_json(
                true,
                'Item removed.',
                bige_cart_summary($cart)
            );
        }

        /* -------------------------------------------------
           CLEAR
        ------------------------------------------------- */
        if ($action === 'clear') {

            $_SESSION['carts'][$branchId] = [];

            bige_cart_json(
                true,
                'Cart cleared.',
                bige_cart_summary([])
            );
        }

        /* -------------------------------------------------
           CHECKOUT
        ------------------------------------------------- */
        if ($action === 'checkout') {

            $clientId = (int)(
                $_SESSION['client_id'] ?? 0
            );

            if ($clientId <= 0) {
                bige_cart_fail(
                    'Please log in before placing an order.',
                    ['login_required' => true]
                );
            }

            $name = trim(
                (string)($_POST['customer_name'] ?? '')
            );

            $phone = trim(
                (string)($_POST['phone'] ?? '')
            );

            $address = trim(
                (string)($_POST['address'] ?? '')
            );

            $payment = trim(
                (string)($_POST['payment_method'] ?? '')
            );

            if (
                $name === '' ||
                $phone === '' ||
                $address === ''
            ) {
                bige_cart_fail(
                    'Please complete your name, phone number and delivery address.'
                );
            }

            if (!in_array(
                $payment,
                [
                    'Cash on Delivery',
                    'Mobile Money',
                    'Bank'
                ],
                true
            )) {
                bige_cart_fail(
                    'Please select a valid payment method.'
                );
            }

            if (!$cart) {
                bige_cart_fail(
                    'Your cart is empty.'
                );
            }

            $conn->begin_transaction();

            try {

                /* Verify client */
                $stmt = $conn->prepare(
                    "SELECT id, full_name, phone, email
                     FROM clients
                     WHERE id = ?
                     LIMIT 1"
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to verify account.'
                    );
                }

                $stmt->bind_param('i', $clientId);
                $stmt->execute();

                $client =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if (!$client) {
                    throw new RuntimeException(
                        'Your online account could not be found. Please log in again.'
                    );
                }

                /* -----------------------------------------
                   Re-check every product while transaction
                   is open.
                ----------------------------------------- */
                $validated = [];
                $orderTotal = 0.0;

                foreach ($cart as $cartItem) {

                    $productId = (int)(
                        $cartItem['id'] ?? 0
                    );

                    $qty = (int)(
                        $cartItem['qty'] ?? 0
                    );

                    if ($productId <= 0 || $qty <= 0) {
                        throw new RuntimeException(
                            'Invalid item in cart.'
                        );
                    }

                    $product = bige_cart_product(
                        $conn,
                        $productId,
                        $branchId,
                        true
                    );

                    if (!$product) {
                        throw new RuntimeException(
                            'One of the products is no longer available.'
                        );
                    }

                    $stock = (int)$product['quantity'];

                    if ($qty > $stock) {
                        throw new RuntimeException(
                            $product['item_name'] .
                            " has only {$stock} unit(s) available."
                        );
                    }

                    $unitPrice =
                        bige_cart_price($product);

                    $lineTotal =
                        round($unitPrice * $qty, 2);

                    $orderTotal += $lineTotal;

                    $validated[] = [
                        'id'    => $productId,
                        'qty'   => $qty,
                        'price' => $unitPrice
                    ];
                }

                $orderTotal = round(
                    $orderTotal,
                    2
                );

                $email = (string)(
                    $client['email'] ?? ''
                );

                /* -----------------------------------------
                   Customer profile
                ----------------------------------------- */
                $stmt = $conn->prepare(
                    "SELECT id
                     FROM customers
                     WHERE client_id = ?
                       AND pharmacy_id = ?
                       AND branch_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to prepare customer profile.'
                    );
                }

                $stmt->bind_param(
                    'iii',
                    $clientId,
                    $pharmacyId,
                    $branchId
                );

                $stmt->execute();

                $customer =
                    $stmt->get_result()->fetch_assoc();

                $stmt->close();

                if ($customer) {

                    $customerId =
                        (int)$customer['id'];

                    $stmt = $conn->prepare(
                        "UPDATE customers
                         SET name = ?,
                             phone = ?,
                             email = ?,
                             address = ?
                         WHERE id = ?
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to update customer profile.'
                        );
                    }

                    $stmt->bind_param(
                        'ssssi',
                        $name,
                        $phone,
                        $email,
                        $address,
                        $customerId
                    );

                    if (!$stmt->execute()) {
                        throw new RuntimeException(
                            'Unable to save delivery details.'
                        );
                    }

                    $stmt->close();

                } else {

                    /* Look for same branch phone */
                    $stmt = $conn->prepare(
                        "SELECT id, client_id
                         FROM customers
                         WHERE branch_id = ?
                           AND phone = ?
                         LIMIT 1
                         FOR UPDATE"
                    );

                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to verify phone number.'
                        );
                    }

                    $stmt->bind_param(
                        'is',
                        $branchId,
                        $phone
                    );

                    $stmt->execute();

                    $owner =
                        $stmt->get_result()->fetch_assoc();

                    $stmt->close();

                    if (
                        $owner &&
                        (int)($owner['client_id'] ?? 0)
                        !== $clientId
                    ) {
                        throw new RuntimeException(
                            'That phone number is already registered to another customer at this branch.'
                        );
                    }

                    if ($owner) {

                        $customerId =
                            (int)$owner['id'];

                        $stmt = $conn->prepare(
                            "UPDATE customers
                             SET client_id = ?,
                                 pharmacy_id = ?,
                                 name = ?,
                                 email = ?,
                                 address = ?
                             WHERE id = ?
                             LIMIT 1"
                        );

                        if (!$stmt) {
                            throw new RuntimeException(
                                'Unable to update customer.'
                            );
                        }

                        $stmt->bind_param(
                            'iisssi',
                            $clientId,
                            $pharmacyId,
                            $name,
                            $email,
                            $address,
                            $customerId
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException(
                                'Unable to save customer details.'
                            );
                        }

                        $stmt->close();

                    } else {

                        $stmt = $conn->prepare(
                            "INSERT INTO customers
                            (
                                pharmacy_id,
                                branch_id,
                                client_id,
                                name,
                                phone,
                                email,
                                address
                            )
                            VALUES (?,?,?,?,?,?,?)"
                        );

                        if (!$stmt) {
                            throw new RuntimeException(
                                'Unable to create customer profile.'
                            );
                        }

                        $stmt->bind_param(
                            'iiissss',
                            $pharmacyId,
                            $branchId,
                            $clientId,
                            $name,
                            $phone,
                            $email,
                            $address
                        );

                        if (!$stmt->execute()) {
                            throw new RuntimeException(
                                'Unable to save customer profile.'
                            );
                        }

                        $stmt->close();
                    }
                }

                /* -----------------------------------------
                   Create order
                ----------------------------------------- */
                $orderNumber =
                    bige_cart_order_number($conn);

                $stmt = $conn->prepare(
                    "INSERT INTO clients_orders
                    (
                        client_id,
                        order_number,
                        total_amount,
                        payment_method,
                        status,
                        pharmacy_id,
                        branch_id
                    )
                    VALUES (?,?,?,?,'Pending',?,?)"
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to create online order.'
                    );
                }

                $stmt->bind_param(
                    'isdsii',
                    $clientId,
                    $orderNumber,
                    $orderTotal,
                    $payment,
                    $pharmacyId,
                    $branchId
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        'Unable to save online order.'
                    );
                }

                $orderId =
                    (int)$conn->insert_id;

                $stmt->close();

                if ($orderId <= 0) {
                    throw new RuntimeException(
                        'Online order was not created.'
                    );
                }

                /* -----------------------------------------
                   Save order items

                   IMPORTANT: stock is NOT deducted here.
                   Stock is deducted only when pharmacy staff
                   marks the order as Completed. This prevents
                   Pending/Cancelled orders from consuming stock.
                ----------------------------------------- */
                $itemStmt = $conn->prepare(
                    "INSERT INTO clients_order_items
                    (
                        order_id,
                        product_id,
                        quantity,
                        price_at_purchase,
                        pharmacy_id,
                        branch_id
                    )
                    VALUES (?,?,?,?,?,?)"
                );

                if (!$itemStmt) {
                    throw new RuntimeException(
                        'Unable to prepare order items.'
                    );
                }

                foreach ($validated as $item) {

                    $productId =
                        (int)$item['id'];

                    $qty =
                        (int)$item['qty'];

                    $unitPrice =
                        (float)$item['price'];

                    $itemStmt->bind_param(
                        'iiidii',
                        $orderId,
                        $productId,
                        $qty,
                        $unitPrice,
                        $pharmacyId,
                        $branchId
                    );

                    if (!$itemStmt->execute()) {
                        throw new RuntimeException(
                            'Unable to save order item.'
                        );
                    }
                }

                $itemStmt->close();
                $stockStmt->close();

                $conn->commit();

                $_SESSION['carts'][$branchId] = [];

                bige_cart_json(
                    true,
                    'Your order has been placed successfully.',
                    [
                        'order_id'      => $orderId,
                        'order_number'  => $orderNumber,
                        'total'         => $orderTotal,
                        'payment_method'=> $payment,
                        'branch_id'     => $branchId,
                        'pharmacy_id'   => $pharmacyId,
                        'cart_count'    => 0,
                        'items'         => [],
                        'subtotal'      => 0,
                    ]
                );

            } catch (Throwable $checkoutError) {

                try {
                    $conn->rollback();
                } catch (Throwable $ignore) {}

                throw $checkoutError;
            }
        }

        bige_cart_fail('Unknown cart action.');

    } catch (Throwable $e) {

        error_log(
            'BIGE50 ONLINE CART: ' .
            $e->getMessage()
        );

        bige_cart_fail(
            $e->getMessage()
        );
    }
}

/* ---------------------------------------------------------
   NORMAL GET = CART PAGE
--------------------------------------------------------- */

/* Existing store header */
$header_candidates = [
    __DIR__ . '/store_header.php',
    __DIR__ . '/api/store_header.php',
];

$header_loaded = false;

foreach ($header_candidates as $header_file) {
    if (is_file($header_file)) {
        require_once $header_file;
        $header_loaded = true;
        break;
    }
}

if (!$header_loaded) {
    die('Error: store_header.php could not be found.');
}

/* Branch */
$branchId = (int)(
    $_SESSION['current_branch_id']
    ?? $_SESSION['branch_id']
    ?? 0
);

if (
    isset($_GET['bid']) &&
    (int)$_GET['bid'] > 0
) {
    $requested = (int)$_GET['bid'];

    $stmt = $conn->prepare(
        "SELECT id, pharmacy_id
         FROM branches
         WHERE id = ? AND is_active = 1
         LIMIT 1"
    );

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $requested
        );

        $stmt->execute();

        $requestedBranch =
            $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if ($requestedBranch) {
            $branchId = $requested;

            $_SESSION['current_branch_id'] =
                $branchId;
        }
    }
}

if ($branchId <= 0) {
    die(
        '<div style="font-family:Arial;padding:30px;text-align:center">
            No active pharmacy branch was selected.
         </div>'
    );
}

$branch = bige_cart_branch(
    $conn,
    $branchId
);

$pharmacyId =
    (int)$branch['pharmacy_id'];

$cart =
    bige_cart_get($branchId);

$cartCount = 0;
$subtotal = 0.0;

foreach ($cart as $item) {

    $qty =
        max(1, (int)($item['qty'] ?? 1));

    $price =
        (float)($item['price'] ?? 0);

    $cartCount += $qty;
    $subtotal +=
        $price * $qty;
}

$subtotal =
    round($subtotal, 2);

/* Customer prefill */
$clientId =
    (int)($_SESSION['client_id'] ?? 0);

$customerName =
    (string)($_SESSION['client_name'] ?? '');

$customerPhone = '';
$customerAddress = '';

if ($clientId > 0) {

    $stmt = $conn->prepare(
        "SELECT full_name, phone, email
         FROM clients
         WHERE id = ?
         LIMIT 1"
    );

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $clientId
        );

        $stmt->execute();

        $client =
            $stmt->get_result()->fetch_assoc()
            ?: [];

        $stmt->close();

        if ($customerName === '') {
            $customerName =
                (string)($client['full_name'] ?? '');
        }

        $customerPhone =
            (string)($client['phone'] ?? '');

        $stmt = $conn->prepare(
            "SELECT name, phone, address
             FROM customers
             WHERE client_id = ?
               AND pharmacy_id = ?
               AND branch_id = ?
             LIMIT 1"
        );

        if ($stmt) {

            $stmt->bind_param(
                'iii',
                $clientId,
                $pharmacyId,
                $branchId
            );

            $stmt->execute();

            $customer =
                $stmt->get_result()->fetch_assoc()
                ?: [];

            $stmt->close();

            if (!empty($customer['name'])) {
                $customerName =
                    (string)$customer['name'];
            }

            if (!empty($customer['phone'])) {
                $customerPhone =
                    (string)$customer['phone'];
            }

            $customerAddress =
                (string)(
                    $customer['address'] ?? ''
                );
        }
    }
}

function bige_cart_e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function bige_cart_money($value): string
{
    return 'K ' .
        number_format(
            (float)$value,
            2
        );
}

function bige_cart_image_url(
    string $image
): string {

    if ($image === '') {
        return '';
    }

    $isApi =
        basename(__DIR__) === 'api';

    $prefix =
        $isApi ? '../' : '';

    return $prefix .
        'uploads/products/' .
        rawurlencode(
            basename($image)
        );
}
?>

<style>
.bige-cart-page{
    background:#f6f8fa;
    min-height:calc(100vh - 160px);
    padding:26px 0 65px
}
.bige-cart-wrap{
    width:min(1180px,calc(100% - 26px));
    margin:auto
}
.bige-cart-heading{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    margin-bottom:22px
}
.bige-cart-heading h1{
    margin:4px 0;
    color:#003339;
    font-size:30px;
    font-weight:800
}
.bige-cart-heading p{
    margin:0;
    color:#6d7880
}
.bige-cart-eyebrow{
    color:#00b386;
    font-size:11px;
    font-weight:900;
    letter-spacing:.13em
}
.bige-cart-back{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:11px 15px;
    background:#fff;
    border:1px solid #e1e6e9;
    border-radius:10px;
    color:#003339;
    text-decoration:none;
    font-weight:800
}
.bige-cart-layout{
    display:grid;
    grid-template-columns:minmax(0,1fr) 350px;
    gap:20px;
    align-items:start
}
.bige-cart-card{
    background:#fff;
    border:1px solid #e3e8eb;
    border-radius:16px;
    box-shadow:0 6px 24px rgba(0,51,57,.06);
    overflow:hidden
}
.bige-cart-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:16px 18px;
    border-bottom:1px solid #edf0f2
}
.bige-cart-head strong{color:#003339}
.bige-cart-clear{
    border:0;
    background:none;
    color:#c0392b;
    font-weight:800;
    cursor:pointer
}
.bige-cart-clear:disabled{
    opacity:.4;
    cursor:not-allowed
}
.bige-cart-item{
    display:grid;
    grid-template-columns:76px minmax(0,1fr) auto auto auto;
    gap:15px;
    align-items:center;
    padding:17px 18px;
    border-bottom:1px solid #edf0f2
}
.bige-cart-item:last-child{border-bottom:0}
.bige-cart-thumb{
    width:76px;
    height:76px;
    border-radius:12px;
    background:#f0f7f5;
    display:grid;
    place-items:center;
    overflow:hidden
}
.bige-cart-thumb img{
    width:100%;
    height:100%;
    object-fit:contain
}
.bige-cart-thumb i{
    font-size:29px;
    color:#00b386
}
.bige-cart-product h3{
    margin:0 0 4px;
    color:#003339;
    font-size:15px;
    font-weight:800
}
.bige-cart-product small{
    display:block;
    margin-bottom:7px;
    color:#7b858c
}
.bige-cart-price{
    color:#00a878;
    font-weight:800
}
.bige-cart-qty{
    display:flex;
    align-items:center;
    border:1px solid #dce3e6;
    border-radius:10px;
    overflow:hidden
}
.bige-cart-qty button{
    width:36px;
    height:38px;
    border:0;
    background:#f4f7f8;
    color:#003339;
    font-size:20px;
    cursor:pointer
}
.bige-cart-qty button:hover{
    background:#e7f2ef
}
.bige-cart-qty input{
    width:45px;
    height:38px;
    border:0;
    outline:0;
    text-align:center;
    font-weight:800;
    color:#003339
}
.bige-cart-line{
    font-weight:900;
    color:#003339;
    white-space:nowrap
}
.bige-cart-remove{
    border:0;
    background:none;
    color:#c0392b;
    font-weight:700;
    cursor:pointer
}
.bige-cart-summary{
    padding:20px;
    position:sticky;
    top:18px
}
.bige-cart-summary h2{
    margin:0 0 18px;
    color:#003339;
    font-size:20px
}
.bige-cart-row{
    display:flex;
    justify-content:space-between;
    gap:12px;
    margin:12px 0;
    color:#68747c
}
.bige-cart-row strong{color:#003339}
.bige-cart-total{
    border-top:1px solid #e4e9ec;
    padding-top:15px;
    margin-top:16px;
    font-size:19px
}
.bige-cart-checkout{
    width:100%;
    margin-top:18px;
    padding:14px;
    border:0;
    border-radius:11px;
    background:#00b386;
    color:#fff;
    font-weight:900;
    cursor:pointer
}
.bige-cart-checkout:hover{background:#009b75}
.bige-cart-checkout:disabled{
    opacity:.45;
    cursor:not-allowed
}
.bige-cart-note{
    margin-top:12px;
    color:#78838a;
    font-size:12px;
    line-height:1.5
}
.bige-cart-empty{
    text-align:center;
    padding:68px 20px
}
.bige-cart-empty-icon{
    width:70px;
    height:70px;
    margin:0 auto 14px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#e8f7f2;
    color:#00b386;
    font-size:32px
}
.bige-cart-empty h2{
    margin:0 0 7px;
    color:#003339
}
.bige-cart-empty p{
    margin:0 0 18px;
    color:#77828a
}
.bige-cart-shop{
    display:inline-flex;
    gap:7px;
    align-items:center;
    padding:12px 18px;
    background:#00b386;
    color:#fff;
    border-radius:10px;
    text-decoration:none;
    font-weight:800
}
.bige-cart-toast{
    position:fixed;
    right:18px;
    bottom:18px;
    z-index:12000;
    padding:13px 16px;
    border-radius:10px;
    background:#003339;
    color:#fff;
    box-shadow:0 12px 35px rgba(0,0,0,.2);
    opacity:0;
    transform:translateY(10px);
    pointer-events:none;
    transition:.2s
}
.bige-cart-toast.show{
    opacity:1;
    transform:none
}
.bige-cart-modal{
    position:fixed;
    inset:0;
    z-index:11999;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px
}
.bige-cart-modal.open{display:flex}
.bige-cart-backdrop{
    position:absolute;
    inset:0;
    background:rgba(0,30,34,.58)
}
.bige-cart-dialog{
    position:relative;
    width:min(540px,100%);
    max-height:92vh;
    overflow:auto;
    background:#fff;
    border-radius:18px;
    padding:24px;
    box-shadow:0 25px 80px rgba(0,0,0,.25)
}
.bige-cart-close{
    position:absolute;
    right:14px;
    top:12px;
    width:36px;
    height:36px;
    border:0;
    border-radius:50%;
    background:#f0f3f4;
    font-size:22px;
    cursor:pointer
}
.bige-cart-dialog h2{
    margin:5px 45px 6px;
    color:#003339
}
.bige-cart-intro{
    color:#748088;
    font-size:14px;
    margin:0 0 20px
}
.bige-cart-field{margin:14px 0}
.bige-cart-field label{
    display:block;
    margin-bottom:7px;
    color:#003339;
    font-size:13px;
    font-weight:800
}
.bige-cart-field input,
.bige-cart-field textarea,
.bige-cart-field select{
    width:100%;
    padding:12px 13px;
    border:1px solid #d7dfe2;
    border-radius:10px;
    outline:0;
    font:inherit
}
.bige-cart-field input:focus,
.bige-cart-field textarea:focus,
.bige-cart-field select:focus{
    border-color:#00b386;
    box-shadow:0 0 0 3px rgba(0,179,134,.1)
}
.bige-cart-message{
    display:none;
    margin-top:13px;
    padding:11px;
    border-radius:9px;
    background:#fff0f1;
    color:#a51d2a;
    font-size:14px
}
.bige-cart-message.show{display:block}
.bige-cart-success{
    display:none;
    text-align:center;
    padding:15px 4px
}
.bige-cart-success.show{display:block}
.bige-cart-success-icon{
    width:64px;
    height:64px;
    margin:5px auto 14px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#e7f8f2;
    color:#00b386;
    font-size:30px
}
.bige-cart-order-no{
    font-size:19px;
    font-weight:900;
    color:#003339;
    margin:10px 0
}
.bige-cart-success p{
    color:#748088;
    line-height:1.5
}
.bige-cart-actions{
    display:flex;
    justify-content:center;
    gap:10px;
    margin-top:18px
}
.bige-cart-actions a{
    padding:11px 15px;
    border-radius:10px;
    text-decoration:none;
    font-weight:800
}
.bige-cart-primary{
    background:#00b386;
    color:#fff
}
.bige-cart-secondary{
    background:#edf1f3;
    color:#003339
}
.bige-confirm{
    position:fixed;
    inset:0;
    z-index:13000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px
}
.bige-confirm.open{display:flex}
.bige-confirm-bg{
    position:absolute;
    inset:0;
    background:rgba(0,0,0,.52)
}
.bige-confirm-box{
    position:relative;
    width:min(420px,100%);
    padding:24px;
    background:#fff;
    border-radius:16px;
    text-align:center;
    box-shadow:0 20px 70px rgba(0,0,0,.25)
}
.bige-confirm-icon{
    width:55px;
    height:55px;
    margin:0 auto 12px;
    border-radius:50%;
    display:grid;
    place-items:center;
    background:#fff3e8;
    color:#d97706;
    font-size:26px
}
.bige-confirm-box h3{
    margin:0 0 7px;
    color:#003339
}
.bige-confirm-box p{
    margin:0;
    color:#758088
}
.bige-confirm-actions{
    display:flex;
    justify-content:center;
    gap:10px;
    margin-top:20px
}
.bige-confirm-actions button{
    padding:11px 18px;
    border:0;
    border-radius:9px;
    font-weight:800;
    cursor:pointer
}
.bige-confirm-cancel{
    background:#edf1f3;
    color:#003339
}
.bige-confirm-danger{
    background:#c0392b;
    color:#fff
}
@media(max-width:800px){
    .bige-cart-page{
        padding:20px 0 50px
    }
    .bige-cart-wrap{
        width:calc(100% - 20px)
    }
    .bige-cart-heading{
        align-items:flex-start;
        flex-direction:column
    }
    .bige-cart-back{
        width:100%;
        justify-content:center
    }
    .bige-cart-layout{
        grid-template-columns:1fr
    }
    .bige-cart-summary{
        position:static
    }
    .bige-cart-item{
        grid-template-columns:60px minmax(0,1fr) auto;
        gap:11px;
        padding:14px 12px
    }
    .bige-cart-thumb{
        width:60px;
        height:60px
    }
    .bige-cart-qty{
        grid-column:2
    }
    .bige-cart-line{
        grid-column:3;
        grid-row:1
    }
    .bige-cart-remove{
        grid-column:3;
        grid-row:2
    }
}
@media(max-width:430px){
    .bige-cart-heading h1{font-size:25px}
    .bige-cart-dialog{padding:20px 16px}
    .bige-cart-actions,
    .bige-confirm-actions{
        flex-direction:column
    }
    .bige-cart-actions a,
    .bige-confirm-actions button{
        width:100%;
        text-align:center
    }
}
</style>

<main class="bige-cart-page">

    <div class="bige-cart-wrap">

        <div class="bige-cart-heading">

            <div>
                <div class="bige-cart-eyebrow">
                    ONLINE PHARMACY
                </div>

                <h1>Your Shopping Cart</h1>

                <p id="cartSubtitle">
                    <?= $cartCount ?>
                    item<?= $cartCount === 1 ? '' : 's' ?>
                    ready for checkout
                </p>
            </div>

            <a
                class="bige-cart-back"
                href="online_store.php?bid=<?= $branchId ?>"
            >
                <i class="mdi mdi-arrow-left"></i>
                Continue Shopping
            </a>

        </div>

        <div class="bige-cart-layout">

            <section class="bige-cart-card">

                <div class="bige-cart-head">
                    <strong>
                        <i class="mdi mdi-cart-outline me-1"></i>
                        Cart Items
                    </strong>

                    <button
                        type="button"
                        id="clearCartBtn"
                        class="bige-cart-clear"
                        <?= !$cart ? 'disabled' : '' ?>
                    >
                        Clear Cart
                    </button>
                </div>

                <div id="cartItems">

                    <?php if (!$cart): ?>

                        <div class="bige-cart-empty">

                            <div class="bige-cart-empty-icon">
                                <i class="mdi mdi-cart-outline"></i>
                            </div>

                            <h2>Your cart is empty</h2>

                            <p>
                                Browse the pharmacy and add the medicines you need.
                            </p>

                            <a
                                class="bige-cart-shop"
                                href="online_store.php?bid=<?= $branchId ?>"
                            >
                                <i class="mdi mdi-store-outline"></i>
                                Start Shopping
                            </a>

                        </div>

                    <?php else: ?>

                        <?php foreach ($cart as $item): ?>

                            <?php
                            $id = (int)($item['id'] ?? 0);
                            $qty = max(
                                1,
                                (int)($item['qty'] ?? 1)
                            );
                            $price = (float)(
                                $item['price'] ?? 0
                            );
                            $image = (string)(
                                $item['image'] ?? ''
                            );
                            $name = (string)(
                                $item['name'] ?? 'Product'
                            );
                            $strength = (string)(
                                $item['strength'] ?? ''
                            );

                            $imageUrl =
                                bige_cart_image_url($image);
                            ?>

                            <article
                                class="bige-cart-item"
                                data-id="<?= $id ?>"
                            >

                                <div class="bige-cart-thumb">

                                    <?php if ($imageUrl): ?>

                                        <img
                                            src="<?= bige_cart_e($imageUrl) ?>"
                                            alt=""
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='block'"
                                        >

                                    <?php endif; ?>

                                    <i
                                        class="mdi mdi-pill"
                                        style="<?= $imageUrl ? 'display:none' : '' ?>"
                                    ></i>

                                </div>

                                <div class="bige-cart-product">

                                    <h3>
                                        <?= bige_cart_e($name) ?>
                                    </h3>

                                    <?php if ($strength): ?>
                                        <small>
                                            <?= bige_cart_e($strength) ?>
                                        </small>
                                    <?php endif; ?>

                                    <div class="bige-cart-price">
                                        <?= bige_cart_money($price) ?>
                                    </div>

                                </div>

                                <div class="bige-cart-qty">

                                    <button
                                        type="button"
                                        data-action="minus"
                                        aria-label="Decrease quantity"
                                    >
                                        âˆ’
                                    </button>

                                    <input
                                        type="number"
                                        min="1"
                                        max="<?= (int)($item['stock'] ?? 999999) ?>"
                                        value="<?= $qty ?>"
                                        inputmode="numeric"
                                    >

                                    <button
                                        type="button"
                                        data-action="plus"
                                        aria-label="Increase quantity"
                                    >
                                        +
                                    </button>

                                </div>

                                <strong class="bige-cart-line">
                                    <?= bige_cart_money($price * $qty) ?>
                                </strong>

                                <button
                                    type="button"
                                    class="bige-cart-remove"
                                    data-action="remove"
                                >
                                    Remove
                                </button>

                            </article>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </section>

            <aside class="bige-cart-card bige-cart-summary">

                <h2>Order Summary</h2>

                <div class="bige-cart-row">
                    <span>Items</span>
                    <strong id="cartCount">
                        <?= $cartCount ?>
                    </strong>
                </div>

                <div class="bige-cart-row">
                    <span>Subtotal</span>
                    <strong id="cartSubtotal">
                        <?= bige_cart_money($subtotal) ?>
                    </strong>
                </div>

                <div class="bige-cart-row">
                    <span>Delivery</span>
                    <span>Confirmed by pharmacy</span>
                </div>

                <div class="bige-cart-row bige-cart-total">
                    <strong>Total</strong>
                    <strong id="cartTotal">
                        <?= bige_cart_money($subtotal) ?>
                    </strong>
                </div>

                <button
                    type="button"
                    id="checkoutBtn"
                    class="bige-cart-checkout"
                    <?= !$cart ? 'disabled' : '' ?>
                >
                    <i class="mdi mdi-lock-check-outline me-1"></i>
                    Proceed to Checkout
                </button>

                <div class="bige-cart-note">
                    Stock and current online price are checked again on
                    the server before your order is created.
                </div>

            </aside>

        </div>

    </div>

</main>

<div id="cartToast" class="bige-cart-toast"></div>

<!-- Checkout -->
<div
    id="checkoutModal"
    class="bige-cart-modal"
    aria-hidden="true"
>

    <div
        class="bige-cart-backdrop"
        data-close-cart
    ></div>

    <section
        class="bige-cart-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="checkoutTitle"
    >

        <button
            type="button"
            class="bige-cart-close"
            data-close-cart
            aria-label="Close"
        >
            Ã—
        </button>

        <div id="checkoutView">

            <div class="bige-cart-eyebrow">
                COMPLETE YOUR ORDER
            </div>

            <h2 id="checkoutTitle">
                Delivery Details
            </h2>

            <p class="bige-cart-intro">
                Enter where you want the pharmacy to deliver your order.
            </p>

            <form id="checkoutForm">

                <div class="bige-cart-field">
                    <label for="customerName">
                        Full Name
                    </label>

                    <input
                        id="customerName"
                        type="text"
                        name="customer_name"
                        maxlength="120"
                        required
                        value="<?= bige_cart_e($customerName) ?>"
                    >
                </div>

                <div class="bige-cart-field">
                    <label for="customerPhone">
                        Phone Number
                    </label>

                    <input
                        id="customerPhone"
                        type="tel"
                        name="phone"
                        maxlength="40"
                        required
                        autocomplete="tel"
                        value="<?= bige_cart_e($customerPhone) ?>"
                    >
                </div>

                <div class="bige-cart-field">
                    <label for="customerAddress">
                        Delivery Address
                    </label>

                    <textarea
                        id="customerAddress"
                        name="address"
                        rows="4"
                        maxlength="500"
                        required
                    ><?= bige_cart_e($customerAddress) ?></textarea>
                </div>

                <div class="bige-cart-field">
                    <label for="paymentMethod">
                        Payment Method
                    </label>

                    <select
                        id="paymentMethod"
                        name="payment_method"
                        required
                    >
                        <option value="Cash on Delivery">
                            Cash on Delivery
                        </option>
                        <option value="Mobile Money">
                            Mobile Money
                        </option>
                        <option value="Bank">
                            Bank / Transfer
                        </option>
                    </select>
                </div>

                <button
                    type="submit"
                    id="placeOrderBtn"
                    class="bige-cart-checkout"
                >
                    <i class="mdi mdi-check-circle-outline me-1"></i>
                    Place Order
                </button>

                <div
                    id="checkoutMessage"
                    class="bige-cart-message"
                ></div>

            </form>

        </div>

        <div
            id="successView"
            class="bige-cart-success"
        >

            <div class="bige-cart-success-icon">
                <i class="mdi mdi-check"></i>
            </div>

            <h2>Order Placed Successfully</h2>

            <div
                id="orderNumber"
                class="bige-cart-order-no"
            ></div>

            <p>
                Your order has been sent to the pharmacy.
                You can follow it from your orders page.
            </p>

            <div class="bige-cart-actions">

                <a
                    class="bige-cart-primary"
                    href="client_orders.php"
                >
                    View My Orders
                </a>

                <a
                    class="bige-cart-secondary"
                    href="online_store.php?bid=<?= $branchId ?>"
                >
                    Continue Shopping
                </a>

            </div>

        </div>

    </section>

</div>

<!-- Custom clear confirmation -->
<div
    id="clearConfirm"
    class="bige-confirm"
    aria-hidden="true"
>

    <div class="bige-confirm-bg"></div>

    <section class="bige-confirm-box">

        <div class="bige-confirm-icon">
            <i class="mdi mdi-cart-remove"></i>
        </div>

        <h3>Clear Shopping Cart?</h3>

        <p>
            All items in this branch cart will be removed.
        </p>

        <div class="bige-confirm-actions">

            <button
                type="button"
                id="cancelClear"
                class="bige-confirm-cancel"
            >
                Cancel
            </button>

            <button
                type="button"
                id="confirmClear"
                class="bige-confirm-danger"
            >
                Clear Cart
            </button>

        </div>

    </section>

</div>

<script>
window.BIGE50_CART = {
    branchId: <?= json_encode($branchId) ?>,
    csrf: <?= json_encode($csrf) ?>,
    api: 'cart.php'
};

(function () {

    'use strict';

    const C = window.BIGE50_CART;

    const $ = id =>
        document.getElementById(id);

    const items =
        $('cartItems');

    const count =
        $('cartCount');

    const subtotal =
        $('cartSubtotal');

    const total =
        $('cartTotal');

    const subtitle =
        $('cartSubtitle');

    const clearBtn =
        $('clearCartBtn');

    const checkoutBtn =
        $('checkoutBtn');

    const toast =
        $('cartToast');

    const checkoutModal =
        $('checkoutModal');

    const checkoutView =
        $('checkoutView');

    const successView =
        $('successView');

    const checkoutForm =
        $('checkoutForm');

    const placeBtn =
        $('placeOrderBtn');

    const message =
        $('checkoutMessage');

    const clearConfirm =
        $('clearConfirm');

    const cancelClear =
        $('cancelClear');

    const confirmClear =
        $('confirmClear');

    let toastTimer = null;

    function money(value) {

        return 'K ' +
            Number(value || 0).toLocaleString(
                'en-ZM',
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );
    }

    function esc(value) {

        return String(value ?? '')
            .replace(/[&<>'"]/g, function (char) {

                return {
                    '&':'&amp;',
                    '<':'&lt;',
                    '>':'&gt;',
                    "'":'&#39;',
                    '"':'&quot;'
                }[char];

            });
    }

    function notify(text, error = false) {

        toast.textContent =
            text;

        toast.style.background =
            error
                ? '#a51d2a'
                : '#003339';

        toast.classList.add('show');

        clearTimeout(toastTimer);

        toastTimer =
            setTimeout(
                () => toast.classList.remove('show'),
                2800
            );
    }

    function syncHeaderBadge(value) {

        document
            .querySelectorAll(
                '.cart-badge, .cart-count'
            )
            .forEach(function (el) {

                el.textContent =
                    Number(value || 0);

            });
    }

    async function api(data) {

        data.bid =
            C.branchId;

        data.csrf =
            C.csrf;

        const response =
            await fetch(
                C.api,
                {
                    method:'POST',
                    headers:{
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept':
                            'application/json'
                    },
                    body:
                        new URLSearchParams(data)
                }
            );

        let result;

        try {
            result =
                await response.json();
        } catch (error) {
            throw new Error(
                'The cart server returned an invalid response.'
            );
        }

        if (!result.success) {

            const error =
                new Error(
                    result.message ||
                    'Cart request failed.'
                );

            Object.assign(
                error,
                result
            );

            throw error;
        }

        return result;
    }

    function sync(result) {

        const list =
            Array.isArray(result.items)
                ? result.items
                : [];

        const itemCount =
            Number(
                result.cart_count || 0
            );

        const sub =
            Number(
                result.subtotal || 0
            );

        const grand =
            Number(
                result.total ?? sub
            );

        count.textContent =
            itemCount;

        subtotal.textContent =
            money(sub);

        total.textContent =
            money(grand);

        subtitle.textContent =
            `${itemCount} item${itemCount === 1 ? '' : 's'} ready for checkout`;

        clearBtn.disabled =
            !list.length;

        checkoutBtn.disabled =
            !list.length;

        syncHeaderBadge(
            itemCount
        );

        if (!list.length) {

            items.innerHTML = `
                <div class="bige-cart-empty">
                    <div class="bige-cart-empty-icon">
                        <i class="mdi mdi-cart-outline"></i>
                    </div>
                    <h2>Your cart is empty</h2>
                    <p>
                        Browse the pharmacy and add the medicines you need.
                    </p>
                    <a
                        class="bige-cart-shop"
                        href="online_store.php?bid=${encodeURIComponent(C.branchId)}"
                    >
                        <i class="mdi mdi-store-outline"></i>
                        Start Shopping
                    </a>
                </div>
            `;

            return;
        }

        items.innerHTML =
            list.map(function (item) {

                const id =
                    Number(item.id || 0);

                const qty =
                    Math.max(
                        1,
                        Number(item.qty || 1)
                    );

                const price =
                    Number(item.price || 0);

                const stock =
                    Math.max(
                        1,
                        Number(item.stock || 999999)
                    );

                const image =
                    item.image || '';

                const imageUrl =
                    image
                        ? (
                            (window.location.pathname
                                .split('/')
                                .pop() === 'cart.php'
                                ? ''
                                : ''
                            ) +
                            'uploads/products/' +
                            encodeURIComponent(
                                String(image)
                                    .split('/')
                                    .pop()
                            )
                        )
                        : '';

                return `
                    <article
                        class="bige-cart-item"
                        data-id="${id}"
                    >

                        <div class="bige-cart-thumb">
                            ${
                                imageUrl
                                    ? `
                                        <img
                                            src="${esc(imageUrl)}"
                                            alt=""
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='block'"
                                        >
                                    `
                                    : ''
                            }

                            <i
                                class="mdi mdi-pill"
                                style="${imageUrl ? 'display:none' : ''}"
                            ></i>
                        </div>

                        <div class="bige-cart-product">
                            <h3>
                                ${esc(item.name || 'Product')}
                            </h3>

                            ${
                                item.strength
                                    ? `<small>${esc(item.strength)}</small>`
                                    : ''
                            }

                            <div class="bige-cart-price">
                                ${money(price)}
                            </div>
                        </div>

                        <div class="bige-cart-qty">

                            <button
                                type="button"
                                data-action="minus"
                            >
                                âˆ’
                            </button>

                            <input
                                type="number"
                                min="1"
                                max="${stock}"
                                value="${qty}"
                                inputmode="numeric"
                            >

                            <button
                                type="button"
                                data-action="plus"
                            >
                                +
                            </button>

                        </div>

                        <strong class="bige-cart-line">
                            ${money(price * qty)}
                        </strong>

                        <button
                            type="button"
                            class="bige-cart-remove"
                            data-action="remove"
                        >
                            Remove
                        </button>

                    </article>
                `;

            }).join('');
    }

    /* -----------------------------------------------------
       Quantity / remove
    ----------------------------------------------------- */
    items.addEventListener(
        'click',
        async function (event) {

            const row =
                event.target.closest(
                    '.bige-cart-item'
                );

            if (!row) return;

            const button =
                event.target.closest(
                    '[data-action]'
                );

            if (!button) return;

            const id =
                Number(
                    row.dataset.id || 0
                );

            const action =
                button.dataset.action;

            try {

                if (action === 'remove') {

                    sync(
                        await api({
                            action:'remove',
                            item_id:id
                        })
                    );

                    notify(
                        'Item removed.'
                    );

                    return;
                }

                if (
                    action === 'plus' ||
                    action === 'minus'
                ) {

                    const input =
                        row.querySelector(
                            'input'
                        );

                    let qty =
                        Math.max(
                            1,
                            parseInt(
                                input.value || '1',
                                10
                            )
                        );

                    if (action === 'plus') {
                        qty++;
                    } else {
                        qty--;
                    }

                    if (qty <= 0) {

                        sync(
                            await api({
                                action:'remove',
                                item_id:id
                            })
                        );

                        notify(
                            'Item removed.'
                        );

                        return;
                    }

                    sync(
                        await api({
                            action:'update',
                            item_id:id,
                            qty:qty
                        })
                    );

                    notify(
                        'Cart updated.'
                    );
                }

            } catch (error) {

                if (error.login_required) {
                    window.location.href =
                        'login_client.php?redirect=' +
                        encodeURIComponent(
                            'cart.php?bid=' +
                            C.branchId
                        );
                    return;
                }

                notify(
                    error.message ||
                    'Unable to update cart.',
                    true
                );
            }
        }
    );

    items.addEventListener(
        'change',
        async function (event) {

            if (
                !event.target.matches(
                    '.bige-cart-qty input'
                )
            ) {
                return;
            }

            const row =
                event.target.closest(
                    '.bige-cart-item'
                );

            if (!row) return;

            const id =
                Number(
                    row.dataset.id || 0
                );

            let qty =
                parseInt(
                    event.target.value || '1',
                    10
                );

            if (!Number.isFinite(qty)) {
                qty = 1;
            }

            try {

                sync(
                    await api({
                        action:'update',
                        item_id:id,
                        qty:Math.max(1, qty)
                    })
                );

                notify(
                    'Cart updated.'
                );

            } catch (error) {

                notify(
                    error.message ||
                    'Unable to update cart.',
                    true
                );

            }
        }
    );

    /* -----------------------------------------------------
       Clear cart confirmation
    ----------------------------------------------------- */
    clearBtn.addEventListener(
        'click',
        function () {

            if (clearBtn.disabled) {
                return;
            }

            clearConfirm.classList.add(
                'open'
            );

            clearConfirm.setAttribute(
                'aria-hidden',
                'false'
            );
        }
    );

    cancelClear.addEventListener(
        'click',
        function () {

            clearConfirm.classList.remove(
                'open'
            );

            clearConfirm.setAttribute(
                'aria-hidden',
                'true'
            );
        }
    );

    confirmClear.addEventListener(
        'click',
        async function () {

            confirmClear.disabled =
                true;

            try {

                sync(
                    await api({
                        action:'clear'
                    })
                );

                notify(
                    'Cart cleared.'
                );

            } catch (error) {

                notify(
                    error.message ||
                    'Unable to clear cart.',
                    true
                );

            } finally {

                confirmClear.disabled =
                    false;

                clearConfirm.classList.remove(
                    'open'
                );

                clearConfirm.setAttribute(
                    'aria-hidden',
                    'true'
                );
            }
        }
    );

    /* -----------------------------------------------------
       Checkout
    ----------------------------------------------------- */
    checkoutBtn.addEventListener(
        'click',
        function () {

            if (checkoutBtn.disabled) {
                return;
            }

            checkoutView.style.display =
                'block';

            successView.classList.remove(
                'show'
            );

            checkoutModal.classList.add(
                'open'
            );

            checkoutModal.setAttribute(
                'aria-hidden',
                'false'
            );

            document.body.style.overflow =
                'hidden';
        }
    );

    document
        .querySelectorAll(
            '[data-close-cart]'
        )
        .forEach(function (element) {

            element.addEventListener(
                'click',
                function () {

                    checkoutModal.classList.remove(
                        'open'
                    );

                    checkoutModal.setAttribute(
                        'aria-hidden',
                        'true'
                    );

                    document.body.style.overflow =
                        '';
                }
            );

        });

    checkoutForm.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();

            message.className =
                'bige-cart-message';

            message.textContent =
                '';

            placeBtn.disabled =
                true;

            placeBtn.innerHTML =
                '<i class="mdi mdi-loading mdi-spin me-1"></i> Placing Order...';

            try {

                const data =
                    Object.fromEntries(
                        new FormData(
                            checkoutForm
                        ).entries()
                    );

                data.action =
                    'checkout';

                const result =
                    await api(data);

                checkoutView.style.display =
                    'none';

                successView.classList.add(
                    'show'
                );

                $('orderNumber').textContent =
                    result.order_number
                        ? 'Order #' +
                          result.order_number
                        : 'Order submitted';

                sync({
                    items:[],
                    cart_count:0,
                    subtotal:0,
                    total:0
                });

            } catch (error) {

                if (error.login_required) {

                    window.location.href =
                        'login_client.php?redirect=' +
                        encodeURIComponent(
                            'cart.php?bid=' +
                            C.branchId
                        );

                    return;
                }

                message.textContent =
                    error.message ||
                    'Unable to place the order.';

                message.className =
                    'bige-cart-message show';

            } finally {

                placeBtn.disabled =
                    false;

                placeBtn.innerHTML =
                    '<i class="mdi mdi-check-circle-outline me-1"></i> Place Order';
            }
        }
    );

    document.addEventListener(
        'keydown',
        function (event) {

            if (event.key !== 'Escape') {
                return;
            }

            checkoutModal.classList.remove(
                'open'
            );

            clearConfirm.classList.remove(
                'open'
            );

            document.body.style.overflow =
                '';
        }
    );

    /* -----------------------------------------------------
       Initial badge sync
    ----------------------------------------------------- */
    syncHeaderBadge(
        <?= (int)$cartCount ?>
    );

})();
</script>

</body>
</html>
