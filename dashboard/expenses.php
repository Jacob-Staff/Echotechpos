<?php
/**
 * ============================================================
 * PHARMANOVA POS
 * EXPENSES PAGE
 * ============================================================
 *
 * Uses the EXISTING:
 *
 *   ../includes/head.php
 *   ../includes/header.php
 *   ../includes/aside.php
 *   ../includes/footer.php
 *
 * Expense backend:
 *
 *   actions/expenses.php
 *
 * There is NO fetch_expenses.php anymore.
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');


/* ============================================================
   DATABASE + AUTH
============================================================ */

require_once '../includes/conn.php';
require_once '../includes/auth.php';


/* ============================================================
   SESSION
============================================================ */

$pharmacy_id =
    (int)($_SESSION['pharmacy_id'] ?? 0);

$branch_id =
    (int)($_SESSION['branch_id'] ?? 0);


if (
    $pharmacy_id <= 0 ||
    $branch_id <= 0
) {

    header(
        'Location: ../login.php?error=session_expired'
    );

    exit;
}


/* ============================================================
   PHARMACY NAME
============================================================ */

$pharmacy_name =
    'PHARMANOVA';


$branch_name =
    'Main Branch';


$stmt =
    $conn->prepare(
        'SELECT name
         FROM pharmacies
         WHERE id = ?
         LIMIT 1'
    );


if ($stmt) {

    $stmt->bind_param(
        'i',
        $pharmacy_id
    );

    if ($stmt->execute()) {

        $stmt->bind_result(
            $db_pharmacy_name
        );

        if ($stmt->fetch()) {

            if (
                !empty(
                    $db_pharmacy_name
                )
            ) {

                $pharmacy_name =
                    $db_pharmacy_name;
            }
        }
    }

    $stmt->close();
}


/* ============================================================
   BRANCH NAME
============================================================ */

$stmt =
    $conn->prepare(
        'SELECT branch_name
         FROM branches
         WHERE id = ?
           AND pharmacy_id = ?
         LIMIT 1'
    );


if ($stmt) {

    $stmt->bind_param(
        'ii',
        $branch_id,
        $pharmacy_id
    );

    if ($stmt->execute()) {

        $stmt->bind_result(
            $db_branch_name
        );

        if ($stmt->fetch()) {

            if (
                !empty(
                    $db_branch_name
                )
            ) {

                $branch_name =
                    $db_branch_name;
            }
        }
    }

    $stmt->close();
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
   EXISTING HEAD
============================================================ */

require_once '../includes/head.php';

?>


<style>

/* ============================================================
   EXPENSES PAGE
============================================================ */

.expenses-page {

    background: #f4f6f9;

    min-height: calc(100vh - 70px);

    padding: 1.25rem;

    color: #1e293b;
}


/* ============================================================
   PAGE TITLE
============================================================ */

.expense-title-icon {

    width: 42px;

    height: 42px;

    border-radius: 10px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    background: #eaf2ff;

    color: #0d6efd;

}


.expense-title {

    font-size: 1.35rem;

    font-weight: 700;

    margin: 0;

    color: #172033;
}


.expense-subtitle {

    font-size: .78rem;

    color: #6c757d;

    margin-top: 3px;
}


/* ============================================================
   STAT CARDS
============================================================ */

.expense-stat {

    background: #ffffff;

    border: 1px solid #e2e8f0;

    border-radius: 12px;

    box-shadow:
        0 2px 8px
        rgba(15, 23, 42, .05);

}


.expense-stat .card-body {

    padding: 1rem;
}


.expense-stat-label {

    font-size: .72rem;

    color: #6c757d;

    font-weight: 700;

    text-transform: uppercase;
}


.expense-stat-value {

    font-size: 1.45rem;

    font-weight: 800;

    margin-top: 3px;
}


/* ============================================================
   MAIN CARDS
============================================================ */

.expense-card {

    background: #ffffff;

    border: 1px solid #e2e8f0;

    border-radius: 12px;

    box-shadow:
        0 2px 8px
        rgba(15, 23, 42, .05);

    overflow: hidden;
}


.expense-card-header {

    background: #ffffff;

    border-bottom: 1px solid #e2e8f0;
}


.expense-card-title {

    font-size: 1rem;

    font-weight: 700;

    color: #172033;
}


.expense-card-subtitle {

    color: #6c757d;

    font-size: .78rem;
}


/* ============================================================
   FORM
============================================================ */

#expenseForm .form-label {

    font-size: .82rem;

    color: #172033;

}


