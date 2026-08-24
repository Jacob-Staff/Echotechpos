<?php
/**
 * ============================================================
 * PHARMANOVA POS
 * EXPENSES
 * ============================================================
 *
 * ONE SELF-CONTAINED PAGE
 *
 * Uses existing:
 *   ../includes/conn.php
 *   ../includes/auth.php
 *   ../includes/head.php
 *   ../includes/header.php
 *   ../includes/aside.php
 *   ../includes/footer.php
 *
 * Existing action files still used for:
 *   actions/add_expense.php
 *   actions/delete_expense.php
 *   actions/clear_expenses.php
 *
 * IMPORTANT:
 * We DO NOT use fetch_expenses.php anymore.
 * Expenses are fetched directly in this page.
 * ============================================================
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');


/* ============================================================
   DATABASE
============================================================ */

require_once "../includes/conn.php";
require_once "../includes/auth.php";


/* ============================================================
   SESSION
============================================================ */

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    header("Location: ../login.php?error=session_expired");
    exit();
}


/* ============================================================
   CATEGORIES
============================================================ */

$categories = [
    'General',
    'Utilities',
    'Staff Welfare',
    'Logistics',
    'Stock/Supplies',
    'Other'
];


/* ============================================================
   FETCH EXPENSES DIRECTLY
============================================================ */

$expenses = [];
$total_expenses = 0.00;

$expense_sql = "
    SELECT
        id,
        name,
        amount,
        expense_date,
        category,
        recorded_by,
        created_at
    FROM expenses
    WHERE pharmacy_id = ?
      AND branch_id = ?
    ORDER BY expense_date DESC, id DESC
";

$expense_stmt = $conn->prepare($expense_sql);

if ($expense_stmt) {

    $expense_stmt->bind_param(
        "ii",
        $pharmacy_id,
        $branch_id
    );

    if ($expense_stmt->execute()) {

        $expense_result = $expense_stmt->get_result();

        while ($row = $expense_result->fetch_assoc()) {

            $amount = (float)($row['amount'] ?? 0);

            $total_expenses += $amount;

            $expenses[] = [
                'id'           => (int)$row['id'],
                'name'         => $row['name'] ?? '',
                'amount'       => $amount,
                'expense_date' => $row['expense_date'] ?? '',
                'category'     => $row['category'] ?? 'General',
                'recorded_by'  => $row['recorded_by'] ?? '',
                'created_at'   => $row['created_at'] ?? ''
            ];
        }

    }

    $expense_stmt->close();
}


/* ============================================================
   CURRENT MONTH TOTAL
============================================================ */

$month_total = 0.00;

$month_sql = "
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM expenses
    WHERE pharmacy_id = ?
      AND branch_id = ?
      AND MONTH(expense_date) = MONTH(CURDATE())
      AND YEAR(expense_date) = YEAR(CURDATE())
";

$month_stmt = $conn->prepare($month_sql);

if ($month_stmt) {

    $month_stmt->bind_param(
        "ii",
        $pharmacy_id,
        $branch_id
    );

    if ($month_stmt->execute()) {

        $month_result = $month_stmt->get_result();

        if ($month_row = $month_result->fetch_assoc()) {
            $month_total = (float)$month_row['total'];
        }
    }

    $month_stmt->close();
}


/* ============================================================
   RECORD COUNT
============================================================ */

$expense_count = count($expenses);


/* ============================================================
   COMMON HEAD
============================================================ */

require_once "../includes/head.php";

?>

<style>

/* ============================================================
   EXPENSE PAGE
============================================================ */

.expenses-page {

    background: #f4f6f9;

    min-height: calc(100vh - 70px);

    padding: 1.25rem;

    color: #212529;
}


/* ------------------------------------------------------------
   PAGE HEADER
------------------------------------------------------------ */

.expenses-page-title {

    font-size: 1.35rem;

    font-weight: 700;

    color: #172033;

    margin: 0;
}

.expenses-page-subtitle {

    color: #6c757d;

    font-size: 0.82rem;

    margin-top: 3px;
}


