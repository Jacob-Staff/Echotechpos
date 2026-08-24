<?php
/**
 * ============================================================
 * PHARMANOVA POS
 * EXPENSE ACTION CONTROLLER
 * ============================================================
 *
 * File:
 * dashboard/actions/expenses.php
 *
 * Handles:
 *   action=list
 *   action=add
 *   action=delete
 *   action=clear_month
 *   action=clear_year
 *
 * Database:
 *   expenses
 *
 * Multi-tenant:
 *   pharmacy_id
 *   branch_id
 * ============================================================
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

header('Content-Type: application/json; charset=utf-8');


/* ============================================================
   DATABASE + AUTH
============================================================ */

require_once '../../includes/conn.php';
require_once '../../includes/auth.php';


/* ============================================================
   JSON RESPONSE HELPER
============================================================ */

function expense_response(
    string $status,
    string $message = '',
    array $extra = []
): void {

    $response = array_merge(
        [
            'status'  => $status,
            'message' => $message
        ],
        $extra
    );

    echo json_encode(
        $response,
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

    http_response_code(401);

    expense_response(
        'error',
        'Your session has expired. Please log in again.'
    );
}


/* ============================================================
   CONNECTION CHECK
============================================================ */

if (!isset($conn) || !($conn instanceof mysqli)) {

    http_response_code(500);

    expense_response(
        'error',
        'Database connection is unavailable.'
    );
}


/* ============================================================
   ACTION
============================================================ */

$action = strtolower(
    trim(
        (string)($_REQUEST['action'] ?? '')
    )
);


/* ============================================================
   ALLOWED CATEGORIES
============================================================ */

$allowed_categories = [
    'General',
    'Utilities',
    'Staff Welfare',
    'Logistics',
    'Stock/Supplies',
    'Other'
];


/* ============================================================
   LIST EXPENSES
============================================================ */

if ($action === 'list') {

    $expenses = [];

    $total_expenses = 0.00;

    $month_total = 0.00;

    $current_month = date('Y-m');


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


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        http_response_code(500);

        expense_response(
            'error',
            'Unable to prepare the expense list.'
        );
    }


    $stmt->bind_param(
        'ii',
        $pharmacy_id,
        $branch_id
    );


    if (!$stmt->execute()) {

        $stmt->close();

        http_response_code(500);

        expense_response(
            'error',
            'Unable to load expenses.'
        );
    }


    /*
     * Use bind_result instead of get_result().
     * This avoids requiring mysqlnd.
     */

    $stmt->bind_result(
        $id,
        $name,
        $amount,
        $expense_date,
        $category,
        $recorded_by,
        $created_at
    );


    while ($stmt->fetch()) {

        $amount_value = (float)$amount;

        $expense_date_value =
            (string)$expense_date;


        $expenses[] = [

            'id' => (int)$id,

            'name' =>
                (string)$name,

            'amount' =>
                $amount_value,

            'expense_date' =>
                $expense_date_value,

            'category' =>
                (string)(
                    $category !== null &&
                    $category !== ''
                        ? $category
                        : 'General'
                ),

            'recorded_by' =>
                (int)$recorded_by,

            'created_at' =>
                (string)($created_at ?? '')
        ];


        $total_expenses +=
            $amount_value;


        if (
            substr(
                $expense_date_value,
                0,
                7
            ) === $current_month
        ) {

            $month_total +=
                $amount_value;
        }
    }


    $stmt->close();


    expense_response(
        'success',
        '',
        [
            'expenses' =>
                $expenses,

            'total' =>
                round(
                    $total_expenses,
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
   ALL ACTIONS BELOW THIS POINT MUST BE POST
============================================================ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    expense_response(
        'error',
        'Invalid request method.'
    );
}


/* ============================================================
   ADD EXPENSE
============================================================ */

if ($action === 'add') {

    $name =
        trim(
            (string)($_POST['name'] ?? '')
        );


    $amount =
        (float)(
            $_POST['amount'] ?? 0
        );


    $expense_date =
        trim(
            (string)($_POST['date'] ?? '')
        );


    $category =
        trim(
            (string)(
                $_POST['category'] ??
                'General'
            )
        );


    /* --------------------------------------------------------
       VALIDATION
    -------------------------------------------------------- */

    if ($name === '') {

        expense_response(
            'error',
            'Please enter an expense description.'
        );
    }


    if (mb_strlen($name) > 255) {

        expense_response(
            'error',
            'Expense description is too long.'
        );
    }


    if (!is_finite($amount) || $amount <= 0) {

        expense_response(
            'error',
            'Please enter a valid expense amount.'
        );
    }


    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $expense_date
        )
    ) {

        expense_response(
            'error',
            'Please select a valid expense date.'
        );
    }


    $date_object =
        DateTime::createFromFormat(
            'Y-m-d',
            $expense_date
        );


    if (
        !$date_object ||
        $date_object->format('Y-m-d') !==
        $expense_date
    ) {

        expense_response(
            'error',
            'Invalid expense date.'
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


    /* --------------------------------------------------------
       INSERT
    -------------------------------------------------------- */

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
        $conn->prepare($sql);


    if (!$stmt) {

        http_response_code(500);

        expense_response(
            'error',
            'Unable to prepare the expense record.'
        );
    }


    $stmt->bind_param(
        'iisdssi',
        $pharmacy_id,
        $branch_id,
        $name,
        $amount,
        $expense_date,
        $category,
        $user_id
    );


    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        error_log(
            'Expense INSERT failed: ' .
            $error
        );


        http_response_code(500);

        expense_response(
            'error',
            'Unable to save the expense.'
        );
    }


    $new_id =
        $stmt->insert_id;


    $stmt->close();


    expense_response(
        'success',
        'Expense recorded successfully.',
        [
            'id' =>
                (int)$new_id
        ]
    );
}


/* ============================================================
   DELETE EXPENSE
============================================================ */

if ($action === 'delete') {

    $expense_id =
        (int)(
            $_POST['id'] ?? 0
        );


    if ($expense_id <= 0) {

        expense_response(
            'error',
            'Invalid expense record.'
        );
    }


    $sql = "
        DELETE FROM expenses
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
        LIMIT 1
    ";


    $stmt =
        $conn->prepare($sql);


    if (!$stmt) {

        http_response_code(500);

        expense_response(
            'error',
            'Unable to prepare the delete operation.'
        );
    }


    $stmt->bind_param(
        'iii',
        $expense_id,
        $pharmacy_id,
        $branch_id
    );


    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        error_log(
            'Expense DELETE failed: ' .
            $error
        );


        http_response_code(500);

        expense_response(
            'error',
            'Unable to delete the expense.'
        );
    }


    $affected =
        $stmt->affected_rows;


    $stmt->close();


    if ($affected < 1) {

        expense_response(
            'error',
            'Expense not found or access denied.'
        );
    }


    expense_response(
        'success',
        'Expense deleted successfully.'
    );
}


/* ============================================================
   CLEAR THIS MONTH
============================================================ */

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


    $sql = "
        DELETE FROM expenses
        WHERE pharmacy_id = ?
          AND branch_id = ?
          AND expense_date >= ?
          AND expense_date < ?
    ";


    $stmt =
        $conn->prepare($sql);


    if (!$stmt) {

        http_response_code(500);

        expense_response(
            'error',
            'Unable to prepare the clear operation.'
        );
    }


    $stmt->bind_param(
        'iiss',
        $pharmacy_id,
        $branch_id,
        $start,
        $next
    );


    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        error_log(
            'Expense clear month failed: ' .
            $error
        );


        http_response_code(500);

        expense_response(
            'error',
            'Unable to clear this month\'s expenses.'
        );
    }


    $deleted =
        $stmt->affected_rows;


    $stmt->close();


    expense_response(
        'success',
        'This month\'s expense records were cleared.',
        [
            'deleted' =>
                (int)$deleted
        ]
    );
}


