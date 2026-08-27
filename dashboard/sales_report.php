<?php
/**
 * ============================================================
 * EchoTech POS
 * SALES & ANALYTICS REPORT
 * ============================================================
 *
 * Business timezone:
 *     Africa/Lusaka (UTC+02:00)
 *
 * Data endpoint:
 *     actions/fetch_sales.php
 *
 * IMPORTANT:
 *     All date handling below is LOCAL BUSINESS DATE handling.
 *
 *     DO NOT use:
 *         toISOString()
 *
 *     because that converts the browser date to UTC and can
 *     move a Zambia date backwards by one day.
 * ============================================================
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| BUSINESS TIMEZONE
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Lusaka');


/*
|--------------------------------------------------------------------------
| AUTH / DATABASE
|--------------------------------------------------------------------------
*/

require_once "../includes/conn.php";
require_once "../includes/auth.php";


/*
|--------------------------------------------------------------------------
| SESSION CONTEXT
|--------------------------------------------------------------------------
*/

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

    exit();
}


/*
|--------------------------------------------------------------------------
| DEFAULT DISPLAY VALUES
|--------------------------------------------------------------------------
*/

$display_pharmacy_name = "Echo Prime Ltd";

$display_branch_name = "Main Branch";


/*
|--------------------------------------------------------------------------
| FETCH PHARMACY NAME
|--------------------------------------------------------------------------
*/

$pharm_query = $conn->prepare(
    "SELECT name
     FROM pharmacies
     WHERE id = ?
     LIMIT 1"
);

if ($pharm_query) {

    $pharm_query->bind_param(
        "i",
        $pharmacy_id
    );

    $pharm_query->execute();

    $pharm_res =
        $pharm_query->get_result();

    if ($row = $pharm_res->fetch_assoc()) {

        if (
            isset($row['name']) &&
            $row['name'] !== ''
        ) {
            $display_pharmacy_name =
                $row['name'];
        }
    }

    $pharm_query->close();
}


/*
|--------------------------------------------------------------------------
| FETCH BRANCH NAME
|--------------------------------------------------------------------------
*/

$branch_query = $conn->prepare(
    "SELECT branch_name
     FROM branches
     WHERE id = ?
     LIMIT 1"
);

if ($branch_query) {

    $branch_query->bind_param(
        "i",
        $branch_id
    );

    $branch_query->execute();

    $branch_res =
        $branch_query->get_result();

    if ($row = $branch_res->fetch_assoc()) {

        if (
            isset($row['branch_name']) &&
            $row['branch_name'] !== ''
        ) {
            $display_branch_name =
                $row['branch_name'];
        }
    }

    $branch_query->close();
}


/*
|--------------------------------------------------------------------------
| FETCH CATEGORIES
|--------------------------------------------------------------------------
|
| Only categories belonging to this pharmacy.
|--------------------------------------------------------------------------
*/

$cat_options = [];


$cat_stmt = $conn->prepare(
    "SELECT DISTINCT category
     FROM store_items
     WHERE pharmacy_id = ?
       AND category IS NOT NULL
       AND category != ''
     ORDER BY category ASC"
);


if ($cat_stmt) {

    $cat_stmt->bind_param(
        "i",
        $pharmacy_id
    );

    $cat_stmt->execute();

    $cat_res =
        $cat_stmt->get_result();

    while (
        $c_row =
        $cat_res->fetch_assoc()
    ) {

        if (
            isset($c_row['category']) &&
            $c_row['category'] !== ''
        ) {

            $cat_options[] =
                $c_row['category'];
        }
    }

    $cat_stmt->close();
}


/*
|--------------------------------------------------------------------------
| HEAD
|--------------------------------------------------------------------------
*/

require_once "../includes/head.php";

?>

<style>

/* ============================================================
   SALES REPORT
============================================================ */

.sales-report-wrapper {

    background-color: #f4f6f9 !important;

    min-height:
        calc(100vh - 70px);

    padding: 1.25rem;

    color: #212529;
}


/* ============================================================
   KPI CARDS
============================================================ */

.kpi-card {

    border-radius: 10px;

    border: none;

    box-shadow:
        0 2px 4px
        rgba(0, 0, 0, 0.03);

    transition:
        transform 0.2s ease-in-out;
}


.kpi-card:hover {

    transform:
        translateY(-2px);
}


/* ============================================================
   CUSTOM CARDS
============================================================ */

.card-custom {

    background-color: #ffffff;

    border:
        1px solid #e2e8f0;

    border-radius: 10px;

    box-shadow:
        0 2px 4px
        rgba(0, 0, 0, 0.02);
}


/* ============================================================
   TABLE
============================================================ */

.table-custom {

    color: #334155;

    margin-bottom: 0;
}