/* ------------------------------------------------------------
   STAT CARDS
------------------------------------------------------------ */

.expense-stat {

    background: #ffffff;

    border: 1px solid #e3e7ec;

    border-radius: 12px;

    padding: 1rem;

    height: 100%;

    box-shadow: 0 2px 7px rgba(0,0,0,.035);
}

.expense-stat-label {

    color: #6c757d;

    font-size: .72rem;

    font-weight: 600;

    text-transform: uppercase;
}

.expense-stat-value {

    font-size: 1.35rem;

    font-weight: 800;

    color: #198754;

    margin-top: 3px;
}

.expense-stat-icon {

    width: 40px;

    height: 40px;

    border-radius: 10px;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #e8f7ee;

    color: #198754;

    font-size: 17px;
}


/* ------------------------------------------------------------
   CARDS
------------------------------------------------------------ */

.expense-card {

    background: #ffffff;

    border: 1px solid #e2e7ed;

    border-radius: 12px;

    box-shadow: 0 2px 7px rgba(15,23,42,.035);

    overflow: hidden;
}

.expense-card-header {

    padding: 1rem 1.1rem;

    border-bottom: 1px solid #e6e9ee;

    background: #ffffff;
}

.expense-card-title {

    font-size: 1rem;

    font-weight: 700;

    color: #172033;

    margin: 0;
}

.expense-card-subtitle {

    color: #6c757d;

    font-size: .78rem;

    margin-top: 2px;
}


/* ------------------------------------------------------------
   FORM
------------------------------------------------------------ */

.expense-form {

    padding: 1.1rem;
}

.expense-form .form-label {

    font-size: .82rem;

    font-weight: 600;

    color: #172033;

    margin-bottom: .4rem;
}

.expense-form .form-control,
.expense-form .form-select {

    min-height: 44px;

    border: 1px solid #d6dce4;

    border-radius: 7px;

    background: #fff;

    color: #212529;

    font-size: .85rem;
}

.expense-form .form-control:focus,
.expense-form .form-select:focus {

    border-color: #0d6efd;

    box-shadow: 0 0 0 .15rem rgba(13,110,253,.10);
}


/* ------------------------------------------------------------
   SAVE BUTTON
------------------------------------------------------------ */

#submitBtn {

    min-height: 44px;

    border-radius: 7px;

    background: #198754;

    border: none;

    color: #fff;

    font-size: .82rem;

    font-weight: 700;
}

#submitBtn:hover {

    background: #157347;
}

#submitBtn:disabled {

    opacity: .75;

    cursor: not-allowed;
}


/* ------------------------------------------------------------
   TABLE
------------------------------------------------------------ */

.expenses-table-wrapper {

    overflow-x: auto;
}

.expenses-table {

    width: 100%;

    margin: 0;

    border-collapse: collapse;
}

.expenses-table thead th {

    background: #f5f7fa;

    color: #3d4652;

    font-size: .71rem;

    font-weight: 700;

    text-transform: uppercase;

    padding: 13px;

    border-bottom: 2px solid #e1e5ea;

    white-space: nowrap;
}

.expenses-table tbody td {

    padding: 12px 13px;

    border-bottom: 1px solid #edf0f3;

    color: #212529;

    font-size: .82rem;

    vertical-align: middle;
}

.expenses-table tbody tr:hover {

    background: #f9fbfd;
}


/* ------------------------------------------------------------
   CATEGORY
------------------------------------------------------------ */

.category-badge {

    display: inline-block;

    padding: 4px 9px;

    border-radius: 20px;

    background: #eef2f6;

    color: #495057;

    font-size: .69rem;

    font-weight: 600;

    white-space: nowrap;
}


/* ------------------------------------------------------------
   AMOUNT
------------------------------------------------------------ */

.expense-amount {

    color: #198754;

    font-weight: 700;

    white-space: nowrap;
}


/* ------------------------------------------------------------
   DELETE
------------------------------------------------------------ */

