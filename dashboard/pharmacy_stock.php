<?php
/**
 * ============================================================
 * PHARMACY POS - PHARMACY STOCK
 * ============================================================
 *
 * POS Standard Time:
 *     Africa/Lusaka
 *
 * Important:
 * - NULL expiry_date = no expiry date
 * - No '0000-00-00' DATE comparisons
 * - Tenant protected by pharmacy_id + branch_id
 * - Shows active stock only
 * - Preserves existing inventory valuation
 * - Preserves search, pagination, edit and delete
 * ============================================================
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| POS STANDARD TIME
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Lusaka');


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
| Includes
|--------------------------------------------------------------------------
*/

require_once "../includes/conn.php";
require_once "../includes/auth.php";


/*
|--------------------------------------------------------------------------
| Zambia / POS Local Date
|--------------------------------------------------------------------------
*/

$current_date = date('Y-m-d');


/*
|--------------------------------------------------------------------------
| Branch / Pharmacy
|--------------------------------------------------------------------------
*/

$branch_id = isset($_SESSION['branch_id'])
    ? (int) $_SESSION['branch_id']
    : 0;

$pharmacy_id = isset($_SESSION['pharmacy_id'])
    ? (int) $_SESSION['pharmacy_id']
    : 0;


/*
|--------------------------------------------------------------------------
| Session Validation
|--------------------------------------------------------------------------
*/

if ($branch_id <= 0 || $pharmacy_id <= 0) {

    header(
        "Location: ../index.php?error=session_expired"
    );

    exit();

}


/*
|--------------------------------------------------------------------------
| Fetch Branch Name
|--------------------------------------------------------------------------
*/

$branch_name = "Our Branch";


$branch_stmt = mysqli_prepare(
    $conn,
    "
    SELECT branch_name
    FROM branches
    WHERE id = ?
    LIMIT 1
    "
);


if ($branch_stmt) {

    mysqli_stmt_bind_param(
        $branch_stmt,
        "i",
        $branch_id
    );

    mysqli_stmt_execute($branch_stmt);

    $branch_result =
        mysqli_stmt_get_result($branch_stmt);

    if (
        $branch_result &&
        ($branch_row = mysqli_fetch_assoc($branch_result))
    ) {

        $branch_name =
            $branch_row['branch_name'];

    }

    mysqli_stmt_close($branch_stmt);

}


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = isset($_GET['page'])
    ? (int) $_GET['page']
    : 1;

if ($page < 1) {
    $page = 1;
}


$num_per_page = 15;

$start_from =
    ($page - 1) * $num_per_page;


/*
|--------------------------------------------------------------------------
| STOCK FILTER
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| We intentionally do NOT use:
|
|     expiry_date = '0000-00-00'
|
| or:
|
|     CAST(expiry_date AS CHAR) = '0000-00-00'
|
| because MySQL strict date mode can throw:
|
|     #1525 Incorrect DATE value: '0000-00-00'
|
| NULL means that the product has no expiry date.
|
| Only products that:
| - belong to this pharmacy
| - belong to this branch
| - have quantity > 0
| - are not expired
|
| are displayed in Live Stock Summary.
|--------------------------------------------------------------------------
*/

$where_clause = "
    WHERE pharmacy_id = {$pharmacy_id}
      AND branch_id = {$branch_id}
      AND quantity > 0
      AND (
            expiry_date IS NULL
            OR expiry_date >= '{$current_date}'
          )
";


/*
|--------------------------------------------------------------------------
| TOTAL BRANCH ASSET VALUATION
|--------------------------------------------------------------------------
*/

$total_inventory_value = 0.00;


$total_val_q = "
    SELECT
        COALESCE(
            SUM(price * quantity),
            0
        ) AS total_valuation

    FROM store_items

    {$where_clause}
";


$total_val_res =
    mysqli_query(
        $conn,
        $total_val_q
    );


if ($total_val_res) {

    $total_val_row =
        mysqli_fetch_assoc($total_val_res);

    $total_inventory_value =
        (float) (
            $total_val_row['total_valuation']
            ?? 0
        );

}