.table-custom thead th {

    background-color: #f1f5f9;

    color: #0f172a;

    border-bottom:
        2px solid #e2e8f0;

    font-size:
        0.8rem;

    text-transform:
        uppercase;

    letter-spacing:
        0.5px;

    padding:
        12px;
}


.table-custom tbody td {

    border-bottom:
        1px solid #f1f5f9;

    vertical-align:
        middle;

    padding:
        12px;

    font-size:
        0.9rem;
}


/* ============================================================
   FILTER AREA
============================================================ */

.sales-filter-card {

    background:
        #ffffff;

    border:
        1px solid #e2e8f0;

    border-radius:
        10px;
}


/* ============================================================
   DATE PRESETS
============================================================ */

.date-preset-group {

    display:
        flex;

    flex-wrap:
        wrap;

    gap:
        0;
}


.date-preset-group .btn {

    white-space:
        nowrap;
}


/* ============================================================
   CHART CONTAINER
============================================================ */

.chart-container {

    page-break-inside:
        avoid;
}


/* ============================================================
   LOADING
============================================================ */

.sales-loading {

    min-height:
        100px;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;
}


/* ============================================================
   PRINT HEADER
============================================================ */

.print-header {

    display:
        none;
}


/* ============================================================
   PRINT
============================================================ */

@media print {

    body {

        background-color:
            #ffffff !important;

        color:
            #000000 !important;
    }


    #header,
    #aside,
    .no-print,
    nav,
    .btn,
    .input-group,
    form,
    footer {

        display:
            none !important;
    }


    .sales-report-wrapper {

        padding:
            0 !important;

        background-color:
            #ffffff !important;
    }


    .card {

        border:
            1px solid #ddd !important;

        box-shadow:
            none !important;
    }


    .table-custom {

        width:
            100% !important;

        border-collapse:
            collapse !important;
    }


    .table-custom th,
    .table-custom td {

        border:
            1px solid #ccc !important;

        padding:
            6px !important;

        font-size:
            11px !important;
    }


    .print-header {

        display:
            block !important;

        text-align:
            center;

        margin-bottom:
            20px;

        border-bottom:
            2px solid #333;

        padding-bottom:
            10px;
    }


    .chart-container {

        page-break-inside:
            avoid;
    }
}


/* ============================================================
   MOBILE
============================================================ */

@media (max-width: 767.98px) {

    .sales-report-wrapper {

        padding:
            0.75rem;
    }


    .kpi-card h2 {

        font-size:
            1.5rem;
    }


    .table-custom {

        min-width:
            980px;
    }


    .date-preset-group {

        width:
            100%;
    }


    .date-preset-group .btn {

        flex:
            1 1 auto;
    }
}

</style>