.delete-expense {

    width: 32px;

    height: 32px;

    padding: 0;

    border-radius: 6px;

    display: inline-flex;

    align-items: center;

    justify-content: center;
}


/* ------------------------------------------------------------
   EMPTY STATE
------------------------------------------------------------ */

.empty-expenses {

    text-align: center;

    padding: 45px 15px !important;

    color: #6c757d;
}

.empty-expenses i {

    display: block;

    font-size: 30px;

    margin-bottom: 10px;

    color: #ced4da;
}


/* ------------------------------------------------------------
   ALERT
------------------------------------------------------------ */

#expenseAlert {

    display: none;

    border-radius: 8px;

    font-size: .82rem;
}


/* ------------------------------------------------------------
   RESPONSIVE
------------------------------------------------------------ */

@media (max-width: 991px) {

    .expenses-page {

        padding: 1rem;
    }

}

@media (max-width: 575px) {

    .expenses-page {

        padding: .75rem;
    }

    .expenses-table thead th,
    .expenses-table tbody td {

        padding: 9px 8px;
    }

}


/* ------------------------------------------------------------
   PRINT
------------------------------------------------------------ */

@media print {

    .no-print,
    #submitBtn,
    .delete-expense,
    .clear-records-btn {

        display: none !important;
    }

    .expenses-page {

        background: #fff !important;

        padding: 0 !important;
    }

    .expense-card,
    .expense-stat {

        box-shadow: none !important;

        border: 1px solid #ccc !important;
    }
}

</style>


