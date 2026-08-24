<?php
/**
 * ============================================================
 * EchoTech POS
 * EXPENSE API
 * ============================================================
 *
 * Single backend for:
 *
 *   GET  ?action=list
 *   POST action=add
 *   POST action=delete
 *   POST action=clear_month
 *   POST action=clear_year
 *
 * Uses the existing:
 *
 *   $_SESSION['pharmacy_id']
 *   $_SESSION['branch_id']
 *   $_SESSION['user_id']
 *
 * Business timezone:
 *
 *   Africa/Lusaka
 * ============================================================
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

header('Content-Type: application/json; charset=utf-8');

require_once "../../includes/conn.php";
require_once "../../includes/auth.php";


/* ============================================================
   JSON RESPONSE
============================================================ */

function expense_json(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ============================================================
   SESSION
============================================================ */

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);


if ($pharmacy_id <= 0 || $branch_id <= 0) {

    expense_json(
        [
            'status'  => 'error',
            'message' => 'Your session has expired. Please log in again.'
        ],
        401
    );
}


/* ============================================================
   ACTION
============================================================ */

$action = strtolower(
    trim(
        (string)(
            $_REQUEST['action'] ?? ''
        )
    )
);


/* ============================================================
   LIST EXPENSES
============================================================ */

if ($action === 'list') {

    $expenses = [];

    $total = 0.00;

    $month_total = 0.00;

    $current_month = date('Y-m');


    /*
     * IMPORTANT:
     *
     * This query deliberately follows the same structure as
     * the old fetch_expenses.php that was already working.
     *
     * pharmacy_id + branch_id are ALWAYS enforced.
     */

    $sql = "
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

        ORDER BY
            expense_date DESC,
            id DESC
    ";


    $stmt = mysqli_prepare(
        $conn,
        $sql
    );


    if (!$stmt) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to prepare expense query.',
                'debug'   => mysqli_error($conn)
            ],
            500
        );
    }


    mysqli_stmt_bind_param(
        $stmt,
        "ii",
        $pharmacy_id,
        $branch_id
    );


    if (!mysqli_stmt_execute($stmt)) {

        $error =
            mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to load expenses.',
                'debug'   => $error
            ],
            500
        );
    }


    $result =
        mysqli_stmt_get_result($stmt);


    if ($result === false) {

        $error =
            mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to read expense records.',
                'debug'   => $error
            ],
            500
        );
    }


    while (
        $row =
        mysqli_fetch_assoc($result)
    ) {

        $amount =
            (float)($row['amount'] ?? 0);

        $expense_date =
            (string)(
                $row['expense_date'] ?? ''
            );


        $expenses[] = [

            'id' =>
                (int)$row['id'],

            'name' =>
                (string)$row['name'],

            'amount' =>
                $amount,

            'expense_date' =>
                $expense_date,

            'category' =>
                (string)(
                    $row['category']
                    ?? 'General'
                ),

            'recorded_by' =>
                (int)(
                    $row['recorded_by']
                    ?? 0
                ),

            'created_at' =>
                (string)(
                    $row['created_at']
                    ?? ''
                )
        ];


        $total += $amount;


        if (
            substr(
                $expense_date,
                0,
                7
            ) === $current_month
        ) {

            $month_total +=
                $amount;
        }
    }


    mysqli_stmt_close($stmt);


    expense_json(
        [
            'status' =>
                'success',

            'expenses' =>
                $expenses,

            'total' =>
                round(
                    $total,
                    2
                ),

            'month_total' =>
                round(
                    $month_total,
                    2
                ),

            'count' =>
                count($expenses)
        ]
    );
}


/* ============================================================
   EVERYTHING BELOW REQUIRES POST
============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {

    expense_json(
        [
            'status'  => 'error',
            'message' => 'Invalid request method.'
        ],
        405
    );
}


/* ============================================================
   ADD EXPENSE
============================================================ */