<div id="main-wrapper">


    <?php

    /*
    |--------------------------------------------------------------------------
    | HEADER / SIDEBAR
    |--------------------------------------------------------------------------
    */

    if (
        file_exists(
            "../includes/header.php"
        )
    ) {

        require_once
            "../includes/header.php";
    }


    if (
        file_exists(
            "../includes/aside.php"
        )
    ) {

        require_once
            "../includes/aside.php";
    }

    ?>


    <div
        class="page-wrapper sales-report-wrapper"
    >

        <div
            class="container-fluid p-0"
        >


            <!-- =====================================================
                 PRINT HEADER
            ====================================================== -->

            <div class="print-header">

                <h2 class="fw-bold mb-1">

                    <?= htmlspecialchars(
                        strtoupper(
                            $display_pharmacy_name
                        )
                    ) ?>

                </h2>


                <h5 class="mb-1">

                    <?= htmlspecialchars(
                        $display_branch_name
                    ) ?>

                    - Sales Report

                </h5>


                <small class="text-muted">

                    Generated on:

                    <?= date(
                        'd M Y, H:i'
                    ) ?>

                </small>

            </div>


            <!-- =====================================================
                 PAGE HEADER
            ====================================================== -->

            <div
                class="
                    d-flex
                    flex-column
                    flex-sm-row
                    justify-content-between
                    align-items-sm-center
                    mb-4
                    gap-2
                "
            >

                <div>

                    <h3
                        class="
                            fw-bold
                            text-dark
                            mb-0
                        "
                    >

                        <i
                            class="
                                fas
                                fa-chart-line
                                me-2
                                text-primary
                            "
                        ></i>

                        Sales & Analytics Report

                    </h3>


                    <span
                        class="
                            text-secondary
                            small
                        "
                    >

                        <b>

                            <?= htmlspecialchars(
                                strtoupper(
                                    $display_pharmacy_name
                                )
                            ) ?>

                        </b>

                        |

                        <?= htmlspecialchars(
                            $display_branch_name
                        ) ?>

                    </span>

                </div>


                <div class="no-print">

                    <button
                        type="button"
                        class="
                            btn
                            btn-outline-dark
                            fw-bold
                        "
                        onclick="window.print();"
                    >

                        <i
                            class="
                                fas
                                fa-print
                                me-1
                            "
                        ></i>

                        Print / Export PDF

                    </button>

                </div>

            </div>


            <!-- =====================================================
                 KPI CARDS
            ====================================================== -->

            <div
                class="
                    row
                    g-3
                    mb-4
                "
            >


                <!-- TOTAL SALES -->

                <div
                    class="
                        col-12
                        col-md-4
                    "
                >

                    <div
                        class="
                            card
                            kpi-card
                            bg-success
                            text-white
                            p-3
                        "
                    >

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-center
                            "
                        >

                            <div>

                                <span
                                    class="
                                        text-white-50
                                        small
                                        fw-bold
                                        text-uppercase
                                    "
                                >

                                    Total Sales Revenue

                                </span>


                                <h2
                                    id="total-sales"
                                    class="
                                        fw-bold
                                        mb-0
                                        mt-1
                                    "
                                >

                                    K 0.00

                                </h2>

                            </div>


                            <div
                                class="
                                    bg-white
                                    bg-opacity-25
                                    rounded-circle
                                    p-3
                                "
                            >

                                <i
                                    class="
                                        fas
                                        fa-wallet
                                        fa-2x
                                        text-white
                                    "
                                ></i>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- TOTAL ITEMS -->

                <div
                    class="
                        col-12
                        col-md-4
                    "
                >

                    <div
                        class="
                            card
                            kpi-card
                            bg-primary
                            text-white
                            p-3
                        "
                    >

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-center
                            "
                        >

                            <div>

                                <span
                                    class="
                                        text-white-50
                                        small
                                        fw-bold
                                        text-uppercase
                                    "
                                >

                                    Units Sold

                                </span>


                                <h2
                                    id="total-items"
                                    class="
                                        fw-bold
                                        mb-0
                                        mt-1
                                    "
                                >

                                    0

                                </h2>

                            </div>


                            <div
                                class="
                                    bg-white
                                    bg-opacity-25
                                    rounded-circle
                                    p-3
                                "
                            >

                                <i
                                    class="
                                        fas
                                        fa-box-open
                                        fa-2x
                                        text-white
                                    "
                                ></i>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- TOTAL INVOICES -->

                <div
                    class="
                        col-12
                        col-md-4
                    "
                >

                    <div
                        class="
                            card
                            kpi-card
                            bg-warning
                            text-dark
                            p-3
                        "
                    >

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-center
                            "
                        >

                            <div>

                                <span
                                    class="
                                        text-dark-50
                                        small
                                        fw-bold
                                        text-uppercase
                                    "
                                >

                                    Total Invoices

                                </span>


                                <h2
                                    id="total-invoices"
                                    class="
                                        fw-bold
                                        mb-0
                                        mt-1
                                    "
                                >

                                    0

                                </h2>

                            </div>


                            <div
                                class="
                                    bg-dark
                                    bg-opacity-10
                                    rounded-circle
                                    p-3
                                "
                            >

                                <i
                                    class="
                                        fas
                                        fa-file-invoice-dollar
                                        fa-2x
                                        text-dark
                                    "
                                ></i>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =====================================================
                 FILTER CONTROLS
            ====================================================== -->

            <div
                class="
                    card
                    sales-filter-card
                    p-3
                    mb-4
                    no-print
                "
            >

                <div
                    class="
                        row
                        g-3
                        align-items-end
                    "
                >


                    <!-- QUICK DATE PRESETS -->

                    <div class="col-12">

                        <label
                            class="
                                form-label
                                small
                                fw-bold
                                text-muted
                                mb-1
                            "
                        >

                            Quick Date Range

                        </label>


                        <div
                            class="
                                btn-group
                                btn-group-sm
                                date-preset-group
                            "
                            role="group"
                        >

                            <button
                                type="button"
                                class="
                                    btn
                                    btn-outline-secondary
                                "
                                onclick="
                                    setDatePreset('today')
                                "
                            >

                                Today

                            </button>


                            <button
                                type="button"
                                class="
                                    btn
                                    btn-outline-secondary
                                "
                                onclick="
                                    setDatePreset('yesterday')
                                "
                            >

                                Yesterday

                            </button>


                            <button
                                type="button"
                                class="
                                    btn
                                    btn-outline-secondary
                                "
                                onclick="
                                    setDatePreset('this_month')
                                "
                            >

                                This Month

                            </button>


                            <button
                                type="button"
                                class="
                                    btn
                                    btn-outline-secondary
                                "
                                onclick="
                                    setDatePreset('last_month')
                                "
                            >

                                Last Month

                            </button>

                        </div>

                    </div>


                    <!-- SEARCH -->

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
                                fw-bold
                                text-muted
                                mb-1
                            "
                        >

                            Search Keyword

                        </label>


                        <div
                            class="input-group"
                        >

                            <span
                                class="
                                    input-group-text
                                    bg-light
                                    border-end-0
                                "
                            >

                                <i
                                    class="
                                        fas
                                        fa-search
                                        text-muted
                                    "
                                ></i>

                            </span>


                            <input
                                type="text"
                                id="search"
                                class="
                                    form-control
                                    border-start-0
                                "
                                placeholder="
                                    Invoice No or Item Name...
                                "
                                autocomplete="off"
                            >

                        </div>

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
                                fw-bold
                                text-muted
                                mb-1
                            "
                        >

                            Category

                        </label>


                        <select
                            id="category"
                            class="form-select"
                        >

                            <option value="">

                                -- All Categories --

                            </option>


                            <?php
                            foreach (
                                $cat_options
                                as $cat
                            ):
                            ?>

                                <option
                                    value="<?=
                                        htmlspecialchars(
                                            $cat
                                        )
                                    ?>"
                                >

                                    <?= htmlspecialchars(
                                        $cat
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- START DATE -->

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
                                fw-bold
                                text-muted
                                mb-1
                            "
                        >

                            Start Date

                        </label>


                        <input
                            type="date"
                            id="startDate"
                            class="form-control"
                            value="<?=
                                date('Y-m-01')
                            ?>"
                        >

                    </div>


                    <!-- END DATE -->

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
                                fw-bold
                                text-muted
                                mb-1
                            "
                        >

                            End Date

                        </label>


                        <input
                            type="date"
                            id="endDate"
                            class="form-control"
                            value="<?=
                                date('Y-m-d')
                            ?>"
                        >

                    </div>


                    <!-- UPDATE -->

                    <div
                        class="
                            col-12
                            col-md-2
                            d-grid
                        "
                    >

                        <button
                            type="button"
                            class="
                                btn
                                btn-primary
                                fw-bold
                                py-2
                            "
                            id="filter-btn"
                        >

                            <i
                                class="
                                    fas
                                    fa-sync-alt
                                    me-1
                                "
                            ></i>

                            UPDATE

                        </button>

                    </div>

                </div>

            </div>


            <!-- =====================================================
                 ANALYTICS CHARTS
            ====================================================== -->

            <div
                class="
                    row
                    g-4
                    mb-4
                    chart-container
                "
            >


                <!-- DAILY REVENUE -->

                <div
                    class="
                        col-12
                        col-lg-8
                    "
                >

                    <div
                        class="
                            card
                            card-custom
                            p-3
                            h-100
                        "
                    >

                        <h6
                            class="
                                fw-bold
                                text-dark
                                mb-3
                            "
                        >

                            <i
                                class="
                                    fas
                                    fa-chart-area
                                    me-2
                                    text-primary
                                "
                            ></i>

                            Daily Revenue Trend

                        </h6>


                        <div
                            style="
                                position: relative;
                                height: 260px;
                            "
                        >

                            <canvas
                                id="weeklyChart"
                            ></canvas>

                        </div>

                    </div>

                </div>


                <!-- CATEGORY BREAKDOWN -->

                <div
                    class="
                        col-12
                        col-lg-4
                    "
                >

                    <div
                        class="
                            card
                            card-custom
                            p-3
                            h-100
                        "
                    >

                        <h6
                            class="
                                fw-bold
                                text-dark
                                mb-3
                            "
                        >

                            <i
                                class="
                                    fas
                                    fa-chart-pie
                                    me-2
                                    text-primary
                                "
                            ></i>

                            Category Breakdown

                        </h6>


                        <div
                            style="
                                position: relative;
                                height: 260px;
                            "
                            class="
                                d-flex
                                align-items-center
                                justify-content-center
                            "
                        >

                            <canvas
                                id="monthlyChart"
                            ></canvas>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =====================================================
                 SALES HISTORY
            ====================================================== -->

            <div
                class="
                    card
                    card-custom
                "
            >

                <div
                    class="
                        card-header
                        bg-white
                        py-3
                        border-bottom
                        d-flex
                        justify-content-between
                        align-items-center
                    "
                >

                    <h5
                        class="
                            fw-bold
                            text-dark
                            mb-0
                        "
                    >

                        <i
                            class="
                                fas
                                fa-list
                                me-2
                                text-primary
                            "
                        ></i>

                        Itemized Sales History

                    </h5>


                    <span
                        id="record-count"
                        class="
                            badge
                            bg-secondary
                        "
                    >

                        0 Records

                    </span>

                </div>


                <div
                    class="card-body p-0"
                >

                    <div
                        class="table-responsive"
                    >

                        <table
                            class="
                                table
                                table-custom
                                align-middle
                            "
                        >

                            <thead>

                                <tr>

                                    <th>#</th>

                                    <th>
                                        Invoice Ref
                                    </th>

                                    <th>
                                        Product Name
                                    </th>

                                    <th
                                        class="text-center"
                                    >
                                        Qty
                                    </th>

                                    <th
                                        class="text-end"
                                    >
                                        Total Amount
                                    </th>

                                    <th
                                        class="text-center"
                                    >
                                        Payment
                                    </th>

                                    <th
                                        class="text-center"
                                    >
                                        Source
                                    </th>

                                    <th
                                        class="text-center"
                                    >
                                        Date
                                    </th>

                                </tr>

                            </thead>


                            <tbody
                                id="sales-body"
                            >

                                <tr>

                                    <td
                                        colspan="8"
                                        class="
                                            text-center
                                            py-4
                                            text-muted
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

                                        Loading sales data...

                                    </td>

                                </tr>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <?php

    if (
        file_exists(
            "../includes/footer.php"
        )
    ) {

        require_once
            "../includes/footer.php";
    }

    ?>

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

<script
    src="https://cdn.jsdelivr.net/npm/chart.js"
></script>


<script>

/*
|--------------------------------------------------------------------------
| GLOBAL CHARTS
|--------------------------------------------------------------------------
*/

let mChart = null;

let wChart = null;


/*
|--------------------------------------------------------------------------
| ZAMBIA BUSINESS DATE
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| NEVER use:
|
|     date.toISOString().split('T')[0]
|
| for these filters.
|
| toISOString() converts the local time to UTC.
|
| Instead we manually construct:
|
|     YYYY-MM-DD
|
| using the browser's LOCAL date.
|--------------------------------------------------------------------------
*/

function formatLocalDate(date) {

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


/*
|--------------------------------------------------------------------------
| GET TODAY IN LOCAL TIME
|--------------------------------------------------------------------------
*/

function getLocalToday() {

    return formatLocalDate(
        new Date()
    );
}


/*
|--------------------------------------------------------------------------
| SET DATE PRESET
|--------------------------------------------------------------------------
*/

function setDatePreset(type) {

    /*
    |--------------------------------------------------------------------------
    | IMPORTANT
    |--------------------------------------------------------------------------
    | JavaScript Date uses the browser's local timezone here.
    | We do NOT convert it to UTC.
    |--------------------------------------------------------------------------
    */

    const today =
        new Date();


    let start =
        new Date(today);

    let end =
        new Date(today);


    /*
    |--------------------------------------------------------------------------
    | TODAY
    |--------------------------------------------------------------------------
    */

    if (type === 'today') {

        start =
            new Date(today);

        end =
            new Date(today);
    }


    /*
    |--------------------------------------------------------------------------
    | YESTERDAY
    |--------------------------------------------------------------------------
    */

    else if (
        type === 'yesterday'
    ) {

        start =
            new Date(today);

        start.setDate(
            start.getDate() - 1
        );

        end =
            new Date(start);
    }


    /*
    |--------------------------------------------------------------------------
    | THIS MONTH
    |--------------------------------------------------------------------------
    */

    else if (
        type === 'this_month'
    ) {

        start =
            new Date(
                today.getFullYear(),
                today.getMonth(),
                1
            );

        end =
            new Date(today);
    }


    /*
    |--------------------------------------------------------------------------
    | LAST MONTH
    |--------------------------------------------------------------------------
    */

    else if (
        type === 'last_month'
    ) {

        start =
            new Date(
                today.getFullYear(),
                today.getMonth() - 1,
                1
            );

        end =
            new Date(
                today.getFullYear(),
                today.getMonth(),
                0
            );
    }


    /*
    |--------------------------------------------------------------------------
    | WRITE LOCAL DATES
    |--------------------------------------------------------------------------
    */

    $('#startDate').val(
        formatLocalDate(start)
    );

    $('#endDate').val(
        formatLocalDate(end)
    );


    /*
    |--------------------------------------------------------------------------
    | LOAD DATA
    |--------------------------------------------------------------------------
    */

    loadSales();
}


/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
|
| Prevent product names / invoice values from injecting HTML.
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| LOAD SALES
|--------------------------------------------------------------------------
*/

function loadSales() {

    const btn =
        $('#filter-btn');


    /*
    |--------------------------------------------------------------------------
    | VALIDATE DATES
    |--------------------------------------------------------------------------
    */

    const startDate =
        $('#startDate').val();

    const endDate =
        $('#endDate').val();


    if (
        !startDate ||
        !endDate
    ) {

        alert(
            'Please select both Start Date and End Date.'
        );

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | DATE ORDER CHECK
    |--------------------------------------------------------------------------
    */

    if (
        startDate >
        endDate
    ) {

        alert(
            'Start Date cannot be after End Date.'
        );

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | BUTTON LOADING
    |--------------------------------------------------------------------------
    */

    btn
        .prop(
            'disabled',
            true
        )
        .html(
            '<i class="fas fa-spinner fa-spin me-1"></i> UPDATING...'
        );


    /*
    |--------------------------------------------------------------------------
    | TABLE LOADING
    |--------------------------------------------------------------------------
    */

    $('#sales-body').html(
        `
        <tr>
            <td
                colspan="8"
                class="text-center py-4 text-muted"
            >
                <i
                    class="
                        fas
                        fa-spinner
                        fa-spin
                        me-2
                    "
                ></i>

                Loading sales data...
            </td>
        </tr>
        `
    );


    /*
    |--------------------------------------------------------------------------
    | AJAX REQUEST
    |--------------------------------------------------------------------------
    */

    $.ajax({

        url:
            'actions/fetch_sales.php',

        method:
            'POST',

        data: {

            search:
                $('#search').val(),

            category:
                $('#category').val(),

            startDate:
                startDate,

            endDate:
                endDate
        },

        dataType:
            'json',


        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        success:
            function(res) {


                /*
                |--------------------------------------------------------------------------
                | SERVER ERROR
                |--------------------------------------------------------------------------
                */

                if (
                    !res ||
                    res.status !==
                    'success'
                ) {

                    const message =
                        res &&
                        res.message
                            ? res.message
                            : 'Unable to load sales data.';


                    $('#sales-body').html(
                        `
                        <tr>
                            <td
                                colspan="8"
                                class="
                                    text-center
                                    text-danger
                                    py-4
                                "
                            >
                                ${escapeHtml(message)}
                            </td>
                        </tr>
                        `
                    );


                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | SALES ROWS
                |--------------------------------------------------------------------------
                */

                let rows = '';


                if (
                    Array.isArray(
                        res.sales
                    ) &&
                    res.sales.length > 0
                ) {


                    res.sales.forEach(
                        function(s, i) {


                            const invoiceNo =
                                escapeHtml(
                                    s.invoice_no
                                );


                            const itemName =
                                escapeHtml(
                                    s.item_name
                                );


                            const quantity =
                                Number(
                                    s.quantity || 0
                                );


                            const totalPrice =
                                Number(
                                    s.total_price || 0
                                );


                            const saleDate =
                                escapeHtml(
                                    s.date
                                );

                            const paymentMethod =
                                escapeHtml(
                                    s.payment_method || 'Not specified'
                                );

                            const source =
                                escapeHtml(
                                    s.source || 'POS Sale'
                                );


                            rows +=
                                `
                                <tr>

                                    <td
                                        class="
                                            fw-bold
                                            text-muted
                                        "
                                    >
                                        ${i + 1}
                                    </td>


                                    <td>

                                        <span
                                            class="
                                                badge
                                                bg-light
                                                text-dark
                                                border
                                                fw-semibold
                                            "
                                        >
                                            ${invoiceNo}
                                        </span>

                                    </td>


                                    <td
                                        class="
                                            fw-bold
                                            text-dark
                                        "
                                    >
                                        ${itemName}
                                    </td>


                                    <td
                                        class="
                                            text-center
                                        "
                                    >

                                        <span
                                            class="
                                                badge
                                                bg-secondary
                                            "
                                        >
                                            ${quantity}
                                        </span>

                                    </td>


                                    <td
                                        class="
                                            text-end
                                            fw-bold
                                            text-success
                                        "
                                    >

                                        K${totalPrice.toFixed(2)}

                                    </td>


                                    <td
                                        class="
                                            text-center
                                            small
                                        "
                                    >
                                        <span
                                            class="badge bg-light text-dark border"
                                        >
                                            ${paymentMethod}
                                        </span>
                                    </td>

                                    <td
                                        class="
                                            text-center
                                            small
                                        "
                                    >
                                        <span
                                            class="badge ${
                                                source === 'Online Order'
                                                    ? 'bg-info text-dark'
                                                    : 'bg-secondary'
                                            }"
                                        >
                                            ${source}
                                        </span>
                                    </td>

                                    <td
                                        class="
                                            text-center
                                            text-muted
                                            small
                                        "
                                    >
                                        ${saleDate}
                                    </td>

                                </tr>
                                `;
                        }
                    );


                    $('#record-count').text(
                        res.sales.length +
                        ' Records'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | NO RESULTS
                |--------------------------------------------------------------------------
                */

                else {

                    rows =
                        `
                        <tr>

                            <td
                                colspan="8"
                                class="
                                    text-center
                                    py-4
                                    text-muted
                                "
                            >

                                No sales transactions found
                                for the selected filter range.

                            </td>

                        </tr>
                        `;


                    $('#record-count').text(
                        '0 Records'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | UPDATE TABLE
                |--------------------------------------------------------------------------
                */

                $('#sales-body').html(
                    rows
                );


                /*
                |--------------------------------------------------------------------------
                | TOTAL SALES
                |--------------------------------------------------------------------------
                */

                const totalSales =
                    Number(
                        res.total_sales || 0
                    );


                $('#total-sales').text(
                    'K ' +
                    totalSales.toLocaleString(
                        undefined,
                        {
                            minimumFractionDigits:
                                2,

                            maximumFractionDigits:
                                2
                        }
                    )
                );


                /*
                |--------------------------------------------------------------------------
                | TOTAL ITEMS
                |--------------------------------------------------------------------------
                */

                $('#total-items').text(
                    Number(
                        res.total_items || 0
                    )
                );


                /*
                |--------------------------------------------------------------------------
                | TOTAL INVOICES
                |--------------------------------------------------------------------------
                */

                $('#total-invoices').text(
                    Number(
                        res.total_invoices || 0
                    )
                );


                /*
                |--------------------------------------------------------------------------
                | CHARTS
                |--------------------------------------------------------------------------
                */

                renderCharts(

                    res.monthly_snapshot ||
                        {},

                    res.daily_trend ||
                        {}
                );
            },


        /*
        |--------------------------------------------------------------------------
        | AJAX ERROR
        |--------------------------------------------------------------------------
        */

        error:
            function(xhr) {

                console.error(
                    'fetch_sales.php error:',
                    xhr.responseText
                );


                let message =
                    'Failed to load sales data.';


                /*
                |--------------------------------------------------------------------------
                | TRY TO READ JSON ERROR
                |--------------------------------------------------------------------------
                */

                try {

                    const response =
                        JSON.parse(
                            xhr.responseText
                        );


                    if (
                        response &&
                        response.message
                    ) {

                        message =
                            response.message;
                    }

                } catch (e) {

                    /*
                    |--------------------------------------------------------------------------
                    | Ignore invalid JSON
                    |--------------------------------------------------------------------------
                    */

                }


                $('#sales-body').html(
                    `
                    <tr>

                        <td
                            colspan="8"
                            class="
                                text-center
                                text-danger
                                py-4
                            "
                        >

                            ${escapeHtml(message)}

                        </td>

                    </tr>
                    `
                );


                $('#record-count').text(
                    '0 Records'
                );


                $('#total-sales').text(
                    'K 0.00'
                );


                $('#total-items').text(
                    '0'
                );


                $('#total-invoices').text(
                    '0'
                );
            },


        /*
        |--------------------------------------------------------------------------
        | COMPLETE
        |--------------------------------------------------------------------------
        */

        complete:
            function() {

                btn
                    .prop(
                        'disabled',
                        false
                    )
                    .html(
                        '<i class="fas fa-sync-alt me-1"></i> UPDATE'
                    );
            }

    });
}


/*
|--------------------------------------------------------------------------
| RENDER CHARTS
|--------------------------------------------------------------------------
*/

function renderCharts(
    monthly,
    daily
) {


    /*
    |--------------------------------------------------------------------------
    | DESTROY OLD CHARTS
    |--------------------------------------------------------------------------
    */

    if (mChart) {

        mChart.destroy();

        mChart = null;
    }


    if (wChart) {

        wChart.destroy();

        wChart = null;
    }


    /*
    |--------------------------------------------------------------------------
    | NORMALIZE DATA
    |--------------------------------------------------------------------------
    */

    monthly =
        monthly &&
        typeof monthly === 'object'
            ? monthly
            : {};


    daily =
        daily &&
        typeof daily === 'object'
            ? daily
            : {};


    /*
    |--------------------------------------------------------------------------
    | MONTHLY / CATEGORY CHART
    |--------------------------------------------------------------------------
    */

    const monthlyCanvas =
        document.getElementById(
            'monthlyChart'
        );


    if (
        monthlyCanvas
    ) {

        const ctxMonthly =
            monthlyCanvas.getContext(
                '2d'
            );


        const monthlyLabels =
            Object.keys(
                monthly
            );


        const monthlyValues =
            Object.values(
                monthly
            ).map(
                Number
            );


        mChart =
            new Chart(
                ctxMonthly,
                {

                    type:
                        'doughnut',

                    data: {

                        labels:
                            monthlyLabels,

                        datasets: [

                            {

                                data:
                                    monthlyValues,

                                backgroundColor: [

                                    '#0d6efd',

                                    '#198754',

                                    '#ffc107',

                                    '#0dcaf0',

                                    '#fd7e14',

                                    '#6c757d',

                                    '#dc3545',

                                    '#6610f2',

                                    '#20c997',

                                    '#6f42c1'
                                ],

                                borderWidth:
                                    1
                            }

                        ]
                    },


                    options: {

                        responsive:
                            true,

                        maintainAspectRatio:
                            false,

                        plugins: {

                            legend: {

                                position:
                                    'bottom'
                            }

                        }
                    }
                }
            );
    }


    /*
    |--------------------------------------------------------------------------
    | DAILY REVENUE CHART
    |--------------------------------------------------------------------------
    */

    const weeklyCanvas =
        document.getElementById(
            'weeklyChart'
        );


    if (
        weeklyCanvas
    ) {

        const ctxWeekly =
            weeklyCanvas.getContext(
                '2d'
            );


        const dailyLabels =
            Object.keys(
                daily
            );


        const dailyValues =
            Object.values(
                daily
            ).map(
                Number
            );


        wChart =
            new Chart(
                ctxWeekly,
                {

                    type:
                        'line',

                    data: {

                        labels:
                            dailyLabels,

                        datasets: [

                            {

                                label:
                                    'Revenue (K)',

                                data:
                                    dailyValues,

                                borderColor:
                                    '#0d6efd',

                                backgroundColor:
                                    'rgba(13, 110, 253, 0.08)',

                                fill:
                                    true,

                                tension:
                                    0.35,

                                pointRadius:
                                    4,

                                pointBackgroundColor:
                                    '#0d6efd'
                            }

                        ]
                    },


                    options: {

                        responsive:
                            true,

                        maintainAspectRatio:
                            false,

                        scales: {

                            y: {

                                beginAtZero:
                                    true,

                                ticks: {

                                    callback:
                                        function(value) {

                                            return (
                                                'K ' +
                                                Number(
                                                    value
                                                ).toLocaleString()
                                            );
                                        }
                                },

                                grid: {

                                    color:
                                        '#f1f5f9'
                                }
                            },


                            x: {

                                grid: {

                                    display:
                                        false
                                }
                            }
                        },


                        plugins: {

                            tooltip: {

                                callbacks: {

                                    label:
                                        function(context) {

                                            const value =
                                                Number(
                                                    context.raw ||
                                                    0
                                                );


                                            return (
                                                ' Revenue: K ' +
                                                value.toLocaleString(
                                                    undefined,
                                                    {
                                                        minimumFractionDigits:
                                                            2,

                                                        maximumFractionDigits:
                                                            2
                                                    }
                                                )
                                            );
                                        }
                                }
                            }
                        }
                    }
                }
            );
    }
}


/*
|--------------------------------------------------------------------------
| SEARCH ON ENTER
|--------------------------------------------------------------------------
*/

$('#search').on(
    'keypress',
    function(e) {

        if (
            e.which === 13
        ) {

            e.preventDefault();

            loadSales();
        }
    }
);


/*
|--------------------------------------------------------------------------
| CATEGORY CHANGE
|--------------------------------------------------------------------------
|
| We deliberately DO NOT auto-load here.
| User can select category then press UPDATE.
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| DATE CHANGE
|--------------------------------------------------------------------------
|
| We deliberately DO NOT auto-load here.
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| DOCUMENT READY
|--------------------------------------------------------------------------
*/

$(document).ready(
    function() {


        /*
        |--------------------------------------------------------------------------
        | ENSURE INITIAL DATES ARE LOCAL
        |--------------------------------------------------------------------------
        */

        const today =
            getLocalToday();


        /*
        |--------------------------------------------------------------------------
        | Only set dates if fields are empty.
        |--------------------------------------------------------------------------
        */

        if (
            !$('#startDate').val()
        ) {

            const now =
                new Date();


            const firstDay =
                new Date(
                    now.getFullYear(),
                    now.getMonth(),
                    1
                );


            $('#startDate').val(
                formatLocalDate(
                    firstDay
                )
            );
        }


        if (
            !$('#endDate').val()
        ) {

            $('#endDate').val(
                today
            );
        }


        /*
        |--------------------------------------------------------------------------
        | UPDATE BUTTON
        |--------------------------------------------------------------------------
        */

        $('#filter-btn').on(
            'click',
            function(e) {

                e.preventDefault();

                loadSales();
            }
        );


        /*
        |--------------------------------------------------------------------------
        | INITIAL LOAD
        |--------------------------------------------------------------------------
        */

        loadSales();

    }
);

</script>


</body>

</html>
