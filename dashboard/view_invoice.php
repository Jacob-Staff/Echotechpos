<?php
/**
 * ============================================================
 * ECHOTECH POS
 * SECURE INVOICE VIEW / REPRINT
 * Phase 3G-1
 * ============================================================
 *
 * IMPORTANT:
 * - Uses the existing includes/head.php layout.
 * - Does NOT query branch columns that are not part of the
 *   current production branch contract.
 * - Restricts the sale to the logged-in pharmacy + branch.
 * - Uses the Phase 3 payment fields.
 * - Uses sales_items.pharmacy_id + branch_id, as created by
 *   the current production sale processor.
 * ============================================================
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

/*
|--------------------------------------------------------------------------
| Tenant / Branch Context
|--------------------------------------------------------------------------
*/

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    http_response_code(401);
    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#f8d7da;
                color:#842029;
                border:1px solid #f5c2c7;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Session Expired</h3>
                <p>Please log in again.</p>
            </div>
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| Invoice ID
|--------------------------------------------------------------------------
*/

$invoice_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$invoice_id || $invoice_id <= 0) {
    http_response_code(400);
    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#fff3cd;
                color:#664d03;
                border:1px solid #ffecb5;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Invalid Invoice</h3>
                <p>No valid invoice ID was supplied.</p>
                <a href='today_transactions.php'
                   style='
                       display:inline-block;
                       margin-top:10px;
                       padding:10px 18px;
                       background:#198754;
                       color:#fff;
                       text-decoration:none;
                       border-radius:6px;
                   '>
                    Back to Transactions
                </a>
            </div>
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| Output Escaping
|--------------------------------------------------------------------------
*/

function invoice_e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Load Sale
|--------------------------------------------------------------------------
|
| The current transaction page already establishes that sales are
| isolated by pharmacy_id + branch_id.
|
| We keep the same security boundary here.
|--------------------------------------------------------------------------
*/

$invoiceSql = "
    SELECT
        s.id,
        s.pharmacy_id,
        s.branch_id,
        s.invoice,
        s.created_at,

        s.subtotal,
        s.vat_amount,
        s.total,

        s.payment_method,
        s.amount_received,
        s.change_due,
        s.client_reference,

        s.user_id,
        s.issued_by,

        COALESCE(
            u.username,
            u.full_name,
            s.issued_by,
            'System'
        ) AS issuer,

        p.name AS pharmacy_name,
        b.branch_name

    FROM sales s

    INNER JOIN pharmacies p
        ON p.id = s.pharmacy_id

    INNER JOIN branches b
        ON b.id = s.branch_id
       AND b.pharmacy_id = s.pharmacy_id

    LEFT JOIN users u
        ON u.id = s.user_id

    WHERE s.id = ?
      AND s.pharmacy_id = ?
      AND s.branch_id = ?

    LIMIT 1
";

$invoiceStmt = mysqli_prepare(
    $conn,
    $invoiceSql
);

if (!$invoiceStmt) {
    error_log(
        "view_invoice.php invoice prepare failed: " .
        mysqli_error($conn)
    );

    http_response_code(500);

    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#f8d7da;
                color:#842029;
                border:1px solid #f5c2c7;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Unable to Load Invoice</h3>
                <p>The invoice query could not be prepared.</p>
            </div>
        </div>
    ");
}

mysqli_stmt_bind_param(
    $invoiceStmt,
    "iii",
    $invoice_id,
    $pharmacy_id,
    $branch_id
);

if (!mysqli_stmt_execute($invoiceStmt)) {

    error_log(
        "view_invoice.php invoice execute failed: " .
        mysqli_stmt_error($invoiceStmt)
    );

    mysqli_stmt_close($invoiceStmt);

    http_response_code(500);

    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#f8d7da;
                color:#842029;
                border:1px solid #f5c2c7;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Unable to Load Invoice</h3>
                <p>Please try again.</p>
            </div>
        </div>
    ");
}

$invoiceResult = mysqli_stmt_get_result(
    $invoiceStmt
);

$invoice = mysqli_fetch_assoc(
    $invoiceResult
);

mysqli_stmt_close(
    $invoiceStmt
);

if (!$invoice) {

    http_response_code(404);

    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#fff3cd;
                color:#664d03;
                border:1px solid #ffecb5;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Invoice Not Found</h3>
                <p>
                    The invoice does not exist or is not available
                    for this pharmacy and branch.
                </p>

                <a href='today_transactions.php'
                   style='
                       display:inline-block;
                       margin-top:10px;
                       padding:10px 18px;
                       background:#198754;
                       color:#fff;
                       text-decoration:none;
                       border-radius:6px;
                   '>
                    Back to Transactions
                </a>
            </div>
        </div>
    ");
}

/*
|--------------------------------------------------------------------------
| Load Sale Items
|--------------------------------------------------------------------------
|
| Current production sales_items records are tenant/branch scoped.
|--------------------------------------------------------------------------
*/

$itemsSql = "
    SELECT
        si.id,
        si.product_id,
        si.quantity,
        si.unit_price,
        st.item_name

    FROM sales_items si

    INNER JOIN store_items st
        ON st.id = si.product_id

    WHERE si.sale_id = ?
      AND si.pharmacy_id = ?
      AND si.branch_id = ?

    ORDER BY si.id ASC
";

$itemsStmt = mysqli_prepare(
    $conn,
    $itemsSql
);

if (!$itemsStmt) {

    error_log(
        "view_invoice.php items prepare failed: " .
        mysqli_error($conn)
    );

    http_response_code(500);

    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#f8d7da;
                color:#842029;
                border:1px solid #f5c2c7;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Unable to Load Invoice Items</h3>
                <p>The invoice items query could not be prepared.</p>
            </div>
        </div>
    ");
}

mysqli_stmt_bind_param(
    $itemsStmt,
    "iii",
    $invoice_id,
    $pharmacy_id,
    $branch_id
);

if (!mysqli_stmt_execute($itemsStmt)) {

    error_log(
        "view_invoice.php items execute failed: " .
        mysqli_stmt_error($itemsStmt)
    );

    mysqli_stmt_close($itemsStmt);

    http_response_code(500);

    die("
        <div style='
            max-width:600px;
            margin:80px auto;
            padding:30px;
            font-family:Arial,sans-serif;
            text-align:center;
        '>
            <div style='
                background:#f8d7da;
                color:#842029;
                border:1px solid #f5c2c7;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Unable to Load Invoice Items</h3>
                <p>Please try again.</p>
            </div>
        </div>
    ");
}

$itemsResult = mysqli_stmt_get_result(
    $itemsStmt
);

$items = [];

while ($row = mysqli_fetch_assoc($itemsResult)) {
    $items[] = $row;
}

mysqli_stmt_close(
    $itemsStmt
);

/*
|--------------------------------------------------------------------------
| Payment
|--------------------------------------------------------------------------
*/

$paymentMethod = trim(
    (string)($invoice['payment_method'] ?? '')
);

if ($paymentMethod === '') {
    $paymentMethod = 'Cash';
}

$paymentMethodLower = strtolower(
    $paymentMethod
);

switch ($paymentMethodLower) {

    case 'mobile':
    case 'momo':
    case 'mobile money':
        $paymentMethodDisplay = 'MOBILE MONEY';
        break;

    case 'card':
        $paymentMethodDisplay = 'CARD';
        break;

    case 'cash':
        $paymentMethodDisplay = 'CASH';
        break;

    default:
        $paymentMethodDisplay =
            strtoupper($paymentMethod);
        break;
}

/*
|--------------------------------------------------------------------------
| Financial Values
|--------------------------------------------------------------------------
*/

$subtotal = round(
    (float)($invoice['subtotal'] ?? 0),
    2
);

$vatAmount = round(
    (float)($invoice['vat_amount'] ?? 0),
    2
);

$total = round(
    (float)($invoice['total'] ?? 0),
    2
);

$amountReceived = round(
    (float)(
        $invoice['amount_received']
        ?? $total
    ),
    2
);

$changeDue = round(
    (float)(
        $invoice['change_due']
        ?? 0
    ),
    2
);

if ($amountReceived < 0) {
    $amountReceived = 0;
}

if ($changeDue < 0) {
    $changeDue = 0;
}

/*
|--------------------------------------------------------------------------
| Date
|--------------------------------------------------------------------------
*/

$createdAt = $invoice['created_at'] ?? '';

$displayDate = 'N/A';

if ($createdAt !== '') {

    $timestamp = strtotime(
        $createdAt
    );

    if ($timestamp !== false) {

        $displayDate = date(
            'd M Y, h:i A',
            $timestamp
        );
    }
}

/*
|--------------------------------------------------------------------------
| Existing application layout
|--------------------------------------------------------------------------
|
| IMPORTANT:
| head.php already outputs:
| <!DOCTYPE html>
| <html>
| <head>
| ...
| <body>
|
| Therefore we DO NOT create a second HTML document here.
|--------------------------------------------------------------------------
*/

require_once "../includes/head.php";

?>

<style>

/* =========================================================
   INVOICE PAGE
========================================================= */

.invoice-page {
    padding-bottom: 40px;
}

.invoice-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
}

.invoice-card {
    width: 100%;
    max-width: 900px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 5px 25px rgba(0,0,0,0.06);
    padding: 40px;
    border-top: 4px solid #198754;
}

.invoice-header {
    display: flex;
    justify-content: space-between;
    gap: 30px;
    margin-bottom: 28px;
}

.pharmacy-name {
    font-size: 24px;
    font-weight: 800;
    color: #212529;
    margin-bottom: 4px;
}

.invoice-heading {
    font-size: 28px;
    font-weight: 800;
    color: #6c757d;
    text-align: right;
}

.invoice-number {
    font-weight: 700;
    color: #212529;
    text-align: right;
}

.invoice-date {
    color: #6c757d;
    font-size: 13px;
    text-align: right;
}

.invoice-meta {
    display: grid;
    grid-template-columns:
        repeat(4, 1fr);
    gap: 12px;
    padding: 16px 0;
    margin-bottom: 25px;
    border-top: 1px solid #dee2e6;
    border-bottom: 1px solid #dee2e6;
}

.meta-label {
    display: block;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    color: #6c757d;
    margin-bottom: 4px;
}

.meta-value {
    font-size: 13px;
    font-weight: 600;
    color: #212529;
}

.payment-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    background: #e9f7ef;
    color: #198754;
    border: 1px solid #b7e4c7;
    font-size: 11px;
    font-weight: 800;
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 25px;
}

