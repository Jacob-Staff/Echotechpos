<?php
/**
 * ============================================================
 * EchoTech POS
 * EXPENSE MANAGEMENT
 * ============================================================
 *
 * POS USER EXPENSE PAGE
 *
 * Uses the existing EchoTech POS template:
 *
 *     includes/head.php
 *     includes/header.php
 *     includes/aside.php
 *     includes/footer.php
 *
 * Backend:
 *
 *     actions/expenses.php
 *
 * Supported backend actions:
 *
 *     list
 *     add
 *     delete
 *     clear_month
 *     clear_year
 *
 * Business timezone:
 *
 *     Africa/Lusaka
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
   DATABASE / AUTH
============================================================ */

require_once "../includes/conn.php";
require_once "../includes/auth.php";


/* ============================================================
   SESSION / TENANT
============================================================ */

$pharmacy_id = (int)(
    $_SESSION['pharmacy_id'] ?? 0
);

$branch_id = (int)(
    $_SESSION['branch_id'] ?? 0
);


if (
    $pharmacy_id <= 0 ||
    $branch_id <= 0
) {

    header(
        "Location: ../login.php?error=session_expired"
    );

    exit;
}


/* ============================================================
   PHARMACY NAME
============================================================ */

$pharmacy_name = "EchoTech POS";

$stmt = $conn->prepare("
    SELECT name
    FROM pharmacies
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "i",
        $pharmacy_id
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        if (!empty($row['name'])) {

            $pharmacy_name =
                $row['name'];
        }
    }

    $stmt->close();
}


/* ============================================================
   BRANCH NAME
============================================================ */

$branch_name = "Main Branch";

