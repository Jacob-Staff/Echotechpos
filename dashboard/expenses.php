<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

require_once '../includes/conn.php';
require_once '../includes/auth.php';

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    header('Location: ../login.php?error=session_expired');
    exit;
}


/* ============================================================
   PHARMACY / BRANCH INFORMATION
============================================================ */

$pharmacy_name = 'EchoTech POS';
$branch_name   = 'Main Branch';


$stmt = $conn->prepare(
    'SELECT name FROM pharmacies WHERE id=? LIMIT 1'
);

if ($stmt) {

    $stmt->bind_param('i', $pharmacy_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        if (!empty($row['name'])) {
            $pharmacy_name = $row['name'];
        }
    }

    $stmt->close();
}


$stmt = $conn->prepare(
    'SELECT branch_name FROM branches WHERE id=? LIMIT 1'
);

if ($stmt) {

    $stmt->bind_param('i', $branch_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        if (!empty($row['branch_name'])) {
            $branch_name = $row['branch_name'];
        }
    }

    $stmt->close();
}


/* ============================================================
   EXPENSE CATEGORIES
============================================================ */

$categories = [
    'General',
    'Utilities',
    'Staff Welfare',
    'Logistics',
    'Stock/Supplies',
    'Other'
];

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1"
    >

    <title>
        Expenses -
        <?= htmlspecialchars($pharmacy_name) ?>
    </title>


    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Existing POS stylesheet -->
    <link
        href="dist/css/style.min.css"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        rel="stylesheet"
    >


<style>

/* ============================================================
   EXPENSE PAGE
============================================================ */

:root {

    --p: #0d6efd;
    --d: #dc3545;
    --w: #ffc107;
    --b: #e2e8f0;
    --bg: #f4f6f9;

}

body {

    background: var(--bg);
    color: #1e293b;

}


.page-wrapper {

    padding: 1.25rem;

}


.ec {

    background: #ffffff;

    border: 1px solid var(--b);

    border-radius: 14px;

    box-shadow:
        0 2px 8px rgba(15, 23, 42, .05);

}


.ic {

    width: 44px;
    height: 44px;

    border-radius: 12px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    background: #eaf2ff;

    color: var(--p);

}


.sum {

    border: 0;

    border-radius: 14px;

    box-shadow:
        0 2px 8px rgba(15, 23, 42, .05);

}


.form-control,
.form-select {

    min-height: 44px;

    border-color: var(--b);

}


.form-control:focus,
.form-select:focus {

    border-color: #86b7fe;

    box-shadow:
        0 0 0 .2rem rgba(13, 110, 253, .12);

}


.expense-table thead th {

    background: #f8fafc;

    color: #334155;

    font-size: .78rem;

    text-transform: uppercase;

    letter-spacing: .4px;

    border-bottom: 2px solid var(--b);

}


.expense-table td {

    padding: 13px;

    border-color: #f1f5f9;

    vertical-align: middle;

}


.amt {

    font-weight: 800;

    color: var(--d);

    white-space: nowrap;

}


.cat {

    background: #f1f5f9;

    color: #334155;

    border: 1px solid var(--b);

}


.empty {

    padding: 50px;

    color: #64748b;

}


.filter {

    background: #f8fafc;

    border: 1px solid var(--b);

    border-radius: 12px;

}


/* ============================================================
   CUSTOM CONFIRMATION MODAL
============================================================ */

.expense-confirm-overlay {

    position: fixed;

    inset: 0;

    width: 100%;
    height: 100%;

    background:
        rgba(15, 23, 42, .58);

    backdrop-filter: blur(3px);

    -webkit-backdrop-filter: blur(3px);

    display: flex;

    align-items: center;
    justify-content: center;

    padding: 20px;

    z-index: 999999;

    opacity: 0;

    visibility: hidden;

    pointer-events: none;

    transition:
        opacity .2s ease,
        visibility .2s ease;

}


.expense-confirm-overlay.show {

    opacity: 1;

    visibility: visible;

    pointer-events: auto;

}


.expense-confirm-modal {

    width: 100%;

    max-width: 430px;

    background: #ffffff;

    border-radius: 16px;

    overflow: hidden;

    box-shadow:
        0 25px 70px rgba(0, 0, 0, .28);

    transform:
        translateY(18px)
        scale(.97);

    transition:
        transform .2s ease;

}


.expense-confirm-overlay.show
.expense-confirm-modal {

    transform:
        translateY(0)
        scale(1);

}


/* Modal Header */