.invoice-table th {
    padding: 12px;
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-size: 11px;
    text-transform: uppercase;
    color: #495057;
}

.invoice-table td {
    padding: 13px 12px;
    border-bottom: 1px solid #eeeeee;
    font-size: 13px;
    color: #212529;
}

.invoice-table .item-name {
    font-weight: 700;
}

.totals-wrapper {
    display: flex;
    justify-content: flex-end;
}

.totals-box {
    width: 100%;
    max-width: 390px;
    background: #fafafa;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 18px;
}

.total-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 5px 0;
    font-size: 14px;
}

.total-row.grand {
    border-top: 2px solid #dee2e6;
    margin-top: 8px;
    padding-top: 12px;
    font-size: 18px;
    font-weight: 800;
}

.grand-amount {
    color: #198754;
}

.payment-details {
    border-top: 1px dashed #ced4da;
    margin-top: 12px;
    padding-top: 12px;
}

.payment-details-title {
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    color: #495057;
    margin-bottom: 8px;
}

.change-value {
    color: #198754;
    font-weight: 800;
}

.reference-box {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px dashed #ced4da;
}

.reference-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    color: #6c757d;
    margin-bottom: 4px;
}

.reference-value {
    font-family: monospace;
    font-size: 11px;
    color: #6c757d;
    word-break: break-all;
}

