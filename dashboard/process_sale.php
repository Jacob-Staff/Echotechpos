<?php
/**
 * ============================================================
 * EchoTech POS
 * Secure POS Sale Processor
 * ============================================================
 *
 * This endpoint is the authoritative source for:
 *
 *   - pharmacy
 *   - branch
 *   - product
 *   - price
 *   - quantity
 *   - stock availability
 *   - subtotal
 *   - VAT
 *   - total
 *   - payment method
 *   - invoice number
 *
 * NEVER trust prices, totals, pharmacy IDs or branch IDs
 * supplied by JavaScript.
 * ============================================================
 */

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/conn.php';


/*
|--------------------------------------------------------------------------
| Helper: JSON error response
|--------------------------------------------------------------------------
*/

function sale_error(string $message, int $httpCode = 400): never
{
    http_response_code($httpCode);

    echo json_encode([
        'status'  => 'error',
        'message' => $message,
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Only POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sale_error('Invalid request method.', 405);
}


/*
|--------------------------------------------------------------------------
| Authenticated tenant information
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

$pharmacyId = (int) ($_SESSION['pharmacy_id'] ?? 0);

$branchId = (int) ($_SESSION['branch_id'] ?? 0);

$issuedBy =
    trim(
        (string) (
            $_SESSION['full_name']
            ?? $_SESSION['sessionUsername']
            ?? $_SESSION['username']
            ?? ''
        )
    );


if ($userId <= 0) {
    sale_error('Your session has expired. Please log in again.', 401);
}


if ($pharmacyId <= 0) {
    sale_error('Your account is not assigned to a valid pharmacy.', 403);
}


if ($branchId <= 0) {
    sale_error('Your account is not assigned to a valid branch.', 403);
}


if ($issuedBy === '') {
    $issuedBy = 'Staff';
}


/*
|--------------------------------------------------------------------------
| Verify branch belongs to the current pharmacy
|--------------------------------------------------------------------------
*/

try {

    $branchStmt = $conn->prepare("
        SELECT id
        FROM branches
        WHERE id = ?
          AND pharmacy_id = ?
          AND is_active = 1
        LIMIT 1
    ");

    $branchStmt->bind_param(
        'ii',
        $branchId,
        $pharmacyId
    );

    $branchStmt->execute();

    $branchResult = $branchStmt->get_result();

    if ($branchResult->num_rows === 0) {
        $branchStmt->close();

        sale_error(
            'The selected branch is not valid for your pharmacy.',
            403
        );
    }

    $branchStmt->close();

} catch (Throwable $e) {

    error_log(
        'POS branch validation failed: '
        . $e->getMessage()
    );

    sale_error(
        'Unable to validate the branch.',
        500
    );
}


/*
|--------------------------------------------------------------------------
| Payment method
|--------------------------------------------------------------------------
*/

$paymentMethod = trim(
    (string) ($_POST['payment_method'] ?? '')
);


$allowedPaymentMethods = [
    'Cash',
    'Card',
    'Mobile Money',
];


if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    sale_error('Invalid payment method.');
}


/*
|--------------------------------------------------------------------------
| Decode cart
|--------------------------------------------------------------------------
*/

$rawCart = $_POST['cart'] ?? '';

if ($rawCart === '') {
    sale_error('Cart is empty.');
}


$cart = json_decode(
    $rawCart,
    true
);


if (!is_array($cart) || count($cart) === 0) {
    sale_error('Cart is empty or invalid.');
}


/*
|--------------------------------------------------------------------------
| Normalize cart
|--------------------------------------------------------------------------
|
| We accept ONLY:
|
|   id
|   qty
|
| The following values from JavaScript are NOT trusted:
|
|   name
|   price
|   stock
|   total
|
|--------------------------------------------------------------------------
*/

$items = [];


foreach ($cart as $cartItem) {

    if (!is_array($cartItem)) {
        continue;
    }

    $productId = (int) ($cartItem['id'] ?? 0);

    $quantity = (int) ($cartItem['qty'] ?? 0);


    if ($productId <= 0) {
        sale_error('Invalid product in cart.');
    }


    if ($quantity <= 0) {
        sale_error('Invalid product quantity.');
    }


    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate product rows
    |--------------------------------------------------------------------------
    |
    | If JavaScript sends the same product twice, combine the quantities.
    |
    */

    if (isset($items[$productId])) {

        $items[$productId]['quantity'] += $quantity;

    } else {

        $items[$productId] = [
            'product_id' => $productId,
            'quantity'   => $quantity,
        ];
    }
}


if (count($items) === 0) {
    sale_error('Cart contains no valid products.');
}


/*
|--------------------------------------------------------------------------
| Maximum transaction protection
|--------------------------------------------------------------------------
*/

$totalQuantity = 0;

foreach ($items as $item) {
    $totalQuantity += $item['quantity'];
}


if ($totalQuantity > 1000) {
    sale_error('Transaction contains too many items.');
}


/*
|--------------------------------------------------------------------------
| Begin transaction
|--------------------------------------------------------------------------
*/

$transactionStarted = false;


try {

    $conn->begin_transaction();

    $transactionStarted = true;


    /*
    |--------------------------------------------------------------------------
    | Authoritative product lookup
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Prices come from the database.
    | Stock comes from the database.
    | Pharmacy comes from the database.
    | Branch comes from the database.
    |
    */

    $productStmt = $conn->prepare("
        SELECT
            id,
            item_name,
            price,
            quantity,
            expiry_date,
            is_active,
            pharmacy_id,
            branch_id
        FROM store_items
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
        LIMIT 1
        FOR UPDATE
    ");


    /*
    |--------------------------------------------------------------------------
    | Stock update statement
    |--------------------------------------------------------------------------
    */

    $stockStmt = $conn->prepare("
        UPDATE store_items
        SET quantity = quantity - ?
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
          AND quantity >= ?
    ");


    /*
    |--------------------------------------------------------------------------
    | Sales calculations
    |--------------------------------------------------------------------------
    */

    $saleTotal = 0.00;

    $saleSubtotal = 0.00;

    $saleVat = 0.00;

    $saleItems = [];


    /*
    |--------------------------------------------------------------------------
    | Validate every product and calculate authoritative totals
    |--------------------------------------------------------------------------
    */

    foreach ($items as $item) {

        $productId = $item['product_id'];

        $quantity = $item['quantity'];


        /*
        |--------------------------------------------------------------------------
        | Lock product row
        |--------------------------------------------------------------------------
        */

        $productStmt->bind_param(
            'iii',
            $productId,
            $pharmacyId,
            $branchId
        );

        $productStmt->execute();

        $productResult = $productStmt->get_result();

        $product = $productResult->fetch_assoc();


        if (!$product) {

            throw new RuntimeException(
                'Product #' . $productId . ' is not available in this branch.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Active product
        |--------------------------------------------------------------------------
        */

        if ((int) $product['is_active'] !== 1) {

            throw new RuntimeException(
                'Product "' . $product['item_name'] . '" is inactive.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Expiry validation
        |--------------------------------------------------------------------------
        */

        if (!empty($product['expiry_date'])) {

            $expiryDate = $product['expiry_date'];

            if (
                $expiryDate !== '0000-00-00'
                &&
                $expiryDate < date('Y-m-d')
            ) {

                throw new RuntimeException(
                    'Product "' . $product['item_name'] . '" has expired.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Stock validation
        |--------------------------------------------------------------------------
        */

        $availableStock = (int) $product['quantity'];


        if ($availableStock < $quantity) {

            throw new RuntimeException(
                'Insufficient stock for "' .
                $product['item_name'] .
                '". Available: ' .
                $availableStock .
                ', requested: ' .
                $quantity .
                '.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | AUTHORITATIVE PRICE
        |--------------------------------------------------------------------------
        */

        $unitPrice = (float) $product['price'];


        if ($unitPrice < 0) {

            throw new RuntimeException(
                'Invalid price for product "' .
                $product['item_name'] .
                '".'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Line total
        |--------------------------------------------------------------------------
        */

        $lineTotal = round(
            $unitPrice * $quantity,
            2
        );


        /*
        |--------------------------------------------------------------------------
        | Current POS uses VAT-inclusive prices.
        |
        | 16% VAT:
        |
        | subtotal = total / 1.16
        | VAT      = total - subtotal
        |--------------------------------------------------------------------------
        */

        $lineSubtotal = round(
            $lineTotal / 1.16,
            2
        );


        $lineVat = round(
            $lineTotal - $lineSubtotal,
            2
        );


        $saleTotal += $lineTotal;

        $saleSubtotal += $lineSubtotal;

        $saleVat += $lineVat;


        /*
        |--------------------------------------------------------------------------
        | Keep authoritative item information for sales_items
        |--------------------------------------------------------------------------
        */

        $saleItems[] = [
            'product_id' => $productId,
            'item_name'  => $product['item_name'],
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Round final financial values
    |--------------------------------------------------------------------------
    */

    $saleTotal = round($saleTotal, 2);

    $saleSubtotal = round($saleSubtotal, 2);

    $saleVat = round($saleVat, 2);


    if ($saleTotal <= 0) {
        throw new RuntimeException(
            'Sale total must be greater than zero.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Generate invoice number
    |--------------------------------------------------------------------------
    */

    $invoiceNo = '';

    for ($attempt = 0; $attempt < 5; $attempt++) {

        $invoiceNo =
            'PH-'
            . date('ymd')
            . '-'
            . strtoupper(
                bin2hex(
                    random_bytes(4)
                )
            );


        $invoiceCheck = $conn->prepare("
            SELECT id
            FROM sales
            WHERE invoice = ?
            LIMIT 1
        ");


        $invoiceCheck->bind_param(
            's',
            $invoiceNo
        );


        $invoiceCheck->execute();

        $invoiceResult = $invoiceCheck->get_result();

        $invoiceExists =
            $invoiceResult->num_rows > 0;


        $invoiceCheck->close();


        if (!$invoiceExists) {
            break;
        }


        $invoiceNo = '';
    }


    if ($invoiceNo === '') {

        throw new RuntimeException(
            'Unable to generate a unique invoice number.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Insert sale header
    |--------------------------------------------------------------------------
    |
    | The existing schema has:
    |
    |   pharmacy_id
    |   branch_id
    |   issued_by
    |   invoice
    |   total
    |   payment
    |   user_id
    |   total_amount
    |   subtotal
    |   vat_amount
    |   payment_method
    |   sale_date
    |   created_at
    |
    */

    $saleStmt = $conn->prepare("
        INSERT INTO sales (
            pharmacy_id,
            branch_id,
            issued_by,
            invoice,
            total,
            payment,
            user_id,
            total_amount,
            subtotal,
            vat_amount,
            payment_method,
            sale_date,
            created_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW(),
            NOW()
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | For the current POS, payment equals the sale total.
    |
    | Cash change/tender handling can be added in the next
    | checkout enhancement without changing the core sale
    | security.
    |--------------------------------------------------------------------------
    */

    $paymentAmount = $saleTotal;


    $saleStmt->bind_param(
        'iissddiddds',
        $pharmacyId,
        $branchId,
        $issuedBy,
        $invoiceNo,
        $saleTotal,
        $paymentAmount,
        $userId,
        $saleTotal,
        $saleSubtotal,
        $saleVat,
        $paymentMethod
    );


    $saleStmt->execute();


    $saleId = (int) $conn->insert_id;


    if ($saleId <= 0) {

        throw new RuntimeException(
            'Unable to create sale record.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Insert sale items + deduct stock
    |--------------------------------------------------------------------------
    */

    $itemStmt = $conn->prepare("
        INSERT INTO sales_items (
            sale_id,
            pharmacy_id,
            branch_id,
            product_id,
            quantity,
            unit_price
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");


    foreach ($saleItems as $saleItem) {

        $productId = $saleItem['product_id'];

        $quantity = $saleItem['quantity'];

        $unitPrice = $saleItem['unit_price'];


        /*
        |--------------------------------------------------------------------------
        | Deduct stock atomically
        |--------------------------------------------------------------------------
        */

        $stockStmt->bind_param(
            'iiiii',
            $quantity,
            $productId,
            $pharmacyId,
            $branchId,
            $quantity
        );


        $stockStmt->execute();


        if ($stockStmt->affected_rows !== 1) {

            throw new RuntimeException(
                'Stock changed while processing "' .
                $saleItem['item_name'] .
                '". Please retry the transaction.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Record sale line
        |--------------------------------------------------------------------------
        */

        $itemStmt->bind_param(
            'iiiiid',
            $saleId,
            $pharmacyId,
            $branchId,
            $productId,
            $quantity,
            $unitPrice
        );


        $itemStmt->execute();
    }


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    $transactionStarted = false;


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'status'       => 'success',
        'sale_id'      => $saleId,
        'invoice'      => $invoiceNo,
        'subtotal'     => number_format($saleSubtotal, 2, '.', ''),
        'vat'          => number_format($saleVat, 2, '.', ''),
        'total'        => number_format($saleTotal, 2, '.', ''),
        'payment_method' => $paymentMethod,
        'items'        => array_map(
            static function (array $item): array {
                return [
                    'id'         => $item['product_id'],
                    'name'       => $item['item_name'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => number_format(
                        $item['unit_price'],
                        2,
                        '.',
                        ''
                    ),
                    'line_total' => number_format(
                        $item['line_total'],
                        2,
                        '.',
                        ''
                    ),
                ];
            },
            $saleItems
        ),
    ]);

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | Rollback everything
    |--------------------------------------------------------------------------
    */

    if ($transactionStarted) {

        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                'POS rollback failed: '
                . $rollbackError->getMessage()
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Log technical details
    |--------------------------------------------------------------------------
    */

    error_log(
        'POS sale failed: '
        . $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | Safe customer-facing message
    |--------------------------------------------------------------------------
    */

    $message = $e->getMessage();


    /*
    |--------------------------------------------------------------------------
    | Avoid exposing SQL/internal errors
    |--------------------------------------------------------------------------
    */

    if (
        stripos($message, 'SQL') !== false
        ||
        stripos($message, 'mysqli') !== false
        ||
        stripos($message, 'database') !== false
        ||
        stripos($message, 'prepare') !== false
    ) {

        $message =
            'The sale could not be completed because of a database error.';
    }


    http_response_code(400);


    echo json_encode([
        'status'  => 'error',
        'message' => $message,
    ]);
}


exit;
