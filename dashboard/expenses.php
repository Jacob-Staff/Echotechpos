<?php
/**
 * ============================================================
 * PHARMANOVA / PHARMACY POS
 * EXPENSES PAGE
 * ============================================================
 *
 * Uses the existing:
 *   ../includes/head.php
 *   ../includes/header.php
 *   ../includes/aside.php
 *   ../includes/footer.php
 *
 * Existing expense actions:
 *   actions/fetch_expenses.php
 *   actions/add_expense.php
 *   actions/delete_expense.php
 *   actions/clear_expenses.php
 * ============================================================
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

/* ------------------------------------------------------------
   SESSION / TENANT
------------------------------------------------------------ */
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

/*
 * Use Zambia time for the POS.
 * This keeps the default expense date consistent with
 * the business/local date rather than a UTC server date.
 */
date_default_timezone_set('Africa/Lusaka');

/* ------------------------------------------------------------
   EXPENSE CATEGORIES
------------------------------------------------------------ */
$categories = [
    'General',
    'Utilities',
    'Staff Welfare',
    'Logistics',
    'Stock/Supplies',
    'Other'
];

/* ------------------------------------------------------------
   OPTIONAL DISPLAY INFORMATION
   Header itself remains responsible for its own display.
------------------------------------------------------------ */
$page_title = "Expenses";

/* ------------------------------------------------------------
   LOAD COMMON HEAD
------------------------------------------------------------ */
require_once "../includes/head.php";
?>

<style>
/* ============================================================
   EXPENSES PAGE
============================================================ */

.expenses-page {
    background: #f4f6f9;
    min-height: calc(100vh - 70px);
    padding: 1.25rem;
    color: #212529;
}

/* Page heading */
.expenses-page .page-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #172033;
    margin: 0;
}

.expenses-page .page-subtitle {
    font-size: 0.82rem;
    color: #6c757d;
    margin-top: 2px;
}

/* Cards */
.expense-card {
    background: #ffffff;
    border: 1px solid #e5e9ef;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.expense-card-header {
    background: #ffffff;
    border-bottom: 1px solid #e5e9ef;
    padding: 1rem 1.1rem;
}

.expense-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #172033;
    margin: 0;
}

.expense-card-subtitle {
    color: #6c757d;
    font-size: 0.78rem;
    margin-top: 2px;
}

/* Record expense panel */
.record-panel {
    background: #ffffff;
    border: 1px solid #e5e9ef;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}

.record-panel .card-body {
    padding: 1.15rem;
}

/* Labels */
.expenses-page .form-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #172033;
    margin-bottom: 0.4rem;
}

/* Inputs */
.expenses-page .form-control,
.expenses-page .form-select {
    min-height: 44px;
    border: 1px solid #d9dee7;
    border-radius: 7px;
    background: #ffffff;
    color: #212529;
    font-size: 0.86rem;
    box-shadow: none;
}

.expenses-page .form-control:focus,
.expenses-page .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.15rem rgba(13, 110, 253, 0.10);
}

/* Save button */
#submitBtn {
    min-height: 44px;
    border-radius: 7px;
    background: #198754;
    border: none;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.84rem;
}

#submitBtn:hover {
    background: #157347;
}

#submitBtn:disabled {
    opacity: 0.75;
    cursor: not-allowed;
}

/* Summary */
.expense-summary {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.expense-summary-icon {
    width: 38px;
    height: 38px;
    border-radius: 9px;
    background: #e8f7ee;
    color: #198754;
    display: flex;
    align-items: center;
    justify-content: center;
}

.expense-total-label {
    font-size: 0.72rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
}

.expense-total {
    font-size: 1.15rem;
    font-weight: 800;
    color: #198754;
}

/* Clear button */
.clear-records-btn {
    border-radius: 6px;
    font-size: 0.76rem;
    font-weight: 700;
}

/* Table */
.expenses-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.expenses-table {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
}

.expenses-table thead th {
    background: #f8f9fb;
    color: #495057;
    border-bottom: 2px solid #e2e6eb;
    padding: 12px 13px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
}

.expenses-table tbody td {
    padding: 12px 13px;
    border-bottom: 1px solid #edf0f3;
    color: #212529;
    font-size: 0.82rem;
    vertical-align: middle;
}

.expenses-table tbody tr:hover {
    background: #f8fbff;
}

/* Empty/loading */
.expenses-loading {
    padding: 35px 15px !important;
    text-align: center;
    color: #6c757d;
}

.expenses-loading i {
    margin-right: 6px;
}

/* Category badge */
.expense-category-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 20px;
    background: #eef3f8;
    color: #495057;
    font-size: 0.7rem;
    font-weight: 600;
}