.invoice-footer {
    text-align: center;
    border-top: 1px solid #dee2e6;
    margin-top: 35px;
    padding-top: 22px;
    color: #6c757d;
    font-size: 12px;
}

@media (max-width: 768px) {

    .invoice-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .invoice-card {
        padding: 20px;
        border-radius: 8px;
    }

    .invoice-header {
        flex-direction: column;
        gap: 15px;
    }

    .invoice-heading,
    .invoice-number,
    .invoice-date {
        text-align: left;
    }

    .invoice-meta {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .invoice-table {
        min-width: 650px;
    }

    .totals-wrapper {
        justify-content: stretch;
    }

    .totals-box {
        max-width: none;
    }
}

@page {
    size: A4;
    margin: 10mm;
}

@media print {

    html,
    body {
        width: 100%;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
    }

    .no-print,
    .topbar,
    .left-sidebar,
    nav,
    footer {
        display: none !important;
    }

    #main-wrapper {
        width: 100% !important;
        min-height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .page-wrapper {
        width: 100% !important;
        min-height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .container-fluid {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .invoice-page {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .invoice-card {
        width: 100% !important;
        max-width: none !important;

        margin: 0 !important;
        padding: 0 !important;

        border: none !important;
        box-shadow: none !important;

        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .invoice-card::before {
        display: none !important;
    }

    .invoice-header {
        margin-bottom: 18px !important;
    }

    .invoice-meta {
        margin-bottom: 18px !important;
        padding: 10px 0 !important;
    }

    .invoice-table {
        margin-bottom: 15px !important;
    }

    .invoice-table th,
    .invoice-table td {
        padding: 8px !important;
    }

    .totals-box {
        padding: 12px !important;
    }

    .payment-details {
        margin-top: 8px !important;
        padding-top: 8px !important;
    }

    .reference-box {
        margin-top: 10px !important;
        padding-top: 8px !important;
    }

    .invoice-footer {
        margin-top: 18px !important;
        padding-top: 12px !important;

        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .invoice-footer small {
        display: none !important;
    }

    .invoice-footer div,
    .invoice-footer p {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    .table-responsive {
        overflow: visible !important;
    }

    .invoice-table {
        width: 100% !important;
    }
}

</style>

<div id="main-wrapper">

    <?php
    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }

    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }
    ?>

    <div class="page-wrapper invoice-page">

        <div class="container-fluid">

            <!-- =================================================
                 TOOLBAR
            ================================================== -->

            <div class="invoice-toolbar no-print">

                <a
                    href="today_transactions.php"
                    class="btn btn-outline-secondary"
                >
                    <i class="fas fa-arrow-left me-1"></i>
                    Back to Transactions
                </a>

                <button
                    type="button"
                    class="btn btn-success px-4"
                    onclick="window.print()"
                >
                    <i class="fas fa-print me-1"></i>
                    Print / Save PDF
                </button>

            </div>


            <!-- =================================================
                 INVOICE
            ================================================== -->

            <div class="invoice-card">

                <!-- HEADER -->

                <div class="invoice-header">

                    <div>

                        <div class="pharmacy-name">
                            <?= invoice_e(
                                strtoupper(
                                    $invoice['pharmacy_name']
                                )
                            ) ?>
                        </div>

                        <div class="text-muted small">
                            <?= invoice_e(
                                $invoice['branch_name']
                            ) ?>
                        </div>

                    </div>

                    <div>

                        <div class="invoice-heading">
                            INVOICE
                        </div>

                        <div class="invoice-number">
                            #<?= invoice_e(
                                $invoice['invoice']
                            ) ?>
                        </div>

                        <div class="invoice-date">
                            <?= invoice_e(
                                $displayDate
                            ) ?>
                        </div>

                    </div>

                </div>


                <!-- TRANSACTION META -->

                <div class="invoice-meta">

                    <div>

                        <span class="meta-label">
                            Branch
                        </span>

                        <span class="meta-value">
                            <?= invoice_e(
                                $invoice['branch_name']
                            ) ?>
                        </span>

                    </div>


                    <div>

                        <span class="meta-label">
                            Issued By
                        </span>

                        <span class="meta-value">
                            <?= invoice_e(
                                $invoice['issuer']
                                ?: 'System'
                            ) ?>
                        </span>

                    </div>


                    <div>

                        <span class="meta-label">
                            Payment
                        </span>

                        <span class="payment-badge">
                            <?= invoice_e(
                                $paymentMethodDisplay
                            ) ?>
                        </span>

                    </div>


                    <div>

                        <span class="meta-label">
                            Status
                        </span>

                        <span
                            class="
                                meta-value
                                text-success
                            "
                        >
                            PAID
                        </span>

                    </div>

                </div>


                <!-- ITEMS -->

                <div class="table-responsive">

                    <table class="invoice-table">

                        <thead>

                            <tr>

                                <th>
                                    Item
                                </th>

                                <th class="text-center">
                                    Qty
                                </th>

                                <th class="text-end">
                                    Unit Price
                                </th>

                                <th class="text-end">
                                    Total
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php if (!empty($items)): ?>

                            <?php foreach ($items as $item): ?>

                                <?php

                                $quantity = (int)(
                                    $item['quantity']
                                    ?? 0
                                );

                                $unitPrice = (float)(
                                    $item['unit_price']
                                    ?? 0
                                );

                                $lineTotal = round(
                                    $quantity *
                                    $unitPrice,
                                    2
                                );

                                ?>

                                <tr>

                                    <td class="item-name">
                                        <?= invoice_e(
                                            $item['item_name']
                                        ) ?>
                                    </td>

                                    <td class="text-center">
                                        <?= $quantity ?>
                                    </td>

                                    <td class="text-end">
                                        K<?= number_format(
                                            $unitPrice,
                                            2
                                        ) ?>
                                    </td>

                                    <td class="text-end fw-bold">
                                        K<?= number_format(
                                            $lineTotal,
                                            2
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>

                                <td
                                    colspan="4"
                                    class="text-center text-muted py-4"
                                >
                                    No items recorded.
                                </td>

                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>


                <!-- TOTALS -->

                <div class="totals-wrapper">

                    <div class="totals-box">

                        <div class="total-row">

                            <span class="text-muted">
                                Subtotal
                            </span>

                            <span>
                                K<?= number_format(
                                    $subtotal,
                                    2
                                ) ?>
                            </span>

                        </div>


                        <div class="total-row">

                            <span class="text-muted">
                                VAT (16%)
                            </span>

                            <span>
                                K<?= number_format(
                                    $vatAmount,
                                    2
                                ) ?>
                            </span>

                        </div>


                        <div class="total-row grand">

                            <span>
                                TOTAL
                            </span>

                            <span class="grand-amount">
                                K<?= number_format(
                                    $total,
                                    2
                                ) ?>
                            </span>

                        </div>


                        <!-- PAYMENT DETAILS -->

                        <div class="payment-details">

                            <div class="payment-details-title">
                                Payment Details
                            </div>

                            <div class="total-row">

                                <span class="text-muted">
                                    Method
                                </span>

                                <span class="fw-bold">
                                    <?= invoice_e(
                                        $paymentMethodDisplay
                                    ) ?>
                                </span>

                            </div>


                            <div class="total-row">

                                <span class="text-muted">
                                    Amount Received
                                </span>

                                <span class="fw-bold">
                                    K<?= number_format(
                                        $amountReceived,
                                        2
                                    ) ?>
                                </span>

                            </div>


                            <div class="total-row">

                                <span class="text-muted">
                                    Change
                                </span>

                                <span class="change-value">
                                    K<?= number_format(
                                        $changeDue,
                                        2
                                    ) ?>
                                </span>

                            </div>

                        </div>


                        <!-- CLIENT REFERENCE -->

                        <?php if (
                            !empty(
                                $invoice['client_reference']
                            )
                        ): ?>

                            <div class="reference-box">

                                <div class="reference-label">
                                    Transaction Reference
                                </div>

                                <div class="reference-value">
                                    <?= invoice_e(
                                        $invoice['client_reference']
                                    ) ?>
                                </div>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- FOOTER -->

                <div class="invoice-footer">

                    <div class="fw-bold text-dark mb-1">
                        Thank you for your business!
                    </div>

                    <div>
                        Medicines sold are non-refundable.
                    </div>

                    <div class="mt-2">
                        <?= invoice_e(
                            $invoice['pharmacy_name']
                        ) ?>
                        -
                        <?= invoice_e(
                            $invoice['branch_name']
                        ) ?>
                    </div>

                    <div class="mt-3">
                        <small>
                            Invoice generated by EchoTech POS
                        </small>
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<?php

if (file_exists("../includes/footer.php")) {
    require_once "../includes/footer.php";
}

?>

<script>
document.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key === 'Escape'
        ) {
            window.location.href =
                'today_transactions.php';
        }

        if (
            event.key === 'F4'
        ) {
            event.preventDefault();
            window.print();
        }

    }
);
</script>

</body>
</html>