/* ============================================================
   CLEAR THIS YEAR
============================================================ */

if ($action === 'clear_year') {

    $start =
        date('Y-01-01');


    $next =
        date(
            'Y-m-d',
            strtotime(
                $start . ' +1 year'
            )
        );


    $sql = "
        DELETE FROM expenses
        WHERE pharmacy_id = ?
          AND branch_id = ?
          AND expense_date >= ?
          AND expense_date < ?
    ";


    $stmt =
        $conn->prepare($sql);


    if (!$stmt) {

        http_response_code(500);

        expense_response(
            'error',
            'Unable to prepare the clear operation.'
        );
    }


    $stmt->bind_param(
        'iiss',
        $pharmacy_id,
        $branch_id,
        $start,
        $next
    );


    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        error_log(
            'Expense clear year failed: ' .
            $error
        );


        http_response_code(500);

        expense_response(
            'error',
            'Unable to clear this year\'s expenses.'
        );
    }


    $deleted =
        $stmt->affected_rows;


    $stmt->close();


    expense_response(
        'success',
        'This year\'s expense records were cleared.',
        [
            'deleted' =>
                (int)$deleted
        ]
    );
}


/* ============================================================
   UNKNOWN ACTION
============================================================ */

http_response_code(400);

expense_response(
    'error',
    'Unknown expense action.'
);
