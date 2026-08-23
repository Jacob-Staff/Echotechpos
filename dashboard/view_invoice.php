<?php
/**
 * ============================================================
 * BIGE50 / PHARMACY POS
 * VIEW INVOICE
 * Phase 3G-1
 *
 * Features:
 * - Secure pharmacy + branch isolation
 * - Prepared statements
 * - New Phase 3 sales fields
 * - Payment method display
 * - Cash received / change
 * - Card / Mobile Money payment display
 * - Client transaction reference
 * - Printable invoice
 * ============================================================
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Authentication / Database
|--------------------------------------------------------------------------
*/

require_once "../includes/conn.php";
require_once "../includes/auth.php";

/*
|--------------------------------------------------------------------------
| Session Tenant Context
|--------------------------------------------------------------------------
*/

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    header("Location: ../login.php?error=session_expired");
    exit();
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
                       color:white;
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
| Helper
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Fetch Invoice
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The invoice is restricted by BOTH:
|
|   pharmacy_id
|   branch_id
|
| This prevents one tenant/branch from opening another branch's invoice.
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

        b.branch_name,

        b.address AS branch_address,
        b.location AS branch_location,
        b.phone AS branch_phone,
        b.contact AS branch_contact

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

$invoiceStmt = $conn->prepare($invoiceSql);