.expense-confirm-header {

    padding: 20px 22px 14px;

    display: flex;

    align-items: center;

    gap: 14px;

}


.expense-confirm-icon {

    width: 48px;

    height: 48px;

    flex: 0 0 48px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #fff1f2;

    color: #dc3545;

    font-size: 19px;

}


.expense-confirm-title {

    margin: 0;

    color: #172033;

    font-size: 1.05rem;

    font-weight: 800;

}


.expense-confirm-subtitle {

    margin-top: 3px;

    color: #64748b;

    font-size: .78rem;

}


/* Modal Body */

.expense-confirm-body {

    padding:
        5px 22px 20px;

}


.expense-confirm-message {

    margin: 0;

    color: #475569;

    font-size: .88rem;

    line-height: 1.55;

}


.expense-confirm-warning {

    margin-top: 14px;

    padding: 11px 13px;

    background: #fff7ed;

    border: 1px solid #fed7aa;

    border-radius: 8px;

    color: #9a3412;

    font-size: .76rem;

    line-height: 1.45;

}


/* Modal Footer */

.expense-confirm-footer {

    padding:
        15px 22px 20px;

    display: flex;

    justify-content: flex-end;

    gap: 10px;

    border-top: 1px solid #f1f5f9;

}


.expense-confirm-footer button {

    min-height: 42px;

    border-radius: 7px;

    padding:
        0 18px;

    font-size: .82rem;

    font-weight: 700;

    transition:
        all .15s ease;

}


.expense-confirm-cancel {

    background: #ffffff;

    border: 1px solid #cbd5e1;

    color: #475569;

}


.expense-confirm-cancel:hover {

    background: #f8fafc;

    border-color: #94a3b8;

}


.expense-confirm-delete {

    background: #dc3545;

    border: 1px solid #dc3545;

    color: #ffffff;

}


.expense-confirm-delete:hover {

    background: #bb2d3b;

    border-color: #bb2d3b;

}


.expense-confirm-delete:disabled,
.expense-confirm-cancel:disabled {

    opacity: .65;

    cursor: not-allowed;

}


/* ============================================================
   MOBILE
============================================================ */

@media (max-width: 768px) {

    .page-wrapper {

        padding: .75rem;

    }

    .expense-table {

        min-width: 720px;

    }

}


@media (max-width: 480px) {

    .expense-confirm-modal {

        max-width: 100%;

        border-radius: 14px;

    }


    .expense-confirm-footer {

        flex-direction: column-reverse;

    }


    .expense-confirm-footer button {

        width: 100%;

    }

}


/* ============================================================
   PRINT
============================================================ */

@media print {

    .no-print,
    #header,
    #aside,
    nav,
    footer {

        display: none !important;

    }


    .page-wrapper {

        padding: 0 !important;

    }


    body {

        background: #ffffff !important;

    }


    .ec {

        box-shadow: none !important;

    }

}

</style>

</head>


<body>