#expenseForm .form-control,
#expenseForm .form-select {

    min-height: 44px;

    border-color: #d8dee7;

    border-radius: 7px;

    font-size: .85rem;
}


#expenseForm .form-control:focus,
#expenseForm .form-select:focus {

    border-color: #0d6efd;

    box-shadow:
        0 0 0 .15rem
        rgba(13, 110, 253, .10);
}


#submitBtn {

    min-height: 44px;

    font-weight: 700;

    border-radius: 7px;
}


/* ============================================================
   FILTER
============================================================ */

.expense-filter {

    background: #f8fafc;

    border: 1px solid #e2e8f0;

    border-radius: 10px;
}


/* ============================================================
   TABLE
============================================================ */

.expense-table {

    margin-bottom: 0;

    width: 100%;
}


.expense-table thead th {

    background: #f8fafc;

    color: #334155;

    font-size: .72rem;

    text-transform: uppercase;

    letter-spacing: .4px;

    border-bottom: 2px solid #e2e8f0;

    white-space: nowrap;
}


.expense-table tbody td {

    padding: 12px;

    border-color: #f1f5f9;

    vertical-align: middle;

    font-size: .83rem;
}


.expense-table tbody tr:hover {

    background: #f8fafc;
}


/* ============================================================
   CATEGORY
============================================================ */

.expense-category {

    background: #f1f5f9;

    color: #334155;

    border: 1px solid #e2e8f0;

    font-size: .69rem;

    font-weight: 600;
}


/* ============================================================
   AMOUNT
============================================================ */

.expense-amount {

    color: #dc3545;

    font-weight: 800;

    white-space: nowrap;
}


/* ============================================================
   EMPTY
============================================================ */

.expense-empty {

    padding: 45px !important;

    color: #64748b;

    text-align: center;
}


.expense-empty i {

    font-size: 28px;

    color: #cbd5e1;

    display: block;

    margin-bottom: 8px;
}


/* ============================================================
   RESPONSIVE
============================================================ */

@media (max-width: 768px) {

    .expenses-page {

        padding: .75rem;
    }

    .expense-table {

        min-width: 700px;
    }

}


/* ============================================================
   PRINT
============================================================ */