/*
|--------------------------------------------------------------------------
| PAGINATED STOCK QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        item_name,
        price,
        quantity,
        is_active,
        expiry_date,
        category,
        strength,
        pharmacy_id,
        branch_id

    FROM store_items

    {$where_clause}

    ORDER BY item_name ASC

    LIMIT {$start_from}, {$num_per_page}
";


$res =
    mysqli_query(
        $conn,
        $sql
    );


/*
|--------------------------------------------------------------------------
| TOTAL STOCK ITEMS
|--------------------------------------------------------------------------
*/

$total_q = "
    SELECT COUNT(*) AS total
    FROM store_items
    {$where_clause}
";


$total_res =
    mysqli_query(
        $conn,
        $total_q
    );


$total_items = 0;


if ($total_res) {

    $total_row =
        mysqli_fetch_assoc($total_res);

    $total_items =
        (int) (
            $total_row['total']
            ?? 0
        );

}


$total_page =
    $total_items > 0
        ? (int) ceil(
            $total_items /
            $num_per_page
        )
        : 0;


/*
|--------------------------------------------------------------------------
| Head
|--------------------------------------------------------------------------
*/

require_once "../includes/head.php";

?>


<style>

/*
|--------------------------------------------------------------------------
| STOCK PAGE STYLES
|--------------------------------------------------------------------------
*/

.table.v-middle td,
.table.v-middle th {
    padding: 12px;
    vertical-align: middle;
}


.btn-update {
    background-color: #00ffae;
    color: #000;
    border: none;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}


.btn-update:hover {
    background-color: #00e699;
    color: #000;
    transform: translateY(-1px);
}


.stock-badge {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: bold;
}


.bg-dark-custom {
    background-color: #1a1a1a !important;
    color: #00ffae;
    border-bottom: 2px solid #333;
}


.text-neon {
    color: #00ffae !important;
}


.total-row {
    background-color: #f8f9fa;
    border-top: 2px solid #00ffae;
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

#search {
    min-height: 42px;
}


/*
|--------------------------------------------------------------------------
| Mobile
|--------------------------------------------------------------------------
*/

@media (max-width: 767px) {

    .page-title {
        font-size: 1rem !important;
    }

    .table.v-middle td,
    .table.v-middle th {
        padding: 9px;
        font-size: 0.85rem;
    }

}

</style>