if ($action === 'add') {

    $name =
        trim(
            (string)(
                $_POST['name'] ?? ''
            )
        );


    $amount =
        (float)(
            $_POST['amount'] ?? 0
        );


    $date =
        trim(
            (string)(
                $_POST['date'] ?? ''
            )
        );


    $category =
        trim(
            (string)(
                $_POST['category']
                ?? 'General'
            )
        );


    $allowed_categories = [

        'General',

        'Utilities',

        'Staff Welfare',

        'Logistics',

        'Stock/Supplies',

        'Other'
    ];


    if (
        $name === '' ||
        mb_strlen($name) > 150
    ) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Please enter a valid expense description.'
            ],
            422
        );
    }


    if (
        !is_finite($amount) ||
        $amount <= 0
    ) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Please enter a valid expense amount.'
            ],
            422
        );
    }


    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $date
        )
    ) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Please select a valid expense date.'
            ],
            422
        );
    }


    if (
        !in_array(
            $category,
            $allowed_categories,
            true
        )
    ) {

        $category = 'General';
    }


    $amount =
        round(
            $amount,
            2
        );


    $sql = "
        INSERT INTO expenses
        (
            pharmacy_id,
            branch_id,
            name,
            amount,
            expense_date,
            category,
            recorded_by
        )

        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ";


    $stmt =
        mysqli_prepare(
            $conn,
            $sql
        );


    if (!$stmt) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to prepare expense record.',
                'debug'   => mysqli_error($conn)
            ],
            500
        );
    }


    mysqli_stmt_bind_param(
        $stmt,
        "iisdssi",
        $pharmacy_id,
        $branch_id,
        $name,
        $amount,
        $date,
        $category,
        $user_id
    );


    if (
        !mysqli_stmt_execute($stmt)
    ) {

        $error =
            mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to save expense.',
                'debug'   => $error
            ],
            500
        );
    }


    $expense_id =
        mysqli_insert_id($conn);


    mysqli_stmt_close($stmt);


    expense_json(
        [
            'status'  => 'success',
            'message' => 'Expense recorded successfully.',
            'id'      => (int)$expense_id
        ]
    );
}


/* ============================================================
   DELETE EXPENSE
============================================================ */

if ($action === 'delete') {

    $id =
        (int)(
            $_POST['id'] ?? 0
        );


    if ($id <= 0) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Invalid expense record.'
            ],
            422
        );
    }


    $sql = "
        DELETE FROM expenses

        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
    ";


    $stmt =
        mysqli_prepare(
            $conn,
            $sql
        );


    if (!$stmt) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to prepare delete operation.',
                'debug'   => mysqli_error($conn)
            ],
            500
        );
    }


    mysqli_stmt_bind_param(
        $stmt,
        "iii",
        $id,
        $pharmacy_id,
        $branch_id
    );


    mysqli_stmt_execute($stmt);


    $affected =
        mysqli_stmt_affected_rows(
            $stmt
        );


    mysqli_stmt_close($stmt);


    if ($affected <= 0) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Expense not found or access denied.'
            ],
            404
        );
    }


    expense_json(
        [
            'status'  => 'success',
            'message' => 'Expense deleted successfully.'
        ]
    );
}


/* ============================================================
   CLEAR MONTH / YEAR
============================================================ */

if (
    $action === 'clear_month' ||
    $action === 'clear_year'
) {

    if ($action === 'clear_month') {

        $start =
            date('Y-m-01');

        $next =
            date(
                'Y-m-d',
                strtotime(
                    $start . ' +1 month'
                )
            );

        $label =
            'This month';

    } else {

        $start =
            date('Y-01-01');

        $next =
            date(
                'Y-m-d',
                strtotime(
                    $start . ' +1 year'
                )
            );

        $label =
            'This year';
    }


    $sql = "
        DELETE FROM expenses

        WHERE pharmacy_id = ?
          AND branch_id = ?
          AND expense_date >= ?
          AND expense_date < ?
    ";


    $stmt =
        mysqli_prepare(
            $conn,
            $sql
        );


    if (!$stmt) {

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to prepare clear operation.',
                'debug'   => mysqli_error($conn)
            ],
            500
        );
    }


    mysqli_stmt_bind_param(
        $stmt,
        "iiss",
        $pharmacy_id,
        $branch_id,
        $start,
        $next
    );


    if (
        !mysqli_stmt_execute($stmt)
    ) {

        $error =
            mysqli_stmt_error($stmt);

        mysqli_stmt_close($stmt);

        expense_json(
            [
                'status'  => 'error',
                'message' => 'Unable to clear expenses.',
                'debug'   => $error
            ],
            500
        );
    }


    $deleted =
        mysqli_stmt_affected_rows(
            $stmt
        );


    mysqli_stmt_close($stmt);


    expense_json(
        [
            'status'  => 'success',
            'message' =>
                $label .
                ' expense records were cleared.',
            'deleted' =>
                (int)$deleted
        ]
    );
}


/* ============================================================
   UNKNOWN ACTION
============================================================ */

expense_json(
    [
        'status'  => 'error',
        'message' => 'Unknown expense action.'
    ],
    400
);