if (!$invoiceStmt) {
    error_log(
        "view_invoice.php prepare invoice failed: " .
        $conn->error
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
                <p>Please try again.</p>
            </div>
        </div>
    ");
}

$invoiceStmt->bind_param(
    "iii",
    $invoice_id,
    $pharmacy_id,
    $branch_id
);

$invoiceStmt->execute();

$invoiceResult = $invoiceStmt->get_result();

$invoice = $invoiceResult->fetch_assoc();

$invoiceStmt->close();

/*
|--------------------------------------------------------------------------
| Invoice Not Found / Access Denied
|--------------------------------------------------------------------------
*/

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
                background:#f8d7da;
                color:#842029;
                border:1px solid #f5c2c7;
                border-radius:10px;
                padding:25px;
            '>
                <h3>Invoice Not Found</h3>
                <p>
                    The invoice does not exist or you do not
                    have permission to view it.
                </p>

                <a href='today_transactions.php'
                   style='
                       display:inline-block;
                       margin-top:10px;
                       padding:10px 18px;
                       background:#198754;
                       color:white;
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
| Fetch Invoice Items
|--------------------------------------------------------------------------
|
| We verify BOTH pharmacy_id and branch_id.
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

$itemsStmt = $conn->prepare($itemsSql);

if (!$itemsStmt) {
    error_log(
        "view_invoice.php prepare items failed: " .
        $conn->error
    );

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

$itemsStmt->bind_param(
    "iii",
    $invoice_id,
    $pharmacy_id,
    $branch_id
);

$itemsStmt->execute();

$itemsResult = $itemsStmt->get_result();

$items = [];

while ($row = $itemsResult->fetch_assoc()) {
    $items[] = $row;
}

$itemsStmt->close();

/*
|--------------------------------------------------------------------------
| Payment Values
|--------------------------------------------------------------------------
*/

$paymentMethod = trim(
    (string)($invoice['payment_method'] ?? '')
);

if ($paymentMethod === '') {
    $paymentMethod = 'Cash';
}

/*
|--------------------------------------------------------------------------
| Normalize Payment Method For Display
|--------------------------------------------------------------------------
*/

$paymentMethodLower = strtolower(
    trim($paymentMethod)
);

if (
    $paymentMethodLower === 'mobile' ||
    $paymentMethodLower === 'momo'
) {
    $paymentMethodDisplay = 'MOBILE MONEY';
} elseif (
    $paymentMethodLower === 'mobile money'
) {
    $paymentMethodDisplay = 'MOBILE MONEY';
} elseif (
    $paymentMethodLower === 'card'
) {
    $paymentMethodDisplay = 'CARD';
} elseif (
    $paymentMethodLower === 'cash'
) {
    $paymentMethodDisplay = 'CASH';
} else {
    $paymentMethodDisplay = strtoupper(
        $paymentMethod
    );
}

/*
|--------------------------------------------------------------------------
| Financial Values
|--------------------------------------------------------------------------
*/

$subtotal = (float)(
    $invoice['subtotal'] ?? 0
);

$vatAmount = (float)(
    $invoice['vat_amount'] ?? 0
);

$total = (float)(
    $invoice['total'] ?? 0
);

$amountReceived = (float)(
    $invoice['amount_received'] ?? $total
);

$changeDue = (float)(
    $invoice['change_due'] ?? 0
);

/*
|--------------------------------------------------------------------------
| Protect Against Negative Display Values
|--------------------------------------------------------------------------
*/

if ($amountReceived < 0) {
    $amountReceived = 0;
}

if ($changeDue < 0) {
    $changeDue = 0;
}

/*
|--------------------------------------------------------------------------
| Branch Contact Information
|--------------------------------------------------------------------------
*/

$displayAddress = '';

if (
    !empty($invoice['branch_address'])
) {
    $displayAddress =
        $invoice['branch_address'];
} elseif (
    !empty($invoice['branch_location'])
) {
    $displayAddress =
        $invoice['branch_location'];
}

$displayPhone = '';

if (
    !empty($invoice['branch_phone'])
) {
    $displayPhone =
        $invoice['branch_phone'];
} elseif (
    !empty($invoice['branch_contact'])
) {
    $displayPhone =
        $invoice['branch_contact'];
}

/*
|--------------------------------------------------------------------------
| Date / Time
|--------------------------------------------------------------------------
*/

$createdAt = $invoice['created_at'] ?? '';

$displayDate = 'N/A';

if (!empty($createdAt)) {

    $timestamp = strtotime($createdAt);

    if ($timestamp !== false) {
        $displayDate =
            date(
                'd M Y, h:i A',
                $timestamp
            );
    }
}

/*
|--------------------------------------------------------------------------
| Load Common Head
|--------------------------------------------------------------------------
*/

require_once "../includes/head.php";

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Invoice #<?= e($invoice['invoice']) ?>
</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
    rel="stylesheet"
>

<link
    href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css"
    rel="stylesheet"
>

<style>

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

body {
    font-family: 'Poppins', sans-serif;
    background-color: #f4f6f9;
    color: #212529;
}

.page-wrapper {
    padding-top: 20px;
    padding-bottom: 40px;
}

/*
|--------------------------------------------------------------------------
| Invoice Card
|--------------------------------------------------------------------------
*/

.invoice-card {

    background: #ffffff;

    border-radius: 8px;

    box-shadow:
        0 0 20px rgba(0, 0, 0, 0.05);

    max-width: 850px;

    margin: 0 auto;

    padding: 40px;

    position: relative;

}

.invoice-card::before {

    content: "";

    position: absolute;

    top: 0;

    left: 0;

    right: 0;

    height: 4px;

    background: #22a7f0;

    border-radius:
        8px 8px 0 0;

}

/*
|--------------------------------------------------------------------------
| Invoice Header
|--------------------------------------------------------------------------
*/

.invoice-title {

    font-size: 2rem;

    letter-spacing: 1px;

    color: #6c757d;

}

/*
|--------------------------------------------------------------------------
| Items Table
|--------------------------------------------------------------------------
*/

.table-invoice {

    width: 100%;

}

.table-invoice thead th {

    background-color: #f8f9fa;

    font-size: 11px;

    text-transform: uppercase;

    padding: 12px;

    border-bottom:
        2px solid #dee2e6;

}

.table-invoice tbody td {

    padding: 12px;

    vertical-align: middle;

    border-bottom:
        1px solid #eeeeee;

}

/*
|--------------------------------------------------------------------------
| Payment Summary
|--------------------------------------------------------------------------
*/

.total-section {

    background: #fdfdfd;

    padding: 20px;

    border-radius: 6px;

    border:
        1px solid #eeeeee;

}

.payment-section {

    margin-top: 18px;

    padding-top: 15px;

    border-top:
        1px dashed #cccccc;

}

.payment-row {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-bottom: 8px;

}

.payment-row:last-child {

    margin-bottom: 0;

}

.payment-label {

    color: #6c757d;

}

.payment-value {

    font-weight: 600;

}

/*
|--------------------------------------------------------------------------
| Total
|--------------------------------------------------------------------------
*/

.grand-total {

    color: #198754;

    font-size: 1.35rem;

    font-weight: 700;

}

/*
|--------------------------------------------------------------------------
| Transaction Reference
|--------------------------------------------------------------------------
*/

.transaction-reference {

    font-family:
        monospace;

    font-size: 11px;

    color: #6c757d;

    word-break: break-all;

}

/*
|--------------------------------------------------------------------------
| Payment Badge
|--------------------------------------------------------------------------
*/

.payment-badge {

    display: inline-block;

    padding:
        6px 12px;

    border-radius: 20px;

    background: #f8f9fa;

    border:
        1px solid #dee2e6;

    font-size: 12px;

    font-weight: 700;

}

/*
|--------------------------------------------------------------------------
| Print
|--------------------------------------------------------------------------
*/

@media print {

    body {

        background:
            #ffffff !important;

    }

    .no-print {

        display:
            none !important;

    }

    .left-sidebar,
    .topbar,
    .header,
    #header,
    #aside,
    nav,
    footer {

        display:
            none !important;

    }

    .page-wrapper {

        margin: 0 !important;

        padding: 0 !important;

        width: 100% !important;

    }

    .invoice-card {

        box-shadow:
            none !important;

        border:
            none !important;

        padding:
            0 !important;

        margin:
            0 !important;

        max-width:
            100% !important;

    }

    .invoice-card::before {

        display:
            none !important;

    }

    .table-invoice {

        width: 100% !important;

    }

    .payment-section {

        page-break-inside:
            avoid;

    }

}

/*
|--------------------------------------------------------------------------
| Mobile
|--------------------------------------------------------------------------
*/

@media (max-width: 767px) {

    .invoice-card {

        padding:
            20px;

        border-radius:
            0;

    }

    .invoice-title {

        font-size:
            1.5rem;

    }

    .invoice-card
    .row {

        margin-left:
            0;

        margin-right:
            0;

    }

}

</style>

</head>

<body>

<div
    id="main-wrapper"
    data-layout="vertical"
    data-sidebartype="full"
>

<?php

if (
    file_exists("../includes/header.php")
) {
    require "../includes/header.php";
}

if (
    file_exists("../includes/aside.php")
) {
    require "../includes/aside.php";
}

?>

<div class="page-wrapper">

<div class="container-fluid">

<!-- ======================================================
     ACTION BAR
====================================================== -->

<div
    class="
        no-print
        d-flex
        justify-content-between
        align-items-center
        mb-4
    "
>

<a
    href="today_transactions.php"
    class="btn btn-outline-dark btn-sm"
>

<i class="mdi mdi-arrow-left"></i>

Back to Transactions

</a>

<button
    type="button"
    onclick="window.print()"
    class="
        btn
        btn-primary
        btn-sm
        px-4
        shadow-sm
    "
>

<i class="mdi mdi-printer me-1"></i>

Print / Save PDF

</button>

</div>


<!-- ======================================================
     INVOICE
====================================================== -->

<div class="invoice-card">

<!-- ====================================================
     HEADER
===================================================== -->

<div class="row mb-5">

<div class="col-md-7">

<h3
    class="
        fw-bold
        text-dark
        mb-1
    "
>

<?= e(
    strtoupper(
        $invoice['pharmacy_name']
    )
) ?>

</h3>

<?php if ($displayAddress !== ''): ?>

<p
    class="
        text-muted
        small
        mb-1
    "
>

<i class="mdi mdi-map-marker"></i>

<?= e($displayAddress) ?>

</p>

<?php endif; ?>


<?php if ($displayPhone !== ''): ?>

<p
    class="
        text-muted
        small
        mb-0
    "
>

<i class="mdi mdi-phone"></i>

<?= e($displayPhone) ?>

</p>

<?php endif; ?>

</div>


<div class="col-md-5 text-md-end mt-3 mt-md-0">

<div
    class="
        invoice-title
        fw-bold
        mb-0
    "
>

INVOICE

</div>

<div
    class="
        fw-bold
        text-dark
    "
>

#<?= e(
    $invoice['invoice']
) ?>

</div>

<small
    class="text-muted"
>

<?= e($displayDate) ?>

</small>

</div>

</div>


<!-- ====================================================
     TRANSACTION INFORMATION
===================================================== -->

<div
    class="
        row
        mb-4
        py-3
        border-top
        border-bottom
        g-3
    "
>

<div class="col-6 col-md-3">

<small
    class="
        text-muted
        text-uppercase
        d-block
        fw-bold
    "
>

Branch

</small>

<span>

<?= e(
    $invoice['branch_name']
) ?>

</span>

</div>


<div class="col-6 col-md-3">

<small
    class="
        text-muted
        text-uppercase
        d-block
        fw-bold
    "
>

Issued By

</small>

<span>

<?= e(
    $invoice['issuer']
        ?: 'Pharmacist'
) ?>

</span>

</div>


<div class="col-6 col-md-3">

<small
    class="
        text-muted
        text-uppercase
        d-block
        fw-bold
    "
>

Payment

</small>

<span
    class="payment-badge"
>

<?= e(
    $paymentMethodDisplay
) ?>

</span>

</div>


<div class="col-6 col-md-3 text-md-end">

<small
    class="
        text-muted
        text-uppercase
        d-block
        fw-bold
    "
>

Status

</small>

<span
    class="
        text-success
        fw-bold
    "
>

PAID

</span>

</div>

</div>


<!-- ====================================================
     CUSTOMER
===================================================== -->

<div class="mb-4">

<small
    class="
        text-muted
        text-uppercase
        d-block
        fw-bold
    "
>

Customer

</small>

<span>

Walk-in Client

</span>

</div>


<!-- ====================================================
     ITEMS
===================================================== -->

<div
    class="table-responsive"
>

<table
    class="
        table
        table-invoice
        mb-4
    "
>

<thead>

<tr>

<th>
    Item Name
</th>

<th
    class="text-center"
>

Qty

</th>

<th
    class="text-end"
>

Price

</th>

<th
    class="text-end"
>

Total

</th>

</tr>

</thead>

<tbody>

<?php if (!empty($items)): ?>

<?php foreach ($items as $item): ?>

<?php

$quantity = (int)(
    $item['quantity'] ?? 0
);

$unitPrice = (float)(
    $item['unit_price'] ?? 0
);

$lineTotal =
    $quantity * $unitPrice;

?>

<tr>

<td
    class="fw-bold"
>

<?= e(
    $item['item_name']
) ?>

</td>

<td
    class="text-center"
>

<?= $quantity ?>

</td>

<td
    class="text-end"
>

K<?= number_format(
    $unitPrice,
    2
) ?>

</td>

<td
    class="
        text-end
        fw-bold
    "
>

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
    class="
        text-center
        text-muted
        py-4
    "
>

No items recorded for this invoice.

</td>

</tr>

<?php endif; ?>

</tbody>

</table>

</div>


<!-- ====================================================
     TOTALS + PAYMENT
===================================================== -->

<div
    class="
        row
        justify-content-end
    "
>

<div class="col-12 col-md-6">

<div class="total-section">

<!-- Subtotal -->

<div
    class="
        d-flex
        justify-content-between
        mb-2
    "
>

<span
    class="text-muted"
>

Subtotal

</span>

<span>

K<?= number_format(
    $subtotal,
    2
) ?>

</span>

</div>


<!-- VAT -->

<div
    class="
        d-flex
        justify-content-between
        mb-3
    "
>

<span
    class="text-muted"
>

VAT (16%)

</span>

<span>

K<?= number_format(
    $vatAmount,
    2
) ?>

</span>

</div>


<!-- Total -->

<div
    class="
        d-flex
        justify-content-between
        fw-bold
        border-top
        pt-3
    "
>

<span>

TOTAL

</span>

<span
    class="grand-total"
>

K<?= number_format(
    $total,
    2
) ?>

</span>

</div>


<!-- ==================================================
     PAYMENT DETAILS
=================================================== -->

<div class="payment-section">

<div
    class="
        fw-bold
        text-dark
        mb-3
    "
>

Payment Details

</div>


<?php if (
    $paymentMethodLower === 'cash'
): ?>

<!-- Cash Received -->

<div
    class="payment-row"
>

<span
    class="payment-label"
>

Cash Received

</span>

<span
    class="payment-value"
>

K<?= number_format(
    $amountReceived,
    2
) ?>

</span>

</div>


<!-- Change -->

<div
    class="payment-row"
>

<span
    class="payment-label"
>

Change

</span>

<span
    class="
        payment-value
        text-success
    "
>

K<?= number_format(
    $changeDue,
    2
) ?>

</span>

</div>


<?php else: ?>

<!-- Card / Mobile Money -->

<div
    class="payment-row"
>

<span
    class="payment-label"
>

Amount Received

</span>

<span
    class="payment-value"
>

K<?= number_format(
    $amountReceived,
    2
) ?>

</span>

</div>


<div
    class="payment-row"
>

<span
    class="payment-label"
>

Change

</span>

<span
    class="payment-value"
>

K0.00

</span>

</div>

<?php endif; ?>

</div>


<!-- ==================================================
     TRANSACTION REFERENCE
=================================================== -->

<?php if (
    !empty(
        $invoice['client_reference']
    )
): ?>

<div
    class="
        mt-3
        pt-3
        border-top
    "
>

<div
    class="
        small
        text-muted
        mb-1
    "
>

Transaction Reference

</div>

<div
    class="transaction-reference"
>

<?= e(
    $invoice['client_reference']
) ?>

</div>

</div>

<?php endif; ?>

</div>

</div>

</div>


<!-- ====================================================
     FOOTER
===================================================== -->

<div
    class="
        mt-5
        pt-4
        text-center
        border-top
    "
>

<p
    class="
        mb-1
        fw-bold
    "
>

Thank you for choosing

<?= e(
    $invoice['pharmacy_name']
) ?>!

</p>


<p
    class="
        text-muted
        small
    "
>

Please retain this receipt for your records.

</p>


<div
    class="
        mt-3
        small
        text-muted
    "
>

Issuer:

<?= e(
    $invoice['issuer']
        ?: 'Pharmacist'
) ?>

&nbsp; | &nbsp;

Branch:

<?= e(
    $invoice['branch_name']
) ?>

</div>


<p
    class="
        mt-4
        text-muted
    "
    style="font-size:10px;"
>

NOTE: NO REFUNDS ON MEDICINES ONCE THEY LEAVE THE PREMISES.

</p>

</div>


</div>

</div>

</div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>