<div id="main-wrapper">


    <?php

    /*
    |--------------------------------------------------------------------------
    | Header / Sidebar
    |--------------------------------------------------------------------------
    */

    if (
        file_exists("../includes/header.php")
    ) {
        require_once "../includes/header.php";
    }


    if (
        file_exists("../includes/aside.php")
    ) {
        require_once "../includes/aside.php";
    }

    ?>


    <div class="page-wrapper">


        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <div class="page-breadcrumb">

            <div class="row align-items-center">

                <div class="col-12 col-md-5">

                    <h4
                        class="page-title text-dark fw-bold mb-0"
                    >
                        <?php
                        echo htmlspecialchars(
                            $branch_name,
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>
                        Inventory
                    </h4>


                    <nav aria-label="breadcrumb">

                        <ol class="breadcrumb mb-0">

                            <li class="breadcrumb-item">

                                <a
                                    href="dashboard.php"
                                    class="text-primary small"
                                >
                                    Dashboard
                                </a>

                            </li>


                            <li
                                class="breadcrumb-item active small"
                                aria-current="page"
                            >
                                Pharmacy Stock
                            </li>

                        </ol>

                    </nav>

                </div>

            </div>

        </div>


        <!-- =====================================================
             MAIN CONTENT
        ====================================================== -->

        <div class="container-fluid">


            <!-- =================================================
                 SUCCESS MESSAGE
            ================================================== -->

            <?php

            if (
                isset($_GET['status']) &&
                $_GET['status'] === 'damaged_recorded'
            ):

            ?>

                <div
                    class="alert alert-warning alert-dismissible fade show border-0 shadow-sm"
                    role="alert"
                >

                    <i
                        class="mdi mdi-check-circle me-2"
                    ></i>

                    <strong>
                        Stock Updated!
                    </strong>

                    Removed damaged units of

                    <?php

                    echo htmlspecialchars(
                        $_GET['item'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    ?>.

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="Close"
                    ></button>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 ERROR MESSAGE
            ================================================== -->

            <?php

            if (
                isset($_GET['status']) &&
                $_GET['status'] === 'error'
            ):

            ?>

                <div
                    class="alert alert-danger alert-dismissible fade show border-0 shadow-sm"
                    role="alert"
                >

                    <i
                        class="mdi mdi-alert-circle me-2"
                    ></i>

                    <strong>
                        Error!
                    </strong>

                    Could not update stock.

                    <?php

                    echo htmlspecialchars(
                        $_GET['msg'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    ?>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="Close"
                    ></button>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 SEARCH / ACTIONS
            ================================================== -->

            <div
                class="row mb-3 align-items-center"
            >

                <div class="col-md-5">

                    <div class="input-group shadow-sm">

                        <span
                            class="input-group-text bg-white border-end-0"
                        >
                            <i
                                class="fas fa-search text-muted"
                            ></i>
                        </span>


                        <input
                            type="text"
                            class="form-control border-start-0"
                            id="search"
                            placeholder="Search by name or barcode..."
                            autocomplete="off"
                        >

                    </div>

                </div>


                <div
                    class="col-md-7 text-end mt-2 mt-md-0"
                >

                    <a
                        href="update_items_stock.php"
                        class="btn btn-outline-dark rounded-pill px-3 me-2 btn-sm fw-semibold"
                    >
                        <i
                            class="fas fa-truck-loading me-1"
                        ></i>

                        Restock
                    </a>


                    <a
                        href="add_product.php"
                        class="btn btn-success rounded-pill px-3 btn-sm fw-bold text-white"
                    >
                        <i
                            class="fas fa-plus me-1"
                        ></i>

                        New Product
                    </a>

                </div>

            </div>


            <!-- =================================================
                 STOCK TABLE
            ================================================== -->

            <div class="row">

                <div class="col-12">

                    <div class="card shadow-sm border-0">


                        <!-- HEADER -->

                        <div
                            class="card-body border-bottom"
                        >

                            <h4
                                class="card-title fw-bold mb-1"
                            >
                                Live Stock Summary
                            </h4>


                            <p
                                class="card-subtitle text-muted mb-0 small"
                            >
                                Manage and track your pharmaceutical assets
                            </p>

                        </div>


                        <!-- TABLE -->

                        <div class="table-responsive">

                            <table
                                class="table v-middle mb-0 align-middle"
                            >

                                <thead>

                                    <tr class="bg-light">

                                        <th class="ps-4">
                                            S.N
                                        </th>

                                        <th>
                                            Item Name & Description
                                        </th>

                                        <th>
                                            Unit Price
                                        </th>

                                        <th>
                                            Category
                                        </th>

                                        <th>
                                            Quantity
                                        </th>

                                        <th>
                                            Total Value
                                        </th>

                                        <th>
                                            Expiry Status
                                        </th>

                                        <th class="text-center">
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody id="output">


                                <?php

                                if (
                                    $res &&
                                    mysqli_num_rows($res) > 0
                                ) {

                                    $sn =
                                        $start_from + 1;


                                    while (
                                        $row =
                                        mysqli_fetch_assoc($res)
                                    ) {


                                        /*
                                        |--------------------------------------------------------------------------
                                        | Product Values
                                        |--------------------------------------------------------------------------
                                        */

                                        $price =
                                            (float) (
                                                $row['price']
                                                ?? 0
                                            );


                                        $qty =
                                            (int) (
                                                $row['quantity']
                                                ?? 0
                                            );


                                        $row_total =
                                            $price * $qty;


                                        /*
                                        |--------------------------------------------------------------------------
                                        | Expiry
                                        |--------------------------------------------------------------------------
                                        */

                                        $expiry_date =
                                            $row['expiry_date']
                                            ?? null;


                                        /*
                                        |--------------------------------------------------------------------------
                                        | A valid expiry exists only when:
                                        | - not NULL
                                        | - not empty
                                        |--------------------------------------------------------------------------
                                        */

                                        $has_expiry =
                                            $expiry_date !== null &&
                                            $expiry_date !== '';


                                        /*
                                        |--------------------------------------------------------------------------
                                        | Expired
                                        |--------------------------------------------------------------------------
                                        */

                                        $is_expired =
                                            $has_expiry &&
                                            $expiry_date < $current_date;


                                        /*
                                        |--------------------------------------------------------------------------
                                        | Stock Status
                                        |--------------------------------------------------------------------------
                                        */

                                        $stock_status =
                                            ($qty <= 10)
                                                ? 'bg-danger'
                                                : 'bg-success';

                                ?>


                                    <tr
                                        data-id="<?php
                                        echo (int) $row['id'];
                                        ?>"
                                    >


                                        <!-- S.N -->

                                        <td class="ps-4 text-muted">

                                            <?php
                                            echo $sn++;
                                            ?>

                                        </td>


                                        <!-- ITEM -->

                                        <td>

                                            <span
                                                class="fw-bold text-dark"
                                            >

                                                <?php

                                                echo htmlspecialchars(
                                                    $row['item_name'] ?? '',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );

                                                ?>

                                            </span>

                                            <?php

                                            if (
                                                !empty(
                                                    $row['strength']
                                                    ?? ''
                                                )
                                            ):

                                            ?>

                                                <small
                                                    class="d-block text-muted"
                                                >

                                                    <?php

                                                    echo htmlspecialchars(
                                                        $row['strength'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );

                                                    ?>

                                                </small>

                                            <?php endif; ?>

                                        </td>


                                        <!-- PRICE -->

                                        <td class="fw-bold">

                                            K
                                            <?php

                                            echo number_format(
                                                $price,
                                                2
                                            );

                                            ?>

                                        </td>


                                        <!-- CATEGORY -->

                                        <td>

                                            <span
                                                class="badge bg-light text-dark border"
                                            >

                                                <?php

                                                echo htmlspecialchars(
                                                    $row['category']
                                                        ?? 'Medicine',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );

                                                ?>

                                            </span>

                                        </td>


                                        <!-- QUANTITY -->

                                        <td>

                                            <span
                                                class="badge <?php
                                                echo $stock_status;
                                                ?> text-white px-2 py-1"
                                            >

                                                <?php

                                                echo number_format(
                                                    $qty
                                                );

                                                ?>

                                            </span>

                                        </td>


                                        <!-- TOTAL VALUE -->

                                        <td
                                            class="text-dark fw-bold"
                                        >

                                            K
                                            <?php

                                            echo number_format(
                                                $row_total,
                                                2
                                            );

                                            ?>

                                        </td>


                                        <!-- EXPIRY -->

                                        <td>

                                            <?php

                                            if ($has_expiry):

                                            ?>

                                                <span
                                                    class="<?php
                                                    echo $is_expired
                                                        ? 'text-danger fw-bold'
                                                        : 'text-muted';
                                                    ?>"
                                                >

                                                    <?php

                                                    echo date(
                                                        'd M Y',
                                                        strtotime(
                                                            $expiry_date
                                                        )
                                                    );

                                                    ?>

                                                </span>


                                                <?php

                                                if ($is_expired):

                                                ?>

                                                    <br>

                                                    <small
                                                        class="badge bg-danger"
                                                    >
                                                        EXPIRED
                                                    </small>

                                                <?php endif; ?>


                                            <?php else: ?>


                                                <span
                                                    class="text-muted"
                                                >
                                                    No expiry
                                                </span>


                                            <?php endif; ?>

                                        </td>


                                        <!-- ACTION -->

                                        <td class="text-center">

                                            <a
                                                href="update_product.php?id=<?php echo (int) $row['id']; ?>"
                                                class="btn btn-outline-info btn-sm rounded-circle me-1"
                                                title="Edit"
                                            >

                                                <i
                                                    class="fas fa-pen"
                                                ></i>

                                            </a>


                                            <button
                                                type="button"
                                                class="btn btn-outline-danger btn-sm rounded-circle delete-btn"
                                                data-id="<?php echo (int) $row['id']; ?>"
                                                title="Delete"
                                            >

                                                <i
                                                    class="fas fa-trash"
                                                ></i>

                                            </button>

                                        </td>


                                    </tr>


                                <?php

                                    }

                                } else {

                                ?>


                                    <tr>

                                        <td
                                            colspan="8"
                                            class="text-center py-5 text-muted"
                                        >

                                            No stock items found.
                                            Click
                                            <strong>
                                                "New Product"
                                            </strong>
                                            to begin.

                                        </td>

                                    </tr>


                                <?php

                                }

                                ?>


                                </tbody>

                            </table>

                        </div>


                        <!-- =================================================
                             VALUATION
                        ================================================== -->

                        <div
                            class="card-footer bg-white p-4"
                        >

                            <div
                                class="row justify-content-end"
                            >

                                <div
                                    class="col-md-4 text-end"
                                >

                                    <h6
                                        class="text-muted text-uppercase mb-1"
                                        style="
                                            font-size: 0.75rem;
                                            letter-spacing: 1px;
                                        "
                                    >
                                        Branch Asset Valuation
                                    </h6>


                                    <h3
                                        class="text-success fw-bold mb-0"
                                    >

                                        K
                                        <?php

                                        echo number_format(
                                            $total_inventory_value,
                                            2
                                        );

                                        ?>

                                    </h3>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 PAGINATION
            ================================================== -->

            <?php

            if ($total_page > 1):

            ?>

                <div class="row mt-4">

                    <div class="col-12">

                        <nav
                            aria-label="Page navigation"
                        >

                            <ul
                                class="pagination justify-content-center"
                            >

                                <?php

                                for (
                                    $i = 1;
                                    $i <= $total_page;
                                    $i++
                                ):

                                ?>

                                    <li
                                        class="page-item <?php
                                        echo (
                                            $i == $page
                                        )
                                            ? 'active'
                                            : '';
                                        ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="pharmacy_stock.php?page=<?php echo $i; ?>"
                                        >

                                            <?php
                                            echo $i;
                                            ?>

                                        </a>

                                    </li>

                                <?php

                                endfor;

                                ?>

                            </ul>

                        </nav>

                    </div>

                </div>

            <?php endif; ?>


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

</div>


<!-- ============================================================
     JAVASCRIPT
============================================================= -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

$(document).ready(function () {


    /*
    |--------------------------------------------------------------------------
    | PRODUCT SEARCH
    |--------------------------------------------------------------------------
    */

    $("#search").on(
        "keyup",
        function () {

            var query =
                $(this).val();


            $.ajax({

                url:
                    "fetch_products.php",

                method:
                    "POST",

                data: {
                    query: query
                },

                success:
                    function (data) {

                        $("#output")
                            .html(data);

                    },

                error:
                    function () {

                        console.warn(
                            "Product search failed."
                        );

                    }

            });

        }
    );


    /*
    |--------------------------------------------------------------------------
    | DELETE PRODUCT
    |--------------------------------------------------------------------------
    */

    $(document).on(
        "click",
        ".delete-btn",
        function () {

            var id =
                $(this).data("id");

            var row =
                $(this).closest("tr");


            if (
                confirm(
                    "Are you sure? This will permanently remove the item from this branch's records."
                )
            ) {

                $.ajax({

                    url:
                        "../includes/delete_product_inc.php",

                    type:
                        "POST",

                    data: {
                        id: id
                    },

                    success:
                        function (resp) {

                            if (
                                String(resp)
                                    .toLowerCase()
                                    .includes("success")
                            ) {

                                row.fadeOut(
                                    400,
                                    function () {
                                        $(this).remove();
                                    }
                                );

                            } else {

                                alert(
                                    "Error: Delete failed. Ensure you have the required permissions."
                                );

                            }

                        },

                    error:
                        function () {

                            alert(
                                "Error: Could not contact the server."
                            );

                        }

                });

            }

        }
    );

});

</script>


</body>
</html>
