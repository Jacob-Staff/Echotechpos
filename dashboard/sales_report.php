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
 *     JavaScript date handling below intentionally does NOT use
 *     toISOString(), because that converts local Zambia dates
 *     into UTC and can shift 24 Aug -> 23 Aug.
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

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
| DATABASE / AUTH
|--------------------------------------------------------------------------
*/

require_once "../includes/conn.php";
require_once "../includes/auth.php";


/*
|--------------------------------------------------------------------------
| SESSION TENANT CONTEXT
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
| DISPLAY NAMES
|--------------------------------------------------------------------------
*/

$display_pharmacy_name = "Echo Prime Ltd";
$display_branch_name   = "Main Branch";


/*
|--------------------------------------------------------------------------
| PHARMACY NAME
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

        if (!empty($row['name'])) {
            $display_pharmacy_name =
                $row['name'];
        }
    }

    $pharm_query->close();
}


/*
|--------------------------------------------------------------------------
| BRANCH NAME
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

        if (!empty($row['branch_name'])) {
            $display_branch_name =
                $row['branch_name'];
        }
    }

    $branch_query->close();
}


/*
|--------------------------------------------------------------------------
| CATEGORY OPTIONS
|--------------------------------------------------------------------------
|
| Categories are limited to this pharmacy.
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