/* Amount */
.expense-amount {
    font-weight: 700;
    color: #198754;
    white-space: nowrap;
}

/* Delete button */
.delete-expense {
    width: 32px;
    height: 32px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

/* Alerts */
.expense-alert {
    display: none;
    margin-bottom: 1rem;
    border-radius: 8px;
    font-size: 0.82rem;
}

/* Responsive */
@media (max-width: 991.98px) {
    .expenses-page {
        padding: 1rem;
    }
}

@media (max-width: 575.98px) {
    .expenses-page {
        padding: 0.75rem;
    }

    .expense-card-header {
        padding: 0.85rem;
    }

    .record-panel .card-body {
        padding: 0.9rem;
    }

    .expenses-table thead th,
    .expenses-table tbody td {
        padding: 10px 8px;
    }
}

/* Print */
@media print {

    .no-print,
    #submitBtn,
    .clear-records-btn,
    .delete-expense {
        display: none !important;
    }

    .expenses-page {
        background: #ffffff !important;
        padding: 0 !important;
    }

    .expense-card,
    .record-panel {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
    }
}
</style>


<div id="main-wrapper">

    <?php
    /*
     * IMPORTANT:
     * Use the SAME existing header and aside as the rest
     * of the POS. Do not recreate them on this page.
     */
    if (file_exists("../includes/header.php")) {
        require_once "../includes/header.php";
    }

    if (file_exists("../includes/aside.php")) {
        require_once "../includes/aside.php";
    }
    ?>


    <!-- =====================================================
         PAGE CONTENT
    ====================================================== -->
    <div class="page-wrapper expenses-page">

        <div class="container-fluid p-0">

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">

                <div>
                    <h4 class="page-title">
                        <i class="fas fa-receipt me-2 text-primary"></i>
                        Expenses
                    </h4>

                    <div class="page-subtitle">
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


            <!-- Alert -->
            <div
                id="expenseAlert"
                class="alert expense-alert"
                role="alert"
            ></div>


            <div class="row g-4">

                <!-- =================================================
                     RECORD EXPENSE
                ================================================== -->
                <div class="col-lg-4">

                    <div class="record-panel">

                        <div class="expense-card-header">
                            <div class="expense-card-title">
                                <i class="fas fa-plus-circle text-primary me-2"></i>
                                Record Expense
                            </div>

                            <div class="expense-card-subtitle">
                                Record a business expense for this branch.
                            </div>
                        </div>

                        <div class="card-body">

                            <form id="expenseForm" autocomplete="off">

                                <!-- Description -->
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


                                <!-- Amount -->
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


                                <!-- Category -->
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

                                            <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <!-- Expense Date -->
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


                                <!-- Save -->
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

                        <div class="expense-card-header">

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                                <div class="expense-summary">

                                    <div class="expense-summary-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>

                                    <div>

                                        <div class="expense-total-label">
                                            Total Expenses
                                        </div>

                                        <div class="expense-total">
                                            K<span id="total_display">0.00</span>
                                        </div>

                                    </div>

                                </div>


                                <!-- Clear Records -->
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


                        <!-- Table -->
                        <div class="expenses-table-wrapper">

                            <table class="table expenses-table">

                                <thead>

                                    <tr>
                                        <th>Description</th>
                                        <th>Category</th>
                                        <th>Date</th>
                                        <th class="text-end">Amount</th>
                                        <th class="text-center no-print">Action</th>
                                    </tr>

                                </thead>

                                <tbody id="expenses_list">

                                    <tr>

                                        <td
                                            colspan="5"
                                            class="expenses-loading"
                                        >
                                            <i class="fas fa-spinner fa-spin"></i>
                                            Loading expenses...
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


    <?php
    /*
     * Keep the existing footer.
     * This is important because the common layout/header
     * JavaScript may be loaded by the footer.
     */
    if (file_exists("../includes/footer.php")) {
        require_once "../includes/footer.php";
    }
    ?>

</div>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
(function () {

    "use strict";

    /* --------------------------------------------------------
       DOM READY
    -------------------------------------------------------- */
    $(document).ready(function () {

        loadExpenses();


        /* ====================================================
           LOAD EXPENSES
        ==================================================== */
        function loadExpenses() {

            const $list = $('#expenses_list');

            $list.html(
                '<tr>' +
                    '<td colspan="5" class="expenses-loading">' +
                        '<i class="fas fa-spinner fa-spin"></i>' +
                        ' Loading expenses...' +
                    '</td>' +
                '</tr>'
            );

            $.ajax({
                url: 'actions/fetch_expenses.php',
                type: 'GET',
                cache: false,

                success: function (data) {

                    if ($.trim(data) === '') {

                        $list.html(
                            '<tr>' +
                                '<td colspan="5" class="expenses-loading">' +
                                    'No expenses recorded for this branch.' +
                                '</td>' +
                            '</tr>'
                        );

                    } else {

                        $list.html(data);

                    }

                    calculateTotal();
                    enhanceExpenseRows();
                },

                error: function (xhr, status, error) {

                    console.error(
                        'Expense fetch error:',
                        status,
                        error
                    );

                    console.error(
                        'Server response:',
                        xhr.responseText
                    );

                    $list.html(
                        '<tr>' +
                            '<td colspan="5" class="text-center py-4 text-danger">' +
                                '<i class="fas fa-exclamation-triangle me-2"></i>' +
                                'Unable to load expenses. Please refresh the page.' +
                            '</td>' +
                        '</tr>'
                    );

                }
            });

        }


        /* ====================================================
           CALCULATE TOTAL
           Supports both:
             .amt
             .amt-val
        ==================================================== */
        function calculateTotal() {

            let total = 0;

            $('.amt, .amt-val').each(function () {

                let value = $(this).attr('data-amt');

                if (value === undefined || value === null) {
                    value = $(this).data('amt');
                }

                value = parseFloat(value);

                if (!isNaN(value)) {
                    total += value;
                }

            });

            $('#total_display').text(
                total.toLocaleString('en-ZM', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                })
            );

        }


        /* ====================================================
           IMPROVE EXISTING FETCHED ROWS
        ==================================================== */
        function enhanceExpenseRows() {

            $('#expenses_list tr').each(function () {

                const $row = $(this);

                /*
                 * Add styling to category cells.
                 */
                $row.find('td').each(function () {

                    const text = $.trim($(this).text());

                    if (
                        text &&
                        (
                            text === 'General' ||
                            text === 'Utilities' ||
                            text === 'Staff Welfare' ||
                            text === 'Logistics' ||
                            text === 'Stock/Supplies' ||
                            text === 'Other'
                        )
                    ) {

                        if (!$(this).find('.expense-category-badge').length) {

                            $(this).html(
                                '<span class="expense-category-badge">' +
                                $('<div>').text(text).html() +
                                '</span>'
                            );

                        }

                    }

                });

            });

        }


        /* ====================================================
           SAVE EXPENSE
        ==================================================== */
        $('#expenseForm').on('submit', function (e) {

            e.preventDefault();

            const form = this;
            const $btn = $('#submitBtn');

            const description = $.trim(
                $('#expense_name').val()
            );

            const amount = parseFloat(
                $('#expense_amount').val()
            );

            const category = $('#expense_category').val();
            const date = $('#expense_date').val();


            /* Basic validation */
            if (!description) {
                showAlert(
                    'danger',
                    'Please enter an expense description.'
                );

                $('#expense_name').focus();
                return;
            }

            if (isNaN(amount) || amount <= 0) {
                showAlert(
                    'danger',
                    'Please enter a valid expense amount.'
                );

                $('#expense_amount').focus();
                return;
            }

            if (!category) {
                showAlert(
                    'danger',
                    'Please select an expense category.'
                );

                $('#expense_category').focus();
                return;
            }

            if (!date) {
                showAlert(
                    'danger',
                    'Please select the expense date.'
                );

                $('#expense_date').focus();
                return;
            }


            /* Disable button */
            $btn
                .prop('disabled', true)
                .html(
                    '<i class="fas fa-spinner fa-spin me-1"></i>' +
                    ' SAVING...'
                );


            $.ajax({

                url: 'actions/add_expense.php',

                type: 'POST',

                data: $(form).serialize(),

                dataType: 'json',

                cache: false,

                success: function (response) {

                    console.log(
                        'Add expense response:',
                        response
                    );


                    if (
                        response &&
                        response.status === 'success'
                    ) {

                        showAlert(
                            'success',
                            response.message ||
                            'Expense saved successfully.'
                        );


                        /*
                         * Reset only after successful save.
                         */
                        form.reset();


                        /*
                         * Reset date to today's local
                         * Zambia date.
                         */
                        $('#expense_date').val(
                            getLocalDate()
                        );


                        /*
                         * Refresh history.
                         */
                        loadExpenses();

                    } else {

                        showAlert(
                            'danger',
                            (
                                response &&
                                response.message
                            )
                                ? response.message
                                : 'Unable to save expense.'
                        );

                    }

                },

                error: function (xhr, status, error) {

                    console.error(
                        'Add expense error:',
                        status,
                        error
                    );

                    console.error(
                        'Server response:',
                        xhr.responseText
                    );


                    let message =
                        'Server error while saving the expense.';


                    /*
                     * Try to read JSON error if the
                     * endpoint returned one.
                     */
                    try {

                        const response =
                            JSON.parse(xhr.responseText);

                        if (response.message) {
                            message = response.message;
                        }

                    } catch (e) {
                        // Keep default message.
                    }


                    showAlert(
                        'danger',
                        message
                    );

                },

                complete: function () {

                    $btn
                        .prop('disabled', false)
                        .html(
                            '<i class="fas fa-save me-1"></i>' +
                            ' SAVE EXPENSE'
                        );

                }

            });

        });


        /* ====================================================
           DELETE EXPENSE
        ==================================================== */
        $(document).on(
            'click',
            '.delete-expense',
            function (e) {

                e.preventDefault();

                const id = $(this).data('id');

                if (!id) {

                    showAlert(
                        'danger',
                        'Invalid expense record.'
                    );

                    return;

                }


                if (
                    !confirm(
                        'Are you sure you want to delete this expense?'
                    )
                ) {
                    return;
                }


                const $button = $(this);

                $button
                    .prop('disabled', true)
                    .html(
                        '<i class="fas fa-spinner fa-spin"></i>'
                    );


                $.ajax({

                    url: 'actions/delete_expense.php',

                    type: 'POST',

                    data: {
                        id: id
                    },

                    cache: false,

                    success: function (response) {

                        console.log(
                            'Delete expense response:',
                            response
                        );

                        showAlert(
                            'success',
                            'Expense deleted successfully.'
                        );

                        loadExpenses();

                    },

                    error: function (xhr, status, error) {

                        console.error(
                            'Delete expense error:',
                            status,
                            error
                        );

                        console.error(
                            xhr.responseText
                        );

                        showAlert(
                            'danger',
                            'Unable to delete the expense.'
                        );

                        $button
                            .prop('disabled', false)
                            .html(
                                '<i class="fas fa-trash"></i>'
                            );

                    }

                });

            }
        );


        /* ====================================================
           CLEAR EXPENSES
        ==================================================== */
        $(document).on(
            'click',
            '.clear-btn',
            function (e) {

                e.preventDefault();

                const type = $(this).data('type');

                let message = '';

                if (type === 'month') {

                    message =
                        'This will permanently delete all expenses ' +
                        'for the current month for this branch. ' +
                        'Continue?';

                } else if (type === 'year') {

                    message =
                        'This will permanently delete all expenses ' +
                        'for the current year for this branch. ' +
                        'Continue?';

                } else {

                    return;

                }


                if (!confirm(message)) {
                    return;
                }


                $.ajax({

                    url: 'actions/clear_expenses.php',

                    type: 'POST',

                    data: {
                        type: type
                    },

                    cache: false,

                    success: function (response) {

                        console.log(
                            'Clear expense response:',
                            response
                        );


                        if (
                            $.trim(response) === 'success'
                        ) {

                            showAlert(
                                'success',
                                type === 'month'
                                    ? 'This month\'s expenses have been cleared.'
                                    : 'This year\'s expenses have been cleared.'
                            );

                            loadExpenses();

                        } else {

                            showAlert(
                                'danger',
                                'Unable to clear the expense records.'
                            );

                        }

                    },

                    error: function (xhr, status, error) {

                        console.error(
                            'Clear expenses error:',
                            status,
                            error
                        );

                        console.error(
                            xhr.responseText
                        );

                        showAlert(
                            'danger',
                            'Server error while clearing expenses.'
                        );

                    }

                });

            }
        );


        /* ====================================================
           ALERT
        ==================================================== */
        function showAlert(type, message) {

            const $alert = $('#expenseAlert');

            $alert
                .removeClass(
                    'alert-success ' +
                    'alert-danger ' +
                    'alert-warning ' +
                    'alert-info'
                )
                .addClass('alert-' + type)
                .html(message)
                .stop(true, true)
                .fadeIn(150);


            setTimeout(function () {

                $alert.fadeOut(300);

            }, 4500);

        }


        /* ====================================================
           LOCAL DATE
           Africa/Lusaka / Zambia
        ==================================================== */
        function getLocalDate() {

            const now = new Date();

            const year =
                now.getFullYear();

            const month =
                String(
                    now.getMonth() + 1
                ).padStart(2, '0');

            const day =
                String(
                    now.getDate()
                ).padStart(2, '0');

            return (
                year +
                '-' +
                month +
                '-' +
                day
            );

        }


        /* ====================================================
           ESCAPE DROPDOWN CLOSING / GENERAL BOOTSTRAP
           The Bootstrap bundle is intentionally loaded here
           so the existing header Settings dropdown continues
           to work on this page.
        ==================================================== */

    });

})();
</script>

</body>
</html>
