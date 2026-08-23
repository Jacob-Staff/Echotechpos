<?php
/**
 * ============================================================
 * EchoTech POS
 * Secure POS Sale Processor
 * ============================================================
 *
 * Authoritative server-side sale processing.
 *
 * The browser may send:
 *   - product IDs
 *   - quantities
 *   - payment method
 *   - amount received
 *
 * The server determines:
 *   - pharmacy
 *   - branch
 *   - product
 *   - price
 *   - stock
 *   - subtotal
 *   - VAT
 *   - total
 *   - change
 *
 * All financial and stock validation is performed here.
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
| Safe JSON error response
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
| POST only
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sale_error('Invalid request method.', 405);
}


/*
|--------------------------------------------------------------------------
| Authenticated tenant context
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

$pharmacyId = (int) ($_SESSION['pharmacy_id'] ?? 0);

$branchId = (int) ($_SESSION['branch_id'] ?? 0);

$issuedBy = trim(
    (string) (
        $_SESSION['full_name']
        ?? $_SESSION['sessionUsername']
        ?? $_SESSION['username']
        ?? ''
    )
);


if ($userId <= 0) {
    sale_error(
        'Your session has expired. Please log in again.',
        401
    );
}


if ($pharmacyId <= 0) {
    sale_error(
        'Your account is not assigned to a valid pharmacy.',
        403
    );
}


if ($branchId <= 0) {
    sale_error(
        'Your account is not assigned to a valid branch.',
        403
    );
}


if ($issuedBy === '') {
    $issuedBy = 'Staff';
}


/*
|--------------------------------------------------------------------------
| Validate branch belongs to pharmacy
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
        'POS branch validation failed: ' .
        $e->getMessage()
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


if (!in_array(
    $paymentMethod,
    $allowedPaymentMethods,
    true
)) {

    sale_error(
        'Invalid payment method.'
    );
}


/*
|--------------------------------------------------------------------------
| Cart
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
| Only product ID and quantity are trusted as requests.
|
*/

$items = [];