$stmt = $conn->prepare("
    SELECT branch_name
    FROM branches
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param(
        "i",
        $branch_id
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        if (!empty($row['branch_name'])) {

            $branch_name =
                $row['branch_name'];
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


/* ============================================================
   EXISTING ECHOTECH TEMPLATE
============================================================ */

require_once "../includes/head.php";

require_once "../includes/header.php";

require_once "../includes/aside.php";

?>

<style>

/* ============================================================
   EXPENSE PAGE
============================================================ */

.expenses-page {

    background:
        #f4f6f9;

    min-height:
        calc(100vh - 70px);

    padding:
        1.25rem;

    color:
        #1e293b;
}


/* ============================================================
   PAGE TITLE
============================================================ */

.expense-title-wrap {

    display:
        flex;

    align-items:
        center;

    gap:
        12px;
}


.expense-title-icon {

    width:
        44px;

    height:
        44px;

    border-radius:
        12px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    background:
        rgba(13, 110, 253, .10);

    color:
        #0d6efd;

    font-size:
        18px;
}


/* ============================================================
   CARDS
============================================================ */

.expense-card {

    background:
        #ffffff;

    border:
        1px solid #e2e8f0;

    border-radius:
        14px;

    box-shadow:
        0 2px 8px
        rgba(15, 23, 42, .04);

    overflow:
        hidden;
}


.expense-card-header {

    background:
        #ffffff;

    border-bottom:
        1px solid #e2e8f0;

    padding:
        1rem;
}


/* ============================================================
   SUMMARY CARDS
============================================================ */

.expense-summary-card {

    border:
        0;

    border-radius:
        14px;

    background:
        #ffffff;

    min-height:
        115px;

    box-shadow:
        0 2px 8px
        rgba(15, 23, 42, .05);
}


.expense-summary-icon {

    width:
        46px;

    height:
        46px;

    border-radius:
        13px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;
}


/* ============================================================
   FORM
============================================================ */

.expenses-page
.form-control,
.expenses-page
.form-select {

    min-height:
        44px;

    border-color:
        #e2e8f0;
}


.expenses-page
.form-control:focus,
.expenses-page
.form-select:focus {

    border-color:
        #0d6efd;

    box-shadow:
        0 0 0 .2rem
        rgba(13, 110, 253, .10);
}


/* ============================================================
   FILTER BAR
============================================================ */

.expense-filter-bar {

    background:
        #f8fafc;

    border:
        1px solid #e2e8f0;

    border-radius:
        12px;

    padding:
        1rem;
}


/* ============================================================
   TABLE
============================================================ */

.expense-table {

    margin-bottom:
        0;
}


.expense-table thead th {

    background:
        #f8fafc;

    color:
        #334155;

    border-bottom:
        2px solid #e2e8f0;

    font-size:
        .78rem;

    font-weight:
        700;

    text-transform:
        uppercase;

    letter-spacing:
        .45px;

    white-space:
        nowrap;

    padding:
        13px;
}


.expense-table tbody td {

    padding:
        13px;

    border-color:
        #f1f5f9;

    vertical-align:
        middle;
}


.expense-table tbody tr {

    transition:
        background .15s ease;
}


.expense-table tbody tr:hover {

    background:
        #f8fafc;
}


/* ============================================================
   AMOUNT
============================================================ */

.expense-amount {

    color:
        #dc3545;

    font-weight:
        800;

    white-space:
        nowrap;
}


/* ============================================================
   CATEGORY
============================================================ */

.category-badge {

    background:
        #f1f5f9;

    color:
        #334155;

    border:
        1px solid #e2e8f0;

    font-weight:
        600;
}


/* ============================================================
   EMPTY / LOADING
============================================================ */

.expense-empty {

    padding:
        55px 20px;

    color:
        #64748b;
}


.expense-loading {

    padding:
        35px 20px;

    color:
        #64748b;
}


/* ============================================================
   MOBILE
============================================================ */

@media (
    max-width: 767.98px
) {

    .expenses-page {

        padding:
            .75rem;
    }


    .expense-table {

        min-width:
            720px;
    }


    .expense-title-wrap {

        align-items:
            flex-start;
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
    footer,
    .sidebar,
    .btn,
    .dropdown,
    form {

        display:
            none !important;
    }


    .expenses-page {

        padding:
            0 !important;

        background:
            #ffffff !important;
    }


    .expense-card,
    .expense-summary-card {

        box-shadow:
            none !important;

        border:
            1px solid #cccccc !important;
    }

}

</style>


<!-- ============================================================
     PAGE
============================================================ -->

<div class="page-wrapper expenses-page">

    <div class="container-fluid">


        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <div
            class="
                d-flex
                flex-column
                flex-md-row
                justify-content-between
                align-items-md-center
                gap-3
                mb-4
            "
        >

            <div>

                <div class="expense-title-wrap">

                    <div class="expense-title-icon">

                        <i
                            class="
                                fas
                                fa-receipt
                            "
                        ></i>

                    </div>


                    <div>

                        <h3
                            class="
                                fw-bold
                                mb-1
                                text-dark
                            "
                        >

                            Expenses

                        </h3>


                        <div
                            class="
                                small
                                text-muted
                            "
                        >

                            <strong>
                                <?= htmlspecialchars(
                                    $pharmacy_name
                                ) ?>
                            </strong>

                            <span class="mx-1">
                                •
                            </span>

                            <?= htmlspecialchars(
                                $branch_name
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>


            <div class="no-print">

                <button
                    type="button"
                    class="
                        btn
                        btn-outline-dark
                        fw-bold
                    "
                    onclick="window.print()"
                >

                    <i
                        class="
                            fas
                            fa-print
                            me-1
                        "
                    ></i>

                    Print

                </button>

            </div>

        </div>


        <!-- =====================================================
             SUMMARY CARDS
        ====================================================== -->

        <div
            class="
                row
                g-3
                mb-4
            "
        >


            <!-- TOTAL -->

            <div
                class="
                    col-12
                    col-md-4
                "
            >

                <div
                    class="
                        card
                        expense-summary-card
                    "
                >

                    <div
                        class="
                            card-body
                            d-flex
                            align-items-center
                            justify-content-between
                        "
                    >

                        <div>

                            <div
                                class="
                                    small
                                    text-muted
                                    fw-bold
                                    text-uppercase
                                "
                            >

                                Total Expenses

                            </div>


                            <div
                                id="total_display"
                                class="
                                    fs-3
                                    fw-bold
                                    text-danger
                                    mt-1
                                "
                            >

                                K0.00

                            </div>

                        </div>


                        <div
                            class="
                                expense-summary-icon
                            "
                            style="
                                background:
                                    rgba(220,53,69,.10);
                                color:
                                    #dc3545;
                            "
                        >

                            <i
                                class="
                                    fas
                                    fa-money-bill-wave
                                    fa-lg
                                "
                            ></i>

                        </div>

                    </div>

                </div>

            </div>


            <!-- MONTH -->

            <div
                class="
                    col-12
                    col-md-4
                "
            >

                <div
                    class="
                        card
                        expense-summary-card
                    "
                >

                    <div
                        class="
                            card-body
                            d-flex
                            align-items-center
                            justify-content-between
                        "
                    >

                        <div>

                            <div
                                class="
                                    small
                                    text-muted
                                    fw-bold
                                    text-uppercase
                                "
                            >

                                This Month

                            </div>


                            <div
                                id="month_display"
                                class="
                                    fs-3
                                    fw-bold
                                    mt-1
                                "
                                style="
                                    color:#b58100;
                                "
                            >

                                K0.00

                            </div>

                        </div>


                        <div
                            class="
                                expense-summary-icon
                            "
                            style="
                                background:
                                    rgba(255,193,7,.12);
                                color:
                                    #b58100;
                            "
                        >

                            <i
                                class="
                                    fas
                                    fa-calendar-alt
                                    fa-lg
                                "
                            ></i>

                        </div>

                    </div>

                </div>

            </div>


            <!-- RECORDS -->

            <div
                class="
                    col-12
                    col-md-4
                "
            >

                <div
                    class="
                        card
                        expense-summary-card
                    "
                >

                    <div
                        class="
                            card-body
                            d-flex
                            align-items-center
                            justify-content-between
                        "
                    >

                        <div>

                            <div
                                class="
                                    small
                                    text-muted
                                    fw-bold
                                    text-uppercase
                                "
                            >

                                Records

                            </div>


                            <div
                                id="count_display"
                                class="
                                    fs-3
                                    fw-bold
                                    text-primary
                                    mt-1
                                "
                            >

                                0

                            </div>

                        </div>


                        <div
                            class="
                                expense-summary-icon
                            "
                            style="
                                background:
                                    rgba(13,110,253,.10);
                                color:
                                    #0d6efd;
                            "
                        >

                            <i
                                class="
                                    fas
                                    fa-list-ol
                                    fa-lg
                                "
                            ></i>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             MAIN CONTENT
        ====================================================== -->

        <div class="row g-4">


            <!-- =================================================
                 ADD EXPENSE
            ================================================== -->

            <div
                class="
                    col-12
                    col-lg-4
                "
            >

                <div
                    class="
                        card
                        expense-card
                    "
                >

                    <div
                        class="
                            expense-card-header
                        "
                    >

                        <h5
                            class="
                                fw-bold
                                mb-1
                            "
                        >

                            <i
                                class="
                                    fas
                                    fa-plus-circle
                                    text-primary
                                    me-2
                                "
                            ></i>

                            Record Expense

                        </h5>


                        <div
                            class="
                                small
                                text-muted
                            "
                        >

                            Record a business expense
                            for this branch.

                        </div>

                    </div>


                    <div
                        class="
                            card-body
                            p-3
                            p-lg-4
                        "
                    >

                        <form
                            id="expenseForm"
                            autocomplete="off"
                        >


                            <!-- DESCRIPTION -->

                            <div class="mb-3">

                                <label
                                    for="expenseName"
                                    class="
                                        form-label
                                        fw-semibold
                                    "
                                >

                                    Description

                                </label>


                                <input
                                    type="text"
                                    id="expenseName"
                                    name="name"
                                    class="form-control"
                                    maxlength="150"
                                    placeholder="
                                        e.g. Electricity bill
                                    "
                                    required
                                >

                            </div>


                            <!-- AMOUNT -->

                            <div class="mb-3">

                                <label
                                    for="expenseAmount"
                                    class="
                                        form-label
                                        fw-semibold
                                    "
                                >

                                    Amount (K)

                                </label>


                                <div
                                    class="input-group"
                                >

                                    <span
                                        class="
                                            input-group-text
                                            fw-bold
                                        "
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
                                    for="expenseCategory"
                                    class="
                                        form-label
                                        fw-semibold
                                    "
                                >

                                    Category

                                </label>


                                <select
                                    id="expenseCategory"
                                    name="category"
                                    class="form-select"
                                >

                                    <?php
                                    foreach (
                                        $categories
                                        as $category
                                    ):
                                    ?>

                                        <option
                                            value="<?=
                                                htmlspecialchars(
                                                    $category
                                                )
                                            ?>"
                                        >

                                            <?= htmlspecialchars(
                                                $category
                                            ) ?>

                                        </option>

                                    <?php
                                    endforeach;
                                    ?>

                                </select>

                            </div>


                            <!-- DATE -->

                            <div class="mb-3">

                                <label
                                    for="expenseDate"
                                    class="
                                        form-label
                                        fw-semibold
                                    "
                                >

                                    Expense Date

                                </label>


                                <input
                                    type="date"
                                    id="expenseDate"
                                    name="date"
                                    class="form-control"
                                    value="<?= date('Y-m-d') ?>"
                                    required
                                >

                            </div>


                            <!-- SAVE -->

                            <button
                                type="submit"
                                id="submitBtn"
                                class="
                                    btn
                                    btn-primary
                                    w-100
                                    fw-bold
                                    py-2
                                "
                            >

                                <i
                                    class="
                                        fas
                                        fa-save
                                        me-1
                                    "
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

            <div
                class="
                    col-12
                    col-lg-8
                "
            >

                <div
                    class="
                        card
                        expense-card
                    "
                >

                    <div
                        class="
                            expense-card-header
                        "
                    >

                        <div
                            class="
                                d-flex
                                flex-column
                                flex-md-row
                                justify-content-between
                                align-items-md-center
                                gap-3
                            "
                        >

                            <div>

                                <h5
                                    class="
                                        fw-bold
                                        mb-1
                                    "
                                >

                                    <i
                                        class="
                                            fas
                                            fa-history
                                            text-primary
                                            me-2
                                        "
                                    ></i>

                                    Expense History

                                </h5>


                                <div
                                    class="
                                        small
                                        text-muted
                                    "
                                >

                                    Branch expense records

                                </div>

                            </div>


                            <!-- CLEAR -->

                            <div
                                class="
                                    dropdown
                                    no-print
                                "
                            >

                                <button
                                    class="
                                        btn
                                        btn-sm
                                        btn-outline-danger
                                        dropdown-toggle
                                        fw-bold
                                    "
                                    type="button"
                                    data-bs-toggle="dropdown"
                                >

                                    <i
                                        class="
                                            fas
                                            fa-trash-alt
                                            me-1
                                        "
                                    ></i>

                                    CLEAR RECORDS

                                </button>


                                <ul
                                    class="
                                        dropdown-menu
                                        dropdown-menu-end
                                    "
                                >

                                    <li>

                                        <a
                                            href="#"
                                            class="
                                                dropdown-item
                                                clear-btn
                                            "
                                            data-type="month"
                                        >

                                            <i
                                                class="
                                                    fas
                                                    fa-calendar
                                                    me-2
                                                "
                                            ></i>

                                            Clear This Month

                                        </a>

                                    </li>


                                    <li>

                                        <a
                                            href="#"
                                            class="
                                                dropdown-item
                                                clear-btn
                                                text-danger
                                            "
                                            data-type="year"
                                        >

                                            <i
                                                class="
                                                    fas
                                                    fa-calendar-times
                                                    me-2
                                                "
                                            ></i>

                                            Clear This Year

                                        </a>

                                    </li>

                                </ul>

                            </div>

                        </div>

                    </div>


                    <div
                        class="
                            card-body
                            p-3
                        "
                    >


                        <!-- FILTER BAR -->

                        <div
                            class="
                                expense-filter-bar
                                mb-3
                                no-print
                            "
                        >

                            <div class="row g-2">


                                <!-- SEARCH -->

                                <div
                                    class="
                                        col-12
                                        col-md-5
                                    "
                                >

                                    <label
                                        class="
                                            form-label
                                            small
                                            fw-semibold
                                            mb-1
                                        "
                                    >

                                        Search

                                    </label>


                                    <input
                                        type="search"
                                        id="expenseSearch"
                                        class="form-control"
                                        placeholder="
                                            Description...
                                        "
                                        autocomplete="off"
                                    >

                                </div>


                                <!-- CATEGORY -->

                                <div
                                    class="
                                        col-12
                                        col-md-3
                                    "
                                >

                                    <label
                                        class="
                                            form-label
                                            small
                                            fw-semibold
                                            mb-1
                                        "
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
                                                value="<?=
                                                    htmlspecialchars(
                                                        $category
                                                    )
                                                ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $category
                                                ) ?>

                                            </option>

                                        <?php
                                        endforeach;
                                        ?>

                                    </select>

                                </div>


                                <!-- FROM -->

                                <div
                                    class="
                                        col-12
                                        col-md-2
                                    "
                                >

                                    <label
                                        class="
                                            form-label
                                            small
                                            fw-semibold
                                            mb-1
                                        "
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
                                    class="
                                        col-12
                                        col-md-2
                                    "
                                >

                                    <label
                                        class="
                                            form-label
                                            small
                                            fw-semibold
                                            mb-1
                                        "
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
                                class="
                                    table
                                    table-hover
                                    expense-table
                                    align-middle
                                    mb-0
                                "
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
                                            class="
                                                text-center
                                                no-print
                                            "
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
                                            class="
                                                text-center
                                                expense-loading
                                            "
                                        >

                                            <i
                                                class="
                                                    fas
                                                    fa-spinner
                                                    fa-spin
                                                    me-2
                                                "
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


<!-- ============================================================
     MESSAGE MODAL
============================================================ -->

<div
    class="
        modal
        fade
    "
    id="messageModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="
            modal-dialog
            modal-dialog-centered
        "
    >

        <div class="modal-content">

            <div class="modal-header">

                <h5
                    id="messageTitle"
                    class="
                        modal-title
                        fw-bold
                    "
                >
                    Message
                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <p
                    id="messageBody"
                    class="mb-0"
                ></p>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-dismiss="modal"
                >

                    OK

                </button>

            </div>

        </div>

    </div>

</div>


<!-- ============================================================
     EXPENSE JAVASCRIPT
============================================================ -->

<script>

/* ============================================================
   ECHOTECH EXPENSE MANAGEMENT
============================================================ */

(function () {

    'use strict';


    let allExpenses = [];


    /* ========================================================
       LOCAL ZAMBIA DATE
    ======================================================== */

    function localDateISO(date) {

        const year =
            date.getFullYear();

        const month =
            String(
                date.getMonth() + 1
            ).padStart(2, '0');

        const day =
            String(
                date.getDate()
            ).padStart(2, '0');

        return (
            year +
            '-' +
            month +
            '-' +
            day
        );
    }


    function todayISO() {

        return localDateISO(
            new Date()
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
                minimumFractionDigits:
                    2,

                maximumFractionDigits:
                    2
            }
        );
    }


    /* ========================================================
       MESSAGE
    ======================================================== */

    function showMessage(
        title,
        message
    ) {

        $('#messageTitle')
            .text(title);

        $('#messageBody')
            .text(message);


        const modal =
            bootstrap.Modal
                .getOrCreateInstance(
                    document.getElementById(
                        'messageModal'
                    )
                );


        modal.show();
    }


    /* ========================================================
       LOAD EXPENSES
    ======================================================== */

    function loadExpenses() {

        $('#expenses_list').html(
            `
            <tr>

                <td
                    colspan="5"
                    class="
                        text-center
                        expense-loading
                    "
                >

                    <i
                        class="
                            fas
                            fa-spinner
                            fa-spin
                            me-2
                        "
                    ></i>

                    Loading expenses...

                </td>

            </tr>
            `
        );


        $.ajax({

            url:
                'actions/expenses.php',

            method:
                'GET',

            dataType:
                'json',

            data: {

                action:
                    'list'
            },


            success:
                function (response) {

                    if (
                        !response ||
                        response.status !==
                        'success'
                    ) {

                        $('#expenses_list').html(
                            `
                            <tr>

                                <td
                                    colspan="5"
                                    class="
                                        text-center
                                        text-danger
                                        py-4
                                    "
                                >

                                    ${
                                        escapeHtml(
                                            response?.message ||
                                            'Unable to load expenses.'
                                        )
                                    }

                                </td>

                            </tr>
                            `
                        );

                        return;
                    }


                    allExpenses =
                        Array.isArray(
                            response.expenses
                        )
                            ? response.expenses
                            : [];


                    renderExpenses();


                    updateSummary(
                        response
                    );
                },


            error:
                function (xhr) {

                    console.error(
                        'Expense load error:',
                        xhr.responseText
                    );


                    $('#expenses_list').html(
                        `
                        <tr>

                            <td
                                colspan="5"
                                class="
                                    text-center
                                    text-danger
                                    py-4
                                "
                            >

                                Unable to load expenses.

                            </td>

                        </tr>
                        `
                    );
                }

        });
    }


    /* ========================================================
       FILTER
    ======================================================== */

    function getFilteredExpenses() {

        const search =
            String(
                $('#expenseSearch')
                    .val() || ''
            )
            .trim()
            .toLowerCase();


        const category =
            $('#filterCategory')
                .val();


        const start =
            $('#filterStart')
                .val();


        const end =
            $('#filterEnd')
                .val();


        return allExpenses.filter(
            function (expense) {

                const name =
                    String(
                        expense.name || ''
                    )
                    .toLowerCase();


                const expenseCategory =
                    String(
                        expense.category || ''
                    );


                const expenseDate =
                    String(
                        expense.expense_date || ''
                    );


                if (
                    search &&
                    !name.includes(
                        search
                    )
                ) {

                    return false;
                }


                if (
                    category &&
                    expenseCategory !==
                    category
                ) {

                    return false;
                }


                if (
                    start &&
                    expenseDate < start
                ) {

                    return false;
                }


                if (
                    end &&
                    expenseDate > end
                ) {

                    return false;
                }


                return true;
            }
        );
    }


    /* ========================================================
       RENDER
    ======================================================== */

    function renderExpenses() {

        const expenses =
            getFilteredExpenses();


        if (!expenses.length) {

            $('#expenses_list').html(
                `
                <tr>

                    <td
                        colspan="5"
                        class="
                            text-center
                            expense-empty
                        "
                    >

                        <i
                            class="
                                fas
                                fa-receipt
                                fa-2x
                                d-block
                                mb-3
                            "
                        ></i>

                        No expenses found.

                    </td>

                </tr>
                `
            );


            updateFilteredSummary(
                expenses
            );

            return;
        }


        let html = '';


        expenses.forEach(
            function (expense) {

                const id =
                    Number(
                        expense.id || 0
                    );


                html +=
                    `
                    <tr>

                        <td>

                            <div
                                class="
                                    fw-bold
                                    text-dark
                                "
                            >

                                ${
                                    escapeHtml(
                                        expense.name
                                    )
                                }

                            </div>

                        </td>


                        <td>

                            <span
                                class="
                                    badge
                                    category-badge
                                    rounded-pill
                                "
                            >

                                ${
                                    escapeHtml(
                                        expense.category ||
                                        'General'
                                    )
                                }

                            </span>

                        </td>


                        <td>

                            <span
                                class="
                                    text-muted
                                "
                            >

                                ${
                                    escapeHtml(
                                        expense.expense_date
                                    )
                                }

                            </span>

                        </td>


                        <td
                            class="
                                text-end
                                expense-amount
                            "
                        >

                            K${
                                money(
                                    expense.amount
                                )
                            }

                        </td>


                        <td
                            class="
                                text-center
                                no-print
                            "
                        >

                            <button
                                type="button"
                                class="
                                    btn
                                    btn-sm
                                    btn-outline-danger
                                    delete-expense
                                "
                                data-id="${id}"
                                title="Delete expense"
                            >

                                <i
                                    class="
                                        fas
                                        fa-trash-alt
                                    "
                                ></i>

                            </button>

                        </td>

                    </tr>
                    `;
            }
        );


        $('#expenses_list')
            .html(html);


        updateFilteredSummary(
            expenses
        );
    }


    /* ========================================================
       SERVER SUMMARY
    ======================================================== */

    function updateSummary(
        response
    ) {

        $('#total_display').text(
            'K' +
            money(
                response.total
            )
        );


        $('#month_display').text(
            'K' +
            money(
                response.month_total
            )
        );


        $('#count_display').text(
            Number(
                response.count || 0
            )
        );
    }


    /* ========================================================
       FILTERED SUMMARY
    ======================================================== */

    function updateFilteredSummary(
        expenses
    ) {

        let total =
            0;


        expenses.forEach(
            function (expense) {

                total +=
                    Number(
                        expense.amount || 0
                    );
            }
        );


        $('#total_display').text(
            'K' +
            money(total)
        );


        $('#count_display').text(
            expenses.length
        );


        /*
         * Month total always represents the current
         * Zambia business month.
         */

        const currentMonth =
            todayISO().substring(
                0,
                7
            );


        let monthTotal =
            0;


        allExpenses.forEach(
            function (expense) {

                const date =
                    String(
                        expense.expense_date ||
                        ''
                    );


                if (
                    date.substring(
                        0,
                        7
                    ) ===
                    currentMonth
                ) {

                    monthTotal +=
                        Number(
                            expense.amount || 0
                        );
                }
            }
        );


        $('#month_display').text(
            'K' +
            money(monthTotal)
        );
    }


    /* ========================================================
       ADD EXPENSE
    ======================================================== */

    $('#expenseForm').on(
        'submit',
        function (event) {

            event.preventDefault();


            const form =
                this;


            const button =
                $('#submitBtn');


            const name =
                String(
                    $('#expenseName')
                        .val() || ''
                )
                .trim();


            const amount =
                Number(
                    $('#expenseAmount')
                        .val()
                );


            const category =
                $('#expenseCategory')
                    .val();


            const date =
                $('#expenseDate')
                    .val();


            if (!name) {

                showMessage(
                    'Missing Description',
                    'Please enter an expense description.'
                );

                return;
            }


            if (
                !Number.isFinite(
                    amount
                ) ||
                amount <= 0
            ) {

                showMessage(
                    'Invalid Amount',
                    'Please enter an amount greater than zero.'
                );

                return;
            }


            if (!date) {

                showMessage(
                    'Missing Date',
                    'Please select the expense date.'
                );

                return;
            }


            button
                .prop(
                    'disabled',
                    true
                )
                .html(
                    '<i class="fas fa-spinner fa-spin me-1"></i> SAVING...'
                );


            $.ajax({

                url:
                    'actions/expenses.php',

                method:
                    'POST',

                dataType:
                    'json',

                data: {

                    action:
                        'add',

                    name:
                        name,

                    amount:
                        amount,

                    category:
                        category,

                    date:
                        date
                },


                success:
                    function (response) {

                        if (
                            response &&
                            response.status ===
                            'success'
                        ) {

                            form.reset();


                            $('#expenseDate')
                                .val(
                                    todayISO()
                                );


                            loadExpenses();


                            showMessage(
                                'Expense Saved',
                                'The expense was recorded successfully.'
                            );

                        } else {

                            showMessage(
                                'Unable to Save',
                                response?.message ||
                                'The expense could not be saved.'
                            );
                        }
                    },


                error:
                    function (xhr) {

                        console.error(
                            'Expense save error:',
                            xhr.responseText
                        );


                        showMessage(
                            'Server Error',
                            'The expense could not be saved.'
                        );
                    },


                complete:
                    function () {

                        button
                            .prop(
                                'disabled',
                                false
                            )
                            .html(
                                '<i class="fas fa-save me-1"></i> SAVE EXPENSE'
                            );
                    }

            });
        }
    );


    /* ========================================================
       DELETE
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


            if (
                !confirm(
                    'Delete this expense record? This action cannot be undone.'
                )
            ) {

                return;
            }


            $.ajax({

                url:
                    'actions/expenses.php',

                method:
                    'POST',

                dataType:
                    'json',

                data: {

                    action:
                        'delete',

                    id:
                        id
                },


                success:
                    function (response) {

                        if (
                            response &&
                            response.status ===
                            'success'
                        ) {

                            loadExpenses();

                        } else {

                            showMessage(
                                'Unable to Delete',
                                response?.message ||
                                'The expense could not be deleted.'
                            );
                        }
                    },


                error:
                    function (xhr) {

                        console.error(
                            'Expense delete error:',
                            xhr.responseText
                        );


                        showMessage(
                            'Server Error',
                            'The expense could not be deleted.'
                        );
                    }

            });
        }
    );


    /* ========================================================
       CLEAR MONTH / YEAR
    ======================================================== */

    $(document).on(
        'click',
        '.clear-btn',
        function (event) {

            event.preventDefault();


            const type =
                $(this).data('type');


            if (
                type !== 'month' &&
                type !== 'year'
            ) {

                return;
            }


            const period =
                type === 'month'
                    ? 'this month'
                    : 'this year';


            if (
                !confirm(
                    'Clear all expense records for ' +
                    period +
                    ' for this branch? This action cannot be undone.'
                )
            ) {

                return;
            }


            $.ajax({

                url:
                    'actions/expenses.php',

                method:
                    'POST',

                dataType:
                    'json',

                data: {

                    action:
                        type === 'month'
                            ? 'clear_month'
                            : 'clear_year'
                },


                success:
                    function (response) {

                        if (
                            response &&
                            response.status ===
                            'success'
                        ) {

                            loadExpenses();


                            showMessage(
                                'Expenses Cleared',
                                response.message ||
                                'The expense records were cleared.'
                            );

                        } else {

                            showMessage(
                                'Unable to Clear',
                                response?.message ||
                                'The expense records could not be cleared.'
                            );
                        }
                    },


                error:
                    function (xhr) {

                        console.error(
                            'Expense clear error:',
                            xhr.responseText
                        );


                        showMessage(
                            'Server Error',
                            'The expense records could not be cleared.'
                        );
                    }

            });
        }
    );


    /* ========================================================
       LIVE FILTERS
    ======================================================== */

    $('#expenseSearch').on(
        'input',
        function () {

            renderExpenses();
        }
    );


    $('#filterCategory').on(
        'change',
        function () {

            renderExpenses();
        }
    );


    $('#filterStart, #filterEnd').on(
        'change',
        function () {

            renderExpenses();
        }
    );


    /* ========================================================
       INITIAL FILTER RANGE
    ======================================================== */

    const today =
        todayISO();


    const monthStart =
        today.substring(
            0,
            8
        ) +
        '01';


    $('#filterStart')
        .val(
            monthStart
        );


    $('#filterEnd')
        .val(
            today
        );


    /* ========================================================
       INITIAL LOAD
    ======================================================== */

    loadExpenses();

})();

</script>

<?php

/*
|--------------------------------------------------------------------------
| EXISTING ECHOTECH FOOTER
|--------------------------------------------------------------------------
*/

require_once "../includes/footer.php";

?>