@media print {

    .no-print {

        display: none !important;
    }

    .expenses-page {

        padding: 0 !important;

        background: #fff !important;
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

    if (
        file_exists(
            '../includes/header.php'
        )
    ) {

        require_once
            '../includes/header.php';
    }

    ?>


    <!-- ========================================================
         EXISTING ASIDE
    ========================================================= -->

    <?php

    if (
        file_exists(
            '../includes/aside.php'
        )
    ) {

        require_once
            '../includes/aside.php';
    }

    ?>


    <!-- ========================================================
         PAGE WRAPPER
    ========================================================= -->

    <div class="page-wrapper expenses-page">

        <div class="container-fluid">


            <!-- ==================================================
                 TITLE
            =================================================== -->

            <div
                class="d-flex flex-column flex-md-row
                       justify-content-between
                       align-items-md-center
                       gap-3 mb-4"
            >

                <div
                    class="d-flex
                           align-items-center
                           gap-3"
                >

                    <div class="expense-title-icon">

                        <i class="fas fa-receipt"></i>

                    </div>


                    <div>

                        <h3 class="expense-title">

                            Expenses

                        </h3>


                        <div class="expense-subtitle">

                            <?= htmlspecialchars(
                                $pharmacy_name,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>

                            <span class="mx-1">•</span>

                            <?= htmlspecialchars(
                                $branch_name,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>

                        </div>

                    </div>

                </div>


                <button
                    type="button"
                    class="btn btn-outline-dark
                           fw-bold no-print"
                    onclick="window.print();"
                >

                    <i class="fas fa-print me-1"></i>

                    Print

                </button>

            </div>


            <!-- ==================================================
                 SUMMARY
            =================================================== -->

            <div class="row g-3 mb-4">


                <!-- TOTAL -->

                <div class="col-md-4">

                    <div
                        class="card
                               expense-stat
                               h-100"
                    >

                        <div
                            class="card-body
                                   d-flex
                                   justify-content-between
                                   align-items-center"
                        >

                            <div>

                                <div class="expense-stat-label">

                                    Total Expenses

                                </div>

                                <div
                                    id="total_display"
                                    class="expense-stat-value
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


                <!-- MONTH -->

                <div class="col-md-4">

                    <div
                        class="card
                               expense-stat
                               h-100"
                    >

                        <div
                            class="card-body
                                   d-flex
                                   justify-content-between
                                   align-items-center"
                        >

                            <div>

                                <div class="expense-stat-label">

                                    This Month

                                </div>

                                <div
                                    id="month_display"
                                    class="expense-stat-value
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


                <!-- RECORDS -->

                <div class="col-md-4">

                    <div
                        class="card
                               expense-stat
                               h-100"
                    >

                        <div
                            class="card-body
                                   d-flex
                                   justify-content-between
                                   align-items-center"
                        >

                            <div>

                                <div class="expense-stat-label">

                                    Records

                                </div>

                                <div
                                    id="count_display"
                                    class="expense-stat-value
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


            <!-- ==================================================
                 CONTENT
            =================================================== -->

            <div class="row g-4">


                <!-- =================================================
                     RECORD EXPENSE
                ================================================== -->

                <div class="col-lg-4">

                    <div class="expense-card">

                        <div class="expense-card-header p-3">

                            <div class="expense-card-title">

                                <i
                                    class="fas
                                           fa-plus-circle
                                           text-primary
                                           me-2"
                                ></i>

                                Record Expense

                            </div>

                            <div
                                class="expense-card-subtitle"
                            >

                                Record a business expense
                                for this branch.

                            </div>

                        </div>


                        <div class="card-body p-3 p-lg-4">

                            <form
                                id="expenseForm"
                                autocomplete="off"
                            >


                                <!-- DESCRIPTION -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                        for="expenseName"
                                    >

                                        Description

                                    </label>


                                    <input
                                        type="text"
                                        id="expenseName"
                                        name="name"
                                        class="form-control"
                                        maxlength="255"
                                        placeholder="e.g. Electricity bill"
                                        required
                                    >

                                </div>


                                <!-- AMOUNT -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                        for="expenseAmount"
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
                                            id="expenseAmount"
                                            name="amount"
                                            class="form-control"
                                            min="0.01"
                                            step="0.01"
                                            placeholder="0.00"
                                            required
                                        >

                                    </div>

                                </div>


                                <!-- CATEGORY -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                        for="expenseCategory"
                                    >

                                        Category

                                    </label>


                                    <select
                                        id="expenseCategory"
                                        name="category"
                                        class="form-select"
                                        required
                                    >

                                        <?php

                                        foreach (
                                            $categories
                                            as $category
                                        ):

                                        ?>

                                            <option
                                                value="<?= htmlspecialchars(
                                                    $category,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $category,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>


                                <!-- DATE -->

                                <div class="mb-3">

                                    <label
                                        class="form-label
                                               fw-semibold"
                                        for="expenseDate"
                                    >

                                        Expense Date

                                    </label>


                                    <input
                                        type="date"
                                        id="expenseDate"
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
                                    class="btn btn-primary
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
                     HISTORY
                ================================================== -->

                <div class="col-lg-8">

                    <div class="expense-card">


                        <!-- HEADER -->

                        <div
                            class="expense-card-header
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

                                    <div
                                        class="expense-card-title"
                                    >

                                        <i
                                            class="fas
                                                   fa-history
                                                   text-primary
                                                   me-2"
                                        ></i>

                                        Expense History

                                    </div>


                                    <div
                                        class="expense-card-subtitle"
                                    >

                                        Branch expense records

                                    </div>

                                </div>


                                <!-- CLEAR -->

                                <div
                                    class="dropdown
                                           no-print"
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
                                                href="#"
                                                class="dropdown-item
                                                       clear-btn"
                                                data-type="month"
                                            >

                                                <i
                                                    class="fas
                                                           fa-calendar-alt
                                                           me-2"
                                                ></i>

                                                Clear This Month

                                            </a>

                                        </li>


                                        <li>

                                            <a
                                                href="#"
                                                class="dropdown-item
                                                       clear-btn
                                                       text-danger"
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


                        <!-- BODY -->

                        <div class="card-body p-3">


                            <!-- FILTER -->

                            <div
                                class="expense-filter
                                       p-3
                                       mb-3
                                       no-print"
                            >

                                <div class="row g-2">


                                    <div class="col-md-5">

                                        <label
                                            class="small
                                                   fw-semibold"
                                        >

                                            Search

                                        </label>


                                        <input
                                            type="text"
                                            id="expenseSearch"
                                            class="form-control"
                                            placeholder="Description..."
                                        >

                                    </div>


                                    <div class="col-md-3">

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


                                            <?php

                                            foreach (
                                                $categories
                                                as $category
                                            ):

                                            ?>

                                                <option
                                                    value="<?= htmlspecialchars(
                                                        $category,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>"
                                                >

                                                    <?= htmlspecialchars(
                                                        $category,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ); ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>


                                    <div class="col-md-2">

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


                                    <div class="col-md-2">

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

                            <div class="table-responsive">

                                <table
                                    class="table
                                           table-hover
                                           expense-table"
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

                                            <th class="text-end">
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

                                                <i
                                                    class="fas
                                                           fa-spinner
                                                           fa-spin
                                                           me-2"
                                                ></i>

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

    </div>


    <!-- ========================================================
         EXISTING FOOTER
    ========================================================= -->

    <?php

    if (
        file_exists(
            '../includes/footer.php'
        )
    ) {

        require_once
            '../includes/footer.php';
    }

    ?>

</div>


<script>

/* ============================================================
   EXPENSE PAGE JAVASCRIPT
============================================================ */

(function () {

    'use strict';


    let allExpenses = [];


    /* ========================================================
       TODAY
    ======================================================== */

    function today() {

        const d =
            new Date();

        const year =
            d.getFullYear();

        const month =
            String(
                d.getMonth() + 1
            ).padStart(
                2,
                '0'
            );

        const day =
            String(
                d.getDate()
            ).padStart(
                2,
                '0'
            );

        return (
            year +
            '-' +
            month +
            '-' +
            day
        );
    }


    /* ========================================================
       ESCAPE HTML
    ======================================================== */

    function escapeHtml(value) {

        return String(
            value ?? ''
        )
        .replace(
            /&/g,
            '&amp;'
        )
        .replace(
            /</g,
            '&lt;'
        )
        .replace(
            />/g,
            '&gt;'
        )
        .replace(
            /"/g,
            '&quot;'
        )
        .replace(
            /'/g,
            '&#039;'
        );
    }


    /* ========================================================
       MONEY
    ======================================================== */

    function money(value) {

        return Number(
            value || 0
        ).toLocaleString(
            undefined,
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
    }


    /* ========================================================
       FILTER
    ======================================================== */

    function getFilteredExpenses() {

        const search =
            (
                document.getElementById(
                    'expenseSearch'
                ).value || ''
            )
            .trim()
            .toLowerCase();


        const category =
            document.getElementById(
                'filterCategory'
            ).value;


        const start =
            document.getElementById(
                'filterStart'
            ).value;


        const end =
            document.getElementById(
                'filterEnd'
            ).value;


        return allExpenses.filter(
            function (expense) {

                const name =
                    String(
                        expense.name || ''
                    )
                    .toLowerCase();


                const categoryMatch =
                    !category ||
                    expense.category ===
                    category;


                const searchMatch =
                    !search ||
                    name.includes(
                        search
                    );


                const startMatch =
                    !start ||
                    expense.expense_date >=
                    start;


                const endMatch =
                    !end ||
                    expense.expense_date <=
                    end;


                return (
                    searchMatch &&
                    categoryMatch &&
                    startMatch &&
                    endMatch
                );

            }
        );
    }


    /* ========================================================
       RENDER
    ======================================================== */

    function renderExpenses() {

        const rows =
            getFilteredExpenses();


        let html = '';

        let filteredTotal =
            0;


        rows.forEach(
            function (expense) {

                const amount =
                    Number(
                        expense.amount || 0
                    );


                filteredTotal +=
                    amount;


                html += `

                    <tr>

                        <td class="fw-bold">

                            ${escapeHtml(
                                expense.name
                            )}

                        </td>


                        <td>

                            <span
                                class="badge
                                       expense-category"
                            >

                                ${escapeHtml(
                                    expense.category ||
                                    'General'
                                )}

                            </span>

                        </td>


                        <td class="text-muted">

                            ${escapeHtml(
                                expense.expense_date
                            )}

                        </td>


                        <td class="text-end">

                            <span
                                class="expense-amount"
                            >

                                K${money(amount)}

                            </span>

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
                                data-id="${Number(
                                    expense.id
                                )}"
                                title="Delete expense"
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
                        class="expense-empty"
                    >

                        <i
                            class="fas
                                   fa-receipt"
                        ></i>

                        No expenses found.

                    </td>

                </tr>

            `;
        }


        document.getElementById(
            'expenses_list'
        ).innerHTML =
            html;


        /*
         * When filters are active, display
         * the filtered total.
         */

        document.getElementById(
            'total_display'
        ).textContent =
            'K' +
            money(
                filteredTotal
            );


        document.getElementById(
            'count_display'
        ).textContent =
            rows.length;
    }


    /* ========================================================
       LOAD
    ======================================================== */

    function loadExpenses() {

        const table =
            document.getElementById(
                'expenses_list'
            );


        table.innerHTML = `

            <tr>

                <td
                    colspan="5"
                    class="text-center
                           py-4"
                >

                    <i
                        class="fas
                               fa-spinner
                               fa-spin
                               me-2"
                    ></i>

                    Loading expenses...

                </td>

            </tr>

        `;


        fetch(
            'actions/expenses.php?action=list',
            {
                method: 'GET',

                credentials: 'same-origin',

                cache: 'no-store',

                headers: {
                    'Accept':
                        'application/json'
                }
            }
        )

        .then(
            function (response) {

                return response.text()
                    .then(
                        function (text) {

                            let data;

                            try {

                                data =
                                    JSON.parse(
                                        text
                                    );

                            } catch (error) {

                                console.error(
                                    'Invalid expense JSON:',
                                    text
                                );

                                throw new Error(
                                    'The expense server returned an invalid response.'
                                );
                            }


                            if (
                                !response.ok
                            ) {

                                throw new Error(
                                    data.message ||
                                    'Unable to load expenses.'
                                );
                            }


                            return data;

                        }
                    );

            }
        )

        .then(
            function (data) {

                if (
                    data.status !==
                    'success'
                ) {

                    throw new Error(
                        data.message ||
                        'Unable to load expenses.'
                    );
                }


                allExpenses =
                    Array.isArray(
                        data.expenses
                    )
                    ? data.expenses
                    : [];


                /*
                 * Initial totals from database.
                 */

                document.getElementById(
                    'total_display'
                ).textContent =
                    'K' +
                    money(
                        data.total
                    );


                document.getElementById(
                    'month_display'
                ).textContent =
                    'K' +
                    money(
                        data.month_total
                    );


                document.getElementById(
                    'count_display'
                ).textContent =
                    data.count ||
                    allExpenses.length;


                renderExpenses();

            }
        )

        .catch(
            function (error) {

                console.error(
                    'Expense loading error:',
                    error
                );


                table.innerHTML = `

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
                                       me-2"
                            ></i>

                            ${escapeHtml(
                                error.message ||
                                'Unable to load expenses.'
                            )}

                        </td>

                    </tr>

                `;

            }
        );
    }


    /* ========================================================
       ADD EXPENSE
    ======================================================== */

    document.getElementById(
        'expenseForm'
    ).addEventListener(
        'submit',
        function (event) {

            event.preventDefault();


            const button =
                document.getElementById(
                    'submitBtn'
                );


            const formData =
                new FormData(this);


            formData.append(
                'action',
                'add'
            );


            button.disabled =
                true;


            button.innerHTML = `

                <i
                    class="fas
                           fa-spinner
                           fa-spin
                           me-1"
                ></i>

                SAVING...

            `;


            fetch(
                'actions/expenses.php',
                {
                    method: 'POST',

                    body: formData,

                    credentials: 'same-origin',

                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            )

            .then(
                function (response) {

                    return response.text()
                        .then(
                            function (text) {

                                let data;

                                try {

                                    data =
                                        JSON.parse(
                                            text
                                        );

                                } catch (error) {

                                    console.error(
                                        text
                                    );

                                    throw new Error(
                                        'Invalid server response while saving expense.'
                                    );
                                }


                                if (
                                    !response.ok
                                ) {

                                    throw new Error(
                                        data.message ||
                                        'Unable to save expense.'
                                    );
                                }


                                return data;

                            }
                        );

                }
            )

            .then(
                function (data) {

                    if (
                        data.status !==
                        'success'
                    ) {

                        throw new Error(
                            data.message ||
                            'Unable to save expense.'
                        );
                    }


                    document.getElementById(
                        'expenseForm'
                    ).reset();


                    document.getElementById(
                        'expenseDate'
                    ).value =
                        today();


                    loadExpenses();

                }
            )

            .catch(
                function (error) {

                    console.error(
                        'Save expense error:',
                        error
                    );


                    alert(
                        error.message ||
                        'Unable to save expense.'
                    );

                }
            )

            .finally(
                function () {

                    button.disabled =
                        false;


                    button.innerHTML = `

                        <i
                            class="fas
                                   fa-save
                                   me-1"
                        ></i>

                        SAVE EXPENSE

                    `;

                }
            );

        }
    );


    /* ========================================================
       DELETE
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


            const id =
                Number(
                    button.getAttribute(
                        'data-id'
                    )
                );


            if (!id) {
                return;
            }


            if (
                !confirm(
                    'Delete this expense record? This action cannot be undone.'
                )
            ) {

                return;
            }


            const formData =
                new FormData();


            formData.append(
                'action',
                'delete'
            );


            formData.append(
                'id',
                String(id)
            );


            button.disabled =
                true;


            fetch(
                'actions/expenses.php',
                {
                    method: 'POST',

                    body: formData,

                    credentials: 'same-origin',

                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            )

            .then(
                function (response) {

                    return response.json();

                }
            )

            .then(
                function (data) {

                    if (
                        data.status !==
                        'success'
                    ) {

                        throw new Error(
                            data.message ||
                            'Unable to delete expense.'
                        );
                    }


                    loadExpenses();

                }
            )

            .catch(
                function (error) {

                    console.error(
                        error
                    );


                    alert(
                        error.message ||
                        'Unable to delete expense.'
                    );


                    button.disabled =
                        false;

                }
            );

        }
    );


    /* ========================================================
       CLEAR MONTH / YEAR
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


            const action =
                type === 'month'
                    ? 'clear_month'
                    : 'clear_year';


            const label =
                type === 'month'
                    ? 'this month'
                    : 'this year';


            if (
                !confirm(
                    'Clear all expense records for ' +
                    label +
                    ' for this branch? This cannot be undone.'
                )
            ) {

                return;
            }


            const formData =
                new FormData();


            formData.append(
                'action',
                action
            );


            fetch(
                'actions/expenses.php',
                {
                    method: 'POST',

                    body: formData,

                    credentials: 'same-origin',

                    headers: {
                        'Accept':
                            'application/json'
                    }
                }
            )

            .then(
                function (response) {

                    return response.json();

                }
            )

            .then(
                function (data) {

                    if (
                        data.status !==
                        'success'
                    ) {

                        throw new Error(
                            data.message ||
                            'Unable to clear expenses.'
                        );
                    }


                    loadExpenses();

                }
            )

            .catch(
                function (error) {

                    console.error(
                        error
                    );


                    alert(
                        error.message ||
                        'Unable to clear expenses.'
                    );

                }
            );

        }
    );


    /* ========================================================
       FILTER EVENTS
    ======================================================== */

    document.getElementById(
        'expenseSearch'
    ).addEventListener(
        'input',
        renderExpenses
    );


    document.getElementById(
        'filterCategory'
    ).addEventListener(
        'change',
        renderExpenses
    );


    document.getElementById(
        'filterStart'
    ).addEventListener(
        'change',
        renderExpenses
    );


    document.getElementById(
        'filterEnd'
    ).addEventListener(
        'change',
        renderExpenses
    );


    /* ========================================================
       DEFAULT DATE FILTER
    ======================================================== */

    const currentDate =
        today();


    document.getElementById(
        'filterStart'
    ).value =
        currentDate.substring(
            0,
            8
        ) + '01';


    document.getElementById(
        'filterEnd'
    ).value =
        currentDate;


    /* ========================================================
       INITIAL LOAD
    ======================================================== */

    loadExpenses();


})();

</script>