foreach ($cart as $cartItem) {

    if (!is_array($cartItem)) {
        continue;
    }

    $productId = (int) (
        $cartItem['id'] ?? 0
    );

    $quantity = (int) (
        $cartItem['qty'] ?? 0
    );


    if ($productId <= 0) {
        sale_error(
            'Invalid product in cart.'
        );
    }


    if ($quantity <= 0) {
        sale_error(
            'Invalid product quantity.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Combine duplicate product rows
    |--------------------------------------------------------------------------
    */

    if (isset($items[$productId])) {

        $items[$productId]['quantity'] +=
            $quantity;

    } else {

        $items[$productId] = [
            'product_id' => $productId,
            'quantity'   => $quantity,
        ];
    }
}


if (count($items) === 0) {
    sale_error(
        'Cart contains no valid products.'
    );
}


$totalQuantity = 0;

foreach ($items as $item) {
    $totalQuantity +=
        $item['quantity'];
}


if ($totalQuantity > 1000) {
    sale_error(
        'Transaction contains too many items.'
    );
}


/*
|--------------------------------------------------------------------------
| Amount received
|--------------------------------------------------------------------------
*/

$rawAmountReceived =
    $_POST['amount_received'] ?? '';


/*
|--------------------------------------------------------------------------
| Financial input validation
|--------------------------------------------------------------------------
*/

if (
    is_array($rawAmountReceived)
    ||
    is_object($rawAmountReceived)
) {

    sale_error(
        'Invalid amount received.'
    );
}


$rawAmountReceived =
    trim((string) $rawAmountReceived);


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
    | Product lookup
    |--------------------------------------------------------------------------
    |
    | FOR UPDATE locks each product row while the sale is being
    | calculated and stock is being deducted.
    |--------------------------------------------------------------------------
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
    | Atomic stock deduction
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
    | Financial totals
    |--------------------------------------------------------------------------
    */

    $saleTotal = 0.00;

    $saleSubtotal = 0.00;

    $saleVat = 0.00;

    $saleItems = [];


    /*
    |--------------------------------------------------------------------------
    | Validate products and calculate authoritative totals
    |--------------------------------------------------------------------------
    */

    foreach ($items as $item) {

        $productId =
            $item['product_id'];

        $quantity =
            $item['quantity'];


        /*
        |--------------------------------------------------------------------------
        | Lock product
        |--------------------------------------------------------------------------
        */

        $productStmt->bind_param(
            'iii',
            $productId,
            $pharmacyId,
            $branchId
        );

        $productStmt->execute();

        $productResult =
            $productStmt->get_result();

        $product =
            $productResult->fetch_assoc();


        if (!$product) {

            throw new RuntimeException(
                'Product #' .
                $productId .
                ' is not available in this branch.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Active product
        |--------------------------------------------------------------------------
        */

        if (
            (int) $product['is_active'] !== 1
        ) {

            throw new RuntimeException(
                'Product "' .
                $product['item_name'] .
                '" is inactive.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Expiry
        |--------------------------------------------------------------------------
        */

        if (
            !empty($product['expiry_date'])
        ) {

            $expiryDate =
                $product['expiry_date'];

            if (
                $expiryDate !== '0000-00-00'
                &&
                $expiryDate < date('Y-m-d')
            ) {

                throw new RuntimeException(
                    'Product "' .
                    $product['item_name'] .
                    '" has expired.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Stock
        |--------------------------------------------------------------------------
        */

        $availableStock =
            (int) $product['quantity'];


        if (
            $availableStock < $quantity
        ) {

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
        | Authoritative price
        |--------------------------------------------------------------------------
        */

        $unitPrice =
            (float) $product['price'];


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
        | Current POS pricing is VAT-inclusive at 16%.
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


        $saleTotal +=
            $lineTotal;

        $saleSubtotal +=
            $lineSubtotal;

        $saleVat +=
            $lineVat;


        $saleItems[] = [
            'product_id' =>
                $productId,

            'item_name' =>
                $product['item_name'],

            'quantity' =>
                $quantity,

            'unit_price' =>
                $unitPrice,

            'line_total' =>
                $lineTotal,
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Final rounding
    |--------------------------------------------------------------------------
    */

    $saleTotal =
        round($saleTotal, 2);

    $saleSubtotal =
        round($saleSubtotal, 2);

    $saleVat =
        round($saleVat, 2);


    if ($saleTotal <= 0) {

        throw new RuntimeException(
            'Sale total must be greater than zero.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Validate payment amount
    |--------------------------------------------------------------------------
    */

    if ($paymentMethod === 'Cash') {

        if (
            $rawAmountReceived === ''
            ||
            !is_numeric($rawAmountReceived)
        ) {

            throw new RuntimeException(
                'Please enter the amount of cash received.'
            );
        }

        $amountReceived =
            round(
                (float) $rawAmountReceived,
                2
            );

    } else {

        /*
        |--------------------------------------------------------------------------
        | Card and Mobile Money are exact payments.
        |--------------------------------------------------------------------------
        */

        $amountReceived =
            $saleTotal;

        /*
        |--------------------------------------------------------------------------
        | If a value was submitted for non-cash, it must still agree
        | with the authoritative total.
        |--------------------------------------------------------------------------
        */

        if ($rawAmountReceived !== '') {

            if (
                !is_numeric($rawAmountReceived)
                ||
                round(
                    (float) $rawAmountReceived,
                    2
                ) !== $saleTotal
            ) {

                throw new RuntimeException(
                    'Payment amount does not match the sale total.'
                );
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | No negative payment
    |--------------------------------------------------------------------------
    */

    if ($amountReceived < 0) {

        throw new RuntimeException(
            'Amount received cannot be negative.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Cash cannot be less than total
    |--------------------------------------------------------------------------
    */

    if (
        $paymentMethod === 'Cash'
        &&
        $amountReceived < $saleTotal
    ) {

        throw new RuntimeException(
            'Cash received is less than the total due.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Change
    |--------------------------------------------------------------------------
    */

    $changeDue = round(
        $amountReceived - $saleTotal,
        2
    );


    if ($changeDue < 0) {
        $changeDue = 0.00;
    }


    /*
    |--------------------------------------------------------------------------
    | Generate unique invoice
    |--------------------------------------------------------------------------
    */

    $invoiceNo = '';


    for (
        $attempt = 0;
        $attempt < 5;
        $attempt++
    ) {

        $invoiceNo =
            'PH-'
            . date('ymd')
            . '-'
            . strtoupper(
                bin2hex(
                    random_bytes(4)
                )
            );


        $invoiceCheck =
            $conn->prepare("
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

        $invoiceResult =
            $invoiceCheck->get_result();

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
    | The production sales table contains:
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
    |   amount_received
    |   change_due
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
            amount_received,
            change_due,
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
            ?,
            ?,
            NOW(),
            NOW()
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | Preserve the existing payment column as the received amount.
    |--------------------------------------------------------------------------
    */

    $paymentValue =
        number_format(
            $amountReceived,
            2,
            '.',
            ''
        );


    $saleStmt->bind_param(
        'iissdsidddsdd',
        $pharmacyId,
        $branchId,
        $issuedBy,
        $invoiceNo,
        $saleTotal,
        $paymentValue,
        $userId,
        $saleTotal,
        $saleSubtotal,
        $saleVat,
        $paymentMethod,
        $amountReceived,
        $changeDue
    );


    $saleStmt->execute();

    $saleId =
        (int) $conn->insert_id;


    if ($saleId <= 0) {

        throw new RuntimeException(
            'Unable to create sale record.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Insert sale items
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

        $productId =
            $saleItem['product_id'];

        $quantity =
            $saleItem['quantity'];

        $unitPrice =
            $saleItem['unit_price'];


        /*
        |--------------------------------------------------------------------------
        | Deduct stock atomically.
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


        if (
            $stockStmt->affected_rows !== 1
        ) {

            throw new RuntimeException(
                'Stock changed while processing "' .
                $saleItem['item_name'] .
                '". Please retry the transaction.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Record sale item
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
    | Commit everything atomically
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    $transactionStarted = false;


    /*
    |--------------------------------------------------------------------------
    | Return authoritative sale result
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'status' =>
            'success',

        'sale_id' =>
            $saleId,

        'invoice' =>
            $invoiceNo,

        'subtotal' =>
            number_format(
                $saleSubtotal,
                2,
                '.',
                ''
            ),

        'vat' =>
            number_format(
                $saleVat,
                2,
                '.',
                ''
            ),

        'total' =>
            number_format(
                $saleTotal,
                2,
                '.',
                ''
            ),

        'payment_method' =>
            $paymentMethod,

        'amount_received' =>
            number_format(
                $amountReceived,
                2,
                '.',
                ''
            ),

        'change_due' =>
            number_format(
                $changeDue,
                2,
                '.',
                ''
            ),

        'items' =>
            array_map(
                static function (
                    array $item
                ): array {

                    return [
                        'id' =>
                            $item['product_id'],

                        'name' =>
                            $item['item_name'],

                        'quantity' =>
                            $item['quantity'],

                        'unit_price' =>
                            number_format(
                                $item['unit_price'],
                                2,
                                '.',
                                ''
                            ),

                        'line_total' =>
                            number_format(
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
    | Rollback on ANY failure
    |--------------------------------------------------------------------------
    */

    if ($transactionStarted) {

        try {

            $conn->rollback();

        } catch (Throwable $rollbackError) {

            error_log(
                'POS rollback failed: ' .
                $rollbackError->getMessage()
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Log technical error privately
    |--------------------------------------------------------------------------
    */

    error_log(
        'POS sale failed: ' .
        $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | Safe customer-facing error
    |--------------------------------------------------------------------------
    */

    $message =
        $e->getMessage();


    /*
    |--------------------------------------------------------------------------
    | Do not expose internal database errors.
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
        ||
        stripos($message, 'column') !== false
        ||
        stripos($message, 'constraint') !== false
    ) {

        $message =
            'The sale could not be completed because of a database error.';
    }


    http_response_code(400);

    echo json_encode([
        'status' =>
            'error',

        'message' =>
            $message,
    ]);
}


exit;