<div id="main-wrapper">


    <?php

    /*
     * KEEP THE EXISTING POS HEADER / ASIDE.
     * Do not duplicate them here.
     */

    if (
        file_exists('../includes/header.php')
    ) {

        require '../includes/header.php';

    }


    if (
        file_exists('../includes/aside.php')
    ) {

        require '../includes/aside.php';

    }

    ?>


    <div class="page-wrapper">

        <div class="container-fluid">


            <!-- =================================================
                 PAGE HEADER
            ================================================== -->

            <div
                class="d-flex
                       flex-column
                       flex-md-row
                       justify-content-between
                       align-items-md-center
                       gap-3
                       mb-4"
            >

                <div
                    class="d-flex
                           align-items-center
                           gap-3"
                >

                    <div class="ic">

                        <i class="fas fa-receipt"></i>

                    </div>


                    <div>

                        <h3
                            class="fw-bold mb-1"
                        >
                            Expenses
                        </h3>


                        <div
                            class="small text-muted"
                        >

                            <?= htmlspecialchars($pharmacy_name) ?>

                            <span class="mx-1">
                                •
                            </span>

                            <?= htmlspecialchars($branch_name) ?>

                        </div>

                    </div>

                </div>


                <button
                    class="btn btn-outline-dark fw-bold no-print"
                    type="button"
                    onclick="window.print()"
                >

                    <i class="fas fa-print me-1"></i>

                    Print

                </button>

            </div>


            <!-- =================================================
                 SUMMARY CARDS
            ================================================== -->

            <div
                class="row g-3 mb-4"
            >


                <!-- TOTAL EXPENSES -->

                <div class="col-md-4">

                    <div class="card sum h-100">

                        <div
                            class="card-body
                                   d-flex
                                   justify-content-between
                                   align-items-center"
                        >

                            <div>

                                <div
                                    class="small
                                           text-muted
                                           fw-bold
                                           text-uppercase"
                                >

                                    Total Expenses

                                </div>


                                <div
                                    id="total_display"
                                    class="fs-3
                                           fw-bold
                                           text-danger"
                                >

                                    K0.00

                                </div>

                            </div>


                            <i
                                class="fas
                                       fa-money-bill-wave
                                       fa-lg
                                       text-danger"
                            ></i>

                        </div>

                    </div>

                </div>


                <!-- THIS MONTH -->

                <div class="col-md-4">

                    <div class="card sum h-100">

                        <div
                            class="card-body
                                   d-flex
                                   justify-content-between
                                   align-items-center"
                        >

                            <div>

                                <div
                                    class="small
                                           text-muted
                                           fw-bold
                                           text-uppercase"
                                >

                                    This Month

                                </div>


                                <div
                                    id="month_display"
                                    class="fs-3
                                           fw-bold
                                           text-warning"
                                >

                                    K0.00

                                </div>

                            </div>


                            <i
                                class="fas
                                       fa-calendar-alt
                                       fa-lg
                                       text-warning"
                            ></i>

                        </div>

                    </div>

                </div>


                <!-- RECORD COUNT -->

                <div class="col-md-4">

                    <div class="card sum h-100">

                        <div
                            class="card-body
                                   d-flex
                                   justify-content-between
                                   align-items-center"
                        >

                            <div>

                                <div
                                    class="small
                                           text-muted
                                           fw-bold
                                           text-uppercase"
                                >

                                    Records

                                </div>


                                <div
                                    id="count_display"
                                    class="fs-3
                                           fw-bold
                                           text-primary"
                                >

                                    0

                                </div>

                            </div>


                            <i
                                class="fas
                                       fa-list-ol
                                       fa-lg
                                       text-primary"
                            ></i>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 MAIN CONTENT
            ================================================== -->

            <div class="row g-4">


                <!-- =================================================
                     RECORD EXPENSE
                ================================================== -->

                <div class="col-lg-4">

                    <div class="card ec">

                        <div
                            class="card-header
                                   bg-white
                                   p-3"
                        >

                            <h5
                                class="fw-bold
                                       mb-1"
                            >

                                <i
                                    class="fas
                                           fa-plus-circle
                                           text-primary
                                           me-2"
                                ></i>

                                Record Expense

                            </h5>


                            <small class="text-muted">

                                Record a business expense
                                for this branch.

                            </small>

                        </div>


                        <div
                            class="card-body
                                   p-3
                                   p-lg-4"
                        >

                            <form
                                id="expenseForm"
                                autocomplete="off"
                            >


                                <!-- DESCRIPTION -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                    >

                                        Description

                                    </label>


                                    <input
                                        name="name"
                                        id="expenseName"
                                        class="form-control"
                                        maxlength="150"
                                        placeholder="e.g. Electricity bill"
                                        required
                                    >

                                </div>


                                <!-- AMOUNT -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                    >

                                        Amount (K)

                                    </label>


                                    <div class="input-group">

                                        <span
                                            class="input-group-text
                                                   fw-bold"
                                        >

                                            K

                                        </span>


                                        <input
                                            type="number"
                                            name="amount"
                                            id="expenseAmount"
                                            class="form-control"
                                            min=".01"
                                            step=".01"
                                            required
                                        >

                                    </div>

                                </div>


                                <!-- CATEGORY -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                    >

                                        Category

                                    </label>


                                    <select
                                        name="category"
                                        id="expenseCategory"
                                        class="form-select"
                                    >

                                        <?php foreach (
                                            $categories as $c
                                        ): ?>

                                            <option
                                                value="<?= htmlspecialchars($c) ?>"
                                            >

                                                <?= htmlspecialchars($c) ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <!-- DATE -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                    >

                                        Expense Date

                                    </label>


                                    <input
                                        type="date"
                                        name="date"
                                        id="expenseDate"
                                        class="form-control"
                                        value="<?= date('Y-m-d') ?>"
                                        required
                                    >

                                </div>


                                <!-- SAVE -->

                                <button
                                    id="submitBtn"
                                    type="submit"
                                    class="btn
                                           btn-primary
                                           w-100
                                           fw-bold
                                           py-2"
                                >

                                    <i
                                        class="fas
                                               fa-save
                                               me-1"
                                    ></i>

                                    SAVE EXPENSE

                                </button>

                            </form>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     EXPENSE HISTORY
                ================================================== -->

                <div class="col-lg-8">

                    <div class="card ec">


                        <!-- CARD HEADER -->

                        <div
                            class="card-header
                                   bg-white
                                   p-3"
                        >

                            <div
                                class="d-flex
                                       flex-column
                                       flex-md-row
                                       justify-content-between
                                       gap-3"
                            >

                                <div>

                                    <h5
                                        class="fw-bold
                                               mb-1"
                                    >

                                        <i
                                            class="fas
                                                   fa-history
                                                   text-primary
                                                   me-2"
                                        ></i>

                                        Expense History

                                    </h5>


                                    <small
                                        class="text-muted"
                                    >

                                        Branch expense records

                                    </small>

                                </div>


                                <!-- CLEAR MENU -->

                                <div
                                    class="dropdown no-print"
                                >

                                    <button
                                        type="button"
                                        class="btn
                                               btn-sm
                                               btn-outline-danger
                                               dropdown-toggle
                                               fw-bold"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >

                                        <i
                                            class="fas
                                                   fa-trash-alt
                                                   me-1"
                                        ></i>

                                        CLEAR RECORDS

                                    </button>


                                    <ul
                                        class="dropdown-menu
                                               dropdown-menu-end"
                                    >

                                        <li>

                                            <a
                                                class="dropdown-item
                                                       clear-btn"
                                                href="#"
                                                data-type="month"
                                            >

                                                <i
                                                    class="fas
                                                           fa-calendar-alt
                                                           me-2
                                                           text-warning"
                                                ></i>

                                                Clear This Month

                                            </a>

                                        </li>


                                        <li>

                                            <a
                                                class="dropdown-item
                                                       clear-btn
                                                       text-danger"
                                                href="#"
                                                data-type="year"
                                            >

                                                <i
                                                    class="fas
                                                           fa-calendar-times
                                                           me-2"
                                                ></i>

                                                Clear This Year

                                            </a>

                                        </li>

                                    </ul>

                                </div>

                            </div>

                        </div>


                        <!-- CARD BODY -->

                        <div
                            class="card-body p-3"
                        >


                            <!-- FILTERS -->

                            <div
                                class="filter
                                       p-3
                                       mb-3
                                       no-print"
                            >

                                <div class="row g-2">


                                    <!-- SEARCH -->

                                    <div
                                        class="col-md-5"
                                    >

                                        <label
                                            class="small
                                                   fw-semibold"
                                        >

                                            Search

                                        </label>


                                        <input
                                            id="expenseSearch"
                                            class="form-control"
                                            placeholder="Description..."
                                        >

                                    </div>


                                    <!-- CATEGORY -->

                                    <div
                                        class="col-md-3"
                                    >

                                        <label
                                            class="small
                                                   fw-semibold"
                                        >

                                            Category

                                        </label>


                                        <select
                                            id="filterCategory"
                                            class="form-select"
                                        >

                                            <option value="">

                                                All Categories

                                            </option>


                                            <?php foreach (
                                                $categories as $c
                                            ): ?>

                                                <option
                                                    value="<?= htmlspecialchars($c) ?>"
                                                >

                                                    <?= htmlspecialchars($c) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <!-- FROM -->

                                    <div
                                        class="col-md-2"
                                    >

                                        <label
                                            class="small
                                                   fw-semibold"
                                        >

                                            From

                                        </label>


                                        <input
                                            type="date"
                                            id="filterStart"
                                            class="form-control"
                                        >

                                    </div>


                                    <!-- TO -->

                                    <div
                                        class="col-md-2"
                                    >

                                        <label
                                            class="small
                                                   fw-semibold"
                                        >

                                            To

                                        </label>


                                        <input
                                            type="date"
                                            id="filterEnd"
                                            class="form-control"
                                        >

                                    </div>

                                </div>

                            </div>


                            <!-- TABLE -->

                            <div
                                class="table-responsive"
                            >

                                <table
                                    class="table
                                           table-hover
                                           expense-table
                                           mb-0"
                                >

                                    <thead>

                                        <tr>

                                            <th>
                                                Description
                                            </th>

                                            <th>
                                                Category
                                            </th>

                                            <th>
                                                Date
                                            </th>

                                            <th
                                                class="text-end"
                                            >
                                                Amount
                                            </th>

                                            <th
                                                class="text-center
                                                       no-print"
                                            >
                                                Action
                                            </th>

                                        </tr>

                                    </thead>


                                    <tbody
                                        id="expenses_list"
                                    >

                                        <tr>

                                            <td
                                                colspan="5"
                                                class="text-center
                                                       py-4"
                                            >

                                                Loading...

                                            </td>

                                        </tr>

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ========================================================
         CUSTOM CONFIRMATION MODAL
    ========================================================= -->

    <div
        id="expenseConfirmOverlay"
        class="expense-confirm-overlay"
        aria-hidden="true"
    >

        <div
            class="expense-confirm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="expenseConfirmTitle"
        >


            <!-- HEADER -->

            <div
                class="expense-confirm-header"
            >

                <div
                    id="expenseConfirmIcon"
                    class="expense-confirm-icon"
                >

                    <i
                        class="fas
                               fa-trash-alt"
                    ></i>

                </div>


                <div>

                    <h5
                        id="expenseConfirmTitle"
                        class="expense-confirm-title"
                    >

                        Delete Expense?

                    </h5>


                    <div
                        id="expenseConfirmSubtitle"
                        class="expense-confirm-subtitle"
                    >

                        This action requires confirmation.

                    </div>

                </div>

            </div>


            <!-- BODY -->

            <div
                class="expense-confirm-body"
            >

                <p
                    id="expenseConfirmMessage"
                    class="expense-confirm-message"
                >

                    Are you sure you want to continue?

                </p>


                <div
                    id="expenseConfirmWarning"
                    class="expense-confirm-warning"
                >

                    <i
                        class="fas
                               fa-exclamation-triangle
                               me-1"
                    ></i>

                    This action cannot be undone.

                </div>

            </div>


            <!-- FOOTER -->

            <div
                class="expense-confirm-footer"
            >

                <button
                    type="button"
                    id="expenseConfirmCancel"
                    class="expense-confirm-cancel"
                >

                    CANCEL

                </button>


                <button
                    type="button"
                    id="expenseConfirmAction"
                    class="expense-confirm-delete"
                >

                    <i
                        class="fas
                               fa-trash-alt
                               me-1"
                    ></i>

                    DELETE EXPENSE

                </button>

            </div>

        </div>

    </div>