<div id="main-wrapper">


    <!-- ========================================================
         EXISTING HEADER
    ========================================================= -->

    <?php

    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }

    ?>


    <!-- ========================================================
         EXISTING SIDEBAR
    ========================================================= -->

    <?php

    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }

    ?>


    <!-- ========================================================
         PAGE
    ========================================================= -->

    <div class="page-wrapper expenses-page">

        <div class="container-fluid p-0">


            <!-- ==================================================
                 PAGE HEADER
            =================================================== -->

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

                <div>

                    <h4 class="expenses-page-title">

                        <i class="fas fa-receipt text-primary me-2"></i>

                        Expenses

                    </h4>

                    <div class="expenses-page-subtitle">

                        Record and manage expenses for this branch

                    </div>

                </div>


                <div class="no-print">

                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        onclick="window.print();"
                    >

                        <i class="fas fa-print me-1"></i>

                        Print

                    </button>

                </div>

            </div>


            <!-- ==================================================
                 ALERT
            =================================================== -->

            <div
                id="expenseAlert"
                class="alert"
                role="alert"
            ></div>


            <!-- ==================================================
                 SUMMARY CARDS
            =================================================== -->

            <div class="row g-3 mb-4">


                <!-- TOTAL -->
                <div class="col-md-4">

                    <div class="expense-stat">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>

                                <div class="expense-stat-label">
                                    Total Expenses
                                </div>

                                <div class="expense-stat-value">

                                    K<span id="total_display">
                                        <?= number_format($total_expenses, 2); ?>
                                    </span>

                                </div>

                            </div>

                            <div class="expense-stat-icon">

                                <i class="fas fa-money-bill-wave"></i>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- THIS MONTH -->
                <div class="col-md-4">

                    <div class="expense-stat">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>

                                <div class="expense-stat-label">
                                    This Month
                                </div>

                                <div class="expense-stat-value">

                                    K<?= number_format($month_total, 2); ?>

                                </div>

                            </div>

                            <div
                                class="expense-stat-icon"
                                style="background:#fff5dd;color:#c58a00;"
                            >

                                <i class="fas fa-calendar-alt"></i>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- RECORDS -->
                <div class="col-md-4">

                    <div class="expense-stat">

                        <div class="d-flex justify-content-between align-items-center">

                            <div>

                                <div class="expense-stat-label">
                                    Records
                                </div>

                                <div
                                    class="expense-stat-value"
                                    style="color:#0d6efd;"
                                >

                                    <?= number_format($expense_count); ?>

                                </div>

                            </div>

                            <div
                                class="expense-stat-icon"
                                style="background:#e9f1ff;color:#0d6efd;"
                            >

                                <i class="fas fa-list"></i>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ==================================================
                 MAIN CONTENT
            =================================================== -->

            <div class="row g-4">


                <!-- =================================================
                     RECORD EXPENSE
                ================================================== -->

                <div class="col-lg-4">

                    <div class="expense-card">

                        <div class="expense-card-header">

                            <div class="expense-card-title">

                                <i class="fas fa-plus-circle text-primary me-2"></i>

                                Record Expense

                            </div>

                            <div class="expense-card-subtitle">

                                Record a business expense for this branch.

                            </div>

                        </div>


                        <div class="expense-form">

                            <form
                                id="expenseForm"
                                autocomplete="off"
                            >


                                <!-- DESCRIPTION -->

                                <div class="mb-3">

                                    <label
                                        for="expense_name"
                                        class="form-label"
                                    >
                                        Description
                                    </label>

                                    <input
                                        type="text"
                                        id="expense_name"
                                        name="name"
                                        class="form-control"
                                        placeholder="e.g. Electricity bill"
                                        maxlength="255"
                                        required
                                    >

                                </div>


                                <!-- AMOUNT -->

                                <div class="mb-3">

                                    <label
                                        for="expense_amount"
                                        class="form-label"
                                    >
                                        Amount (K)
                                    </label>

                                    <div class="input-group">

                                        <span class="input-group-text">
                                            K
                                        </span>

                                        <input
                                            type="number"
                                            id="expense_amount"
                                            name="amount"
                                            class="form-control"
                                            placeholder="0.00"
                                            min="0.01"
                                            step="0.01"
                                            required
                                        >

                                    </div>

                                </div>


                                <!-- CATEGORY -->

                                <div class="mb-3">

                                    <label
                                        for="expense_category"
                                        class="form-label"
                                    >
                                        Category
                                    </label>

                                    <select
                                        id="expense_category"
                                        name="category"
                                        class="form-select"
                                        required
                                    >

                                        <?php foreach ($categories as $category): ?>

                                            <option
                                                value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>"
                                            >

                                                <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <!-- DATE -->

                                <div class="mb-3">

                                    <label
                                        for="expense_date"
                                        class="form-label"
                                    >
                                        Expense Date
                                    </label>

                                    <input
                                        type="date"
                                        id="expense_date"
                                        name="date"
                                        class="form-control"
                                        value="<?= date('Y-m-d'); ?>"
                                        required
                                    >

                                </div>


                                <!-- SAVE -->

                                <button
                                    type="submit"
                                    id="submitBtn"
                                    class="btn w-100"
                                >

                                    <i class="fas fa-save me-1"></i>

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

                    <div class="expense-card">


                        <!-- HEADER -->

                        <div class="expense-card-header">

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                                <div>

                                    <div class="expense-card-title">

                                        <i class="fas fa-history text-primary me-2"></i>

                                        Expense History

                                    </div>

                                    <div class="expense-card-subtitle">

                                        Branch expense records

                                    </div>

                                </div>


                                <!-- CLEAR -->

                                <div class="dropdown no-print">

                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm dropdown-toggle clear-records-btn"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false"
                                    >

                                        <i class="fas fa-trash-alt me-1"></i>

                                        CLEAR RECORDS

                                    </button>


                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>

                                            <a
                                                href="#"
                                                class="dropdown-item clear-btn"
                                                data-type="month"
                                            >

                                                <i class="fas fa-calendar-alt me-2 text-warning"></i>

                                                This Month

                                            </a>

                                        </li>


                                        <li>

                                            <a
                                                href="#"
                                                class="dropdown-item clear-btn text-danger"
                                                data-type="year"
                                            >

                                                <i class="fas fa-calendar-times me-2"></i>

                                                This Year

                                            </a>

                                        </li>

                                    </ul>

                                </div>

                            </div>

                        </div>


                        <!-- TABLE -->

                        <div class="expenses-table-wrapper">

                            <table class="table expenses-table">

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

                                        <th class="text-end">
                                            Amount
                                        </th>

                                        <th class="text-center no-print">
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody id="expenses_list">


                                <?php if (empty($expenses)): ?>


                                    <tr>

                                        <td
                                            colspan="5"
                                            class="empty-expenses"
                                        >

                                            <i class="fas fa-receipt"></i>

                                            No expenses recorded for this branch.

                                        </td>

                                    </tr>


                                <?php else: ?>


                                    <?php foreach ($expenses as $expense): ?>


                                        <tr>

                                            <!-- DESCRIPTION -->

                                            <td>

                                                <?= htmlspecialchars(
                                                    $expense['name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>

                                            </td>


                                            <!-- CATEGORY -->

                                            <td>

                                                <span class="category-badge">

                                                    <?= htmlspecialchars(
                                                        $expense['category'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>

                                                </span>

                                            </td>


                                            <!-- DATE -->

                                            <td>

                                                <?php

                                                $formatted_date = '';

                                                if (!empty($expense['expense_date'])) {

                                                    $timestamp = strtotime(
                                                        $expense['expense_date']
                                                    );

                                                    if ($timestamp !== false) {

                                                        $formatted_date =
                                                            date(
                                                                'd M Y',
                                                                $timestamp
                                                            );

                                                    }

                                                }

                                                echo htmlspecialchars(
                                                    $formatted_date,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );

                                                ?>

                                            </td>


                                            <!-- AMOUNT -->

                                            <td class="text-end">

                                                <span
                                                    class="expense-amount amt-val"
                                                    data-amt="<?= htmlspecialchars(
                                                        (string)$expense['amount'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>"
                                                >

                                                    K<?= number_format(
                                                        $expense['amount'],
                                                        2
                                                    ); ?>

                                                </span>

                                            </td>


                                            <!-- ACTION -->

                                            <td class="text-center no-print">

                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger delete-expense"
                                                    data-id="<?= (int)$expense['id']; ?>"
                                                    title="Delete expense"
                                                >

                                                    <i class="fas fa-trash"></i>

                                                </button>

                                            </td>

                                        </tr>


                                    <?php endforeach; ?>


                                <?php endif; ?>


                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ========================================================
         EXISTING FOOTER
    ========================================================= -->

    <?php

    if (file_exists("../includes/footer.php")) {
        require_once "../includes/footer.php";
    }

    ?>

</div>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script>
(function () {

    "use strict";


    /* ========================================================
       SHOW ALERT
    ======================================================== */

    function showExpenseAlert(type, message) {

        const alertBox =
            document.getElementById('expenseAlert');

        if (!alertBox) {
            return;
        }

        alertBox.className =
            'alert alert-' + type;

        alertBox.innerHTML =
            message;

        alertBox.style.display =
            'block';


        setTimeout(function () {

            alertBox.style.display =
                'none';

        }, 4500);

    }


    /* ========================================================
       ADD EXPENSE
    ======================================================== */

    const expenseForm =
        document.getElementById('expenseForm');

    if (expenseForm) {

        expenseForm.addEventListener(
            'submit',
            function (event) {

                event.preventDefault();


                const submitButton =
                    document.getElementById('submitBtn');


                submitButton.disabled =
                    true;

                submitButton.innerHTML =
                    '<i class="fas fa-spinner fa-spin me-1"></i> SAVING...';


                const formData =
                    new FormData(expenseForm);


                fetch(
                    'actions/add_expense.php',
                    {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }
                )

                .then(function (response) {

                    return response.text();

                })

                .then(function (text) {

                    let data = null;

                    try {

                        data =
                            JSON.parse(text);

                    } catch (error) {

                        console.error(
                            'Invalid JSON from add_expense.php:',
                            text
                        );

                        throw new Error(
                            'Invalid server response.'
                        );

                    }


                    if (
                        data &&
                        data.status === 'success'
                    ) {

                        showExpenseAlert(
                            'success',
                            data.message ||
                            'Expense saved successfully.'
                        );


                        /*
                         * Reload page.
                         *
                         * This is deliberate.
                         * The expense list is server-rendered,
                         * so we don't need fetch_expenses.php.
                         */
                        setTimeout(
                            function () {

                                window.location.reload();

                            },
                            500
                        );


                    } else {

                        throw new Error(
                            data &&
                            data.message
                                ? data.message
                                : 'Unable to save expense.'
                        );

                    }

                })

                .catch(function (error) {

                    console.error(
                        'Expense save error:',
                        error
                    );


                    showExpenseAlert(
                        'danger',
                        error.message ||
                        'Unable to save expense.'
                    );


                    submitButton.disabled =
                        false;

                    submitButton.innerHTML =
                        '<i class="fas fa-save me-1"></i> SAVE EXPENSE';

                });

            }
        );

    }


    /* ========================================================
       DELETE EXPENSE
    ======================================================== */

    document.addEventListener(
        'click',
        function (event) {

            const button =
                event.target.closest(
                    '.delete-expense'
                );


            if (!button) {
                return;
            }


            event.preventDefault();


            const expenseId =
                button.getAttribute(
                    'data-id'
                );


            if (!expenseId) {

                showExpenseAlert(
                    'danger',
                    'Invalid expense record.'
                );

                return;

            }


            const confirmed =
                confirm(
                    'Are you sure you want to delete this expense?'
                );


            if (!confirmed) {
                return;
            }


            button.disabled =
                true;

            button.innerHTML =
                '<i class="fas fa-spinner fa-spin"></i>';


            const formData =
                new FormData();

            formData.append(
                'id',
                expenseId
            );


            fetch(
                'actions/delete_expense.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }
            )

            .then(function (response) {

                return response.text();

            })

            .then(function (responseText) {

                const result =
                    responseText.trim();


                if (result === 'success') {

                    showExpenseAlert(
                        'success',
                        'Expense deleted successfully.'
                    );


                    setTimeout(
                        function () {

                            window.location.reload();

                        },
                        400
                    );


                } else {

                    throw new Error(
                        result ||
                        'Unable to delete expense.'
                    );

                }

            })

            .catch(function (error) {

                console.error(
                    'Delete expense error:',
                    error
                );


                button.disabled =
                    false;

                button.innerHTML =
                    '<i class="fas fa-trash"></i>';


                showExpenseAlert(
                    'danger',
                    error.message ||
                    'Unable to delete expense.'
                );

            });

        }
    );


    /* ========================================================
       CLEAR EXPENSES
    ======================================================== */

    document.addEventListener(
        'click',
        function (event) {

            const button =
                event.target.closest(
                    '.clear-btn'
                );


            if (!button) {
                return;
            }


            event.preventDefault();


            const type =
                button.getAttribute(
                    'data-type'
                );


            if (
                type !== 'month' &&
                type !== 'year'
            ) {

                return;

            }


            let confirmationMessage;


            if (type === 'month') {

                confirmationMessage =
                    'Are you sure you want to clear ALL expenses for this month?';

            } else {

                confirmationMessage =
                    'Are you sure you want to clear ALL expenses for this year?';

            }


            if (
                !confirm(
                    confirmationMessage
                )
            ) {

                return;

            }


            const formData =
                new FormData();

            formData.append(
                'type',
                type
            );


            fetch(
                'actions/clear_expenses.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }
            )

            .then(function (response) {

                return response.text();

            })

            .then(function (responseText) {

                const result =
                    responseText.trim();


                if (result === 'success') {

                    showExpenseAlert(
                        'success',
                        type === 'month'
                            ? 'This month\'s expenses have been cleared.'
                            : 'This year\'s expenses have been cleared.'
                    );


                    setTimeout(
                        function () {

                            window.location.reload();

                        },
                        400
                    );


                } else {

                    throw new Error(
                        result ||
                        'Unable to clear expenses.'
                    );

                }

            })

            .catch(function (error) {

                console.error(
                    'Clear expenses error:',
                    error
                );


                showExpenseAlert(
                    'danger',
                    error.message ||
                    'Unable to clear expenses.'
                );

            });

        }
    );


})();
</script>