</div>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script
    src="https://code.jquery.com/jquery-3.7.1.min.js"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

(() => {

    'use strict';


    /* ========================================================
       DATA
    ======================================================== */

    let all = [];


    /* ========================================================
       DATE HELPERS
    ======================================================== */

    const localDate = (d) => {

        const y =
            d.getFullYear();

        const m =
            String(
                d.getMonth() + 1
            ).padStart(2, '0');

        const x =
            String(
                d.getDate()
            ).padStart(2, '0');

        return `${y}-${m}-${x}`;

    };


    const today = () =>
        localDate(new Date());


    /* ========================================================
       HTML ESCAPE
    ======================================================== */

    const esc = (v) => {

        return String(v ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    };


    /* ========================================================
       MONEY FORMAT
    ======================================================== */

    const money = (v) => {

        return Number(v || 0)
            .toLocaleString(
                undefined,
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );

    };


    /* ========================================================
       LOAD EXPENSES
    ======================================================== */

    function load() {

        $('#expenses_list').html(`

            <tr>

                <td
                    colspan="5"
                    class="text-center py-4"
                >

                    <i
                        class="fas
                               fa-spinner
                               fa-spin
                               text-primary
                               me-2"
                    ></i>

                    Loading expenses...

                </td>

            </tr>

        `);


        $.getJSON(
            'actions/expenses.php',
            {
                action: 'list'
            }
        )

        .done((r) => {

            if (
                r.status !==
                'success'
            ) {

                $('#expenses_list').html(`

                    <tr>

                        <td
                            colspan="5"
                            class="text-center
                                   text-danger
                                   py-4"
                        >

                            <i
                                class="fas
                                       fa-exclamation-triangle
                                       me-1"
                            ></i>

                            ${
                                esc(
                                    r.message ||
                                    'Unable to load expenses'
                                )
                            }

                        </td>

                    </tr>

                `);

                return;
            }


            all =
                Array.isArray(
                    r.expenses
                )
                    ? r.expenses
                    : [];


            render();


            $('#month_display')
                .text(
                    'K' +
                    money(
                        r.month_total
                    )
                );

        })

        .fail(() => {

            $('#expenses_list').html(`

                <tr>

                    <td
                        colspan="5"
                        class="text-center
                               text-danger
                               py-4"
                    >

                        <i
                            class="fas
                                   fa-exclamation-circle
                                   me-1"
                        ></i>

                        Unable to load expenses.
                        Please refresh the page.

                    </td>

                </tr>

            `);

        });

    }


    /* ========================================================
       FILTER
    ======================================================== */

    function filtered() {

        const q =
            (
                $('#expenseSearch')
                    .val() ||
                ''
            )
            .trim()
            .toLowerCase();


        const c =
            $('#filterCategory')
                .val();


        const a =
            $('#filterStart')
                .val();


        const b =
            $('#filterEnd')
                .val();


        return all.filter(
            (e) => {

                return (

                    (
                        !q ||
                        String(
                            e.name || ''
                        )
                        .toLowerCase()
                        .includes(q)
                    )

                    &&

                    (
                        !c ||
                        e.category === c
                    )

                    &&

                    (
                        !a ||
                        e.expense_date >= a
                    )

                    &&

                    (
                        !b ||
                        e.expense_date <= b
                    )

                );

            }
        );

    }


    /* ========================================================
       RENDER TABLE
    ======================================================== */

    function render() {

        const rows =
            filtered();


        let total = 0;

        let html = '';


        rows.forEach(
            (e) => {

                const n =
                    Number(
                        e.amount || 0
                    );


                total += n;


                html += `

                    <tr>

                        <td class="fw-bold">

                            ${esc(e.name)}

                        </td>


                        <td>

                            <span
                                class="badge cat"
                            >

                                ${
                                    esc(
                                        e.category ||
                                        'General'
                                    )
                                }

                            </span>

                        </td>


                        <td
                            class="text-muted"
                        >

                            ${
                                esc(
                                    e.expense_date
                                )
                            }

                        </td>


                        <td
                            class="text-end amt"
                        >

                            K${money(n)}

                        </td>


                        <td
                            class="text-center
                                   no-print"
                        >

                            <button
                                type="button"
                                class="btn
                                       btn-sm
                                       btn-outline-danger
                                       delete-expense"
                                data-id="${Number(e.id)}"
                                title="Delete Expense"
                            >

                                <i
                                    class="fas
                                           fa-trash-alt"
                                ></i>

                            </button>

                        </td>

                    </tr>

                `;

            }
        );


        if (!html) {

            html = `

                <tr>

                    <td
                        colspan="5"
                        class="text-center empty"
                    >

                        <i
                            class="fas
                                   fa-receipt
                                   fa-2x
                                   mb-3
                                   d-block"
                        ></i>

                        No expenses found.

                    </td>

                </tr>

            `;

        }


        $('#expenses_list')
            .html(html);


        $('#total_display')
            .text(
                'K' +
                money(total)
            );


        $('#count_display')
            .text(
                rows.length
            );

    }


    /* ========================================================
       SAVE EXPENSE
    ======================================================== */

    $('#expenseForm').on(
        'submit',
        function (e) {

            e.preventDefault();


            const form =
                this;


            const button =
                $('#submitBtn');


            const name =
                $('[name=name]')
                    .val()
                    .trim();


            const amount =
                Number(
                    $('[name=amount]')
                        .val()
                );


            const date =
                $('[name=date]')
                    .val();


            if (
                !name ||
                !Number.isFinite(amount) ||
                amount <= 0 ||
                !date
            ) {

                alert(
                    'Please enter a valid description, amount and date.'
                );

                return;

            }


            button
                .prop(
                    'disabled',
                    true
                )
                .html(`

                    <i
                        class="fas
                               fa-spinner
                               fa-spin
                               me-1"
                    ></i>

                    SAVING...

                `);


            $.post(
                'actions/expenses.php',

                $(form).serialize()
                +
                '&action=add',

                null,

                'json'
            )

            .done(
                (r) => {

                    if (
                        r.status ===
                        'success'
                    ) {

                        form.reset();


                        $('#expenseDate')
                            .val(
                                today()
                            );


                        load();

                    } else {

                        alert(
                            r.message ||
                            'Unable to save expense'
                        );

                    }

                }
            )

            .fail(
                () => {

                    alert(
                        'Server error while saving expense.'
                    );

                }
            )

            .always(
                () => {

                    button
                        .prop(
                            'disabled',
                            false
                        )
                        .html(`

                            <i
                                class="fas
                                       fa-save
                                       me-1"
                            ></i>

                            SAVE EXPENSE

                        `);

                }
            );

        }
    );


    /* ========================================================
       CUSTOM CONFIRMATION MODAL ELEMENTS
    ======================================================== */

    const confirmOverlay =
        document.getElementById(
            'expenseConfirmOverlay'
        );


    const confirmTitle =
        document.getElementById(
            'expenseConfirmTitle'
        );


    const confirmSubtitle =
        document.getElementById(
            'expenseConfirmSubtitle'
        );


    const confirmMessage =
        document.getElementById(
            'expenseConfirmMessage'
        );


    const confirmWarning =
        document.getElementById(
            'expenseConfirmWarning'
        );


    const confirmIcon =
        document.getElementById(
            'expenseConfirmIcon'
        );


    const confirmAction =
        document.getElementById(
            'expenseConfirmAction'
        );


    const confirmCancel =
        document.getElementById(
            'expenseConfirmCancel'
        );


    let pendingConfirmAction =
        null;


    /* ========================================================
       OPEN MODAL
    ======================================================== */

    function openExpenseConfirm(
        options
    ) {

        confirmTitle.textContent =
            options.title ||
            'Confirm Action';


        confirmSubtitle.textContent =
            options.subtitle ||
            'Please confirm this action.';


        confirmMessage.innerHTML =
            options.message ||
            'Are you sure you want to continue?';


        confirmWarning.innerHTML =
            options.warning ||
            `

                <i
                    class="fas
                           fa-exclamation-triangle
                           me-1"
                ></i>

                This action cannot be undone.

            `;


        confirmAction.innerHTML =
            options.button ||
            `

                <i
                    class="fas
                           fa-trash-alt
                           me-1"
                ></i>

                CONFIRM

            `;


        confirmIcon.innerHTML =
            options.icon ||
            `

                <i
                    class="fas
                           fa-trash-alt"
                ></i>

            `;


        pendingConfirmAction =
            options.onConfirm ||
            null;


        confirmAction.disabled =
            false;


        confirmCancel.disabled =
            false;


        confirmOverlay.classList.add(
            'show'
        );


        confirmOverlay.setAttribute(
            'aria-hidden',
            'false'
        );


        document.body.style.overflow =
            'hidden';


        setTimeout(
            () => {

                confirmAction.focus();

            },
            50
        );

    }


    /* ========================================================
       CLOSE MODAL
    ======================================================== */

    function closeExpenseConfirm() {

        confirmOverlay.classList.remove(
            'show'
        );


        confirmOverlay.setAttribute(
            'aria-hidden',
            'true'
        );


        pendingConfirmAction =
            null;


        document.body.style.overflow =
            '';

    }


    /* ========================================================
       CANCEL
    ======================================================== */

    confirmCancel.addEventListener(
        'click',
        () => {

            closeExpenseConfirm();

        }
    );


    /* ========================================================
       CLICK OUTSIDE
    ======================================================== */

    confirmOverlay.addEventListener(
        'click',
        (event) => {

            if (
                event.target ===
                confirmOverlay
            ) {

                closeExpenseConfirm();

            }

        }
    );


    /* ========================================================
       ESCAPE KEY
    ======================================================== */

    document.addEventListener(
        'keydown',
        (event) => {

            if (
                event.key ===
                'Escape'
                &&
                confirmOverlay.classList.contains(
                    'show'
                )
            ) {

                closeExpenseConfirm();

            }

        }
    );


    /* ========================================================
       CONFIRM ACTION
    ======================================================== */

    confirmAction.addEventListener(
        'click',
        async () => {

            if (
                typeof pendingConfirmAction !==
                'function'
            ) {

                closeExpenseConfirm();

                return;

            }


            const action =
                pendingConfirmAction;


            confirmAction.disabled =
                true;


            confirmCancel.disabled =
                true;


            confirmAction.innerHTML = `

                <i
                    class="fas
                           fa-spinner
                           fa-spin
                           me-1"
                ></i>

                PROCESSING...

            `;


            try {

                await action();

            } catch (error) {

                console.error(
                    'Expense action error:',
                    error
                );

            } finally {

                confirmAction.disabled =
                    false;


                confirmCancel.disabled =
                    false;


                closeExpenseConfirm();

            }

        }
    );


    /* ========================================================
       DELETE EXPENSE
    ======================================================== */

    $(document).on(
        'click',
        '.delete-expense',
        function () {

            const id =
                Number(
                    $(this).data('id')
                );


            if (!id) {
                return;
            }


            openExpenseConfirm({

                title:
                    'Delete Expense?',

                subtitle:
                    'Remove this expense record',

                icon:
                    `

                        <i
                            class="fas
                                   fa-trash-alt"
                        ></i>

                    `,

                message:
                    `

                        Are you sure you want to
                        permanently delete this
                        expense record?

                    `,

                warning:
                    `

                        <i
                            class="fas
                                   fa-exclamation-triangle
                                   me-1"
                        ></i>

                        The expense will be removed
                        from this branch and this
                        action cannot be undone.

                    `,

                button:
                    `

                        <i
                            class="fas
                                   fa-trash-alt
                                   me-1"
                        ></i>

                        DELETE EXPENSE

                    `,

                onConfirm:
                    async function () {

                        try {

                            const response =
                                await fetch(
                                    'actions/expenses.php',
                                    {
                                        method:
                                            'POST',

                                        credentials:
                                            'same-origin',

                                        headers: {
                                            'Accept':
                                                'application/json',
                                            'Content-Type':
                                                'application/x-www-form-urlencoded; charset=UTF-8'
                                        },

                                        body:
                                            new URLSearchParams(
                                                {
                                                    action:
                                                        'delete',

                                                    id:
                                                        String(
                                                            id
                                                        )
                                                }
                                            )
                                    }
                                );


                            const data =
                                await response.json();


                            if (
                                data.status !==
                                'success'
                            ) {

                                throw new Error(
                                    data.message ||
                                    'Unable to delete expense.'
                                );

                            }


                            load();

                        } catch (error) {

                            console.error(
                                'Delete expense error:',
                                error
                            );


                            alert(
                                error.message ||
                                'Unable to delete expense.'
                            );

                        }

                    }

            );

        }
    );


    /* ========================================================
       CLEAR MONTH / YEAR
    ======================================================== */

    $(document).on(
        'click',
        '.clear-btn',
        function (e) {

            e.preventDefault();


            const type =
                $(this).data('type');


            const isMonth =
                type === 'month';


            const action =
                isMonth
                    ? 'clear_month'
                    : 'clear_year';


            const period =
                isMonth
                    ? 'this month'
                    : 'this year';


            const title =
                isMonth
                    ? 'Clear This Month?'
                    : 'Clear This Year?';


            const subtitle =
                isMonth
                    ? 'Remove this month\'s expenses'
                    : 'Remove this year\'s expenses';


            const buttonText =
                isMonth
                    ? 'CLEAR THIS MONTH'
                    : 'CLEAR THIS YEAR';


            openExpenseConfirm({

                title:
                    title,

                subtitle:
                    subtitle,

                icon:
                    `

                        <i
                            class="fas
                                   fa-calendar-times"
                        ></i>

                    `,

                message:
                    `

                        Are you sure you want to
                        permanently clear all
                        expense records for
                        <strong>
                            ${period}
                        </strong>
                        for this branch?

                    `,

                warning:
                    `

                        <i
                            class="fas
                                   fa-exclamation-triangle
                                   me-1"
                        ></i>

                        All matching expense records
                        will be permanently deleted.
                        This action cannot be undone.

                    `,

                button:
                    `

                        <i
                            class="fas
                                   fa-trash-alt
                                   me-1"
                        ></i>

                        ${buttonText}

                    `,

                onConfirm:
                    async function () {

                        try {

                            const response =
                                await fetch(
                                    'actions/expenses.php',
                                    {
                                        method:
                                            'POST',

                                        credentials:
                                            'same-origin',

                                        headers: {
                                            'Accept':
                                                'application/json',
                                            'Content-Type':
                                                'application/x-www-form-urlencoded; charset=UTF-8'
                                        },

                                        body:
                                            new URLSearchParams(
                                                {
                                                    action:
                                                        action
                                                }
                                            )
                                    }
                                );


                            const data =
                                await response.json();


                            if (
                                data.status !==
                                'success'
                            ) {

                                throw new Error(
                                    data.message ||
                                    'Unable to clear expenses.'
                                );

                            }


                            load();

                        } catch (error) {

                            console.error(
                                'Clear expenses error:',
                                error
                            );


                            alert(
                                error.message ||
                                'Unable to clear expenses.'
                            );

                        }

                    }

            );

        }
    );


    /* ========================================================
       LIVE FILTERING
    ======================================================== */

    $(
        '#expenseSearch,' +
        '#filterCategory,' +
        '#filterStart,' +
        '#filterEnd'
    ).on(
        'input change',
        render
    );


    /* ========================================================
       INITIAL DATES
    ======================================================== */

    const t =
        today();


    $('#filterStart')
        .val(
            t.slice(0, 8) +
            '01'
        );


    $('#filterEnd')
        .val(t);


    /* ========================================================
       INITIAL LOAD
    ======================================================== */

    load();


})();

</script>


</body>

</html>
