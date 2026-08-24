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
 *   GET  ?action=list
 *   POST action=add
 *   POST action=delete
 *   POST action=clear_month
 *   POST action=clear_year
 *
 * Uses:
 *   pharmacy_id
 *   branch_id
 *
 * IMPORTANT:
 * This endpoint returns JSON ONLY.
 * ============================================================
 */

declare(strict_types=1);

/* ============================================================
   ERROR HANDLING
============================================================ */

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

header('Content-Type: application/json; charset=utf-8');

/* ============================================================
   DATABASE
============================================================ */

require_once '../../includes/conn.php';

/* ============================================================
   JSON RESPONSE
============================================================ */

function expense_response(
    string $status,
    string $message = '',
    array $extra = [],
    int $http_code = 200
): void {

    http_response_code($http_code);

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
   DATABASE CONNECTION CHECK
============================================================ */

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    expense_response(
        'error',
        'Database connection is unavailable.',
        [],
        500
    );
}

/* ============================================================
   SESSION
============================================================ */

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);

/*
 * Do NOT include auth.php here.
 *
 * This is an AJAX/JSON endpoint.
 * If auth.php redirects to login or prints HTML,
 * fetch() receives HTML instead of JSON.
 *
 * We therefore validate the required session values directly.
 */

if (
    $pharmacy_id <= 0 ||
    $branch_id <= 0
) {

    expense_response(
        'error',
        'Your session has expired. Please log in again.',
        [],
        401
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
    $month_total    = 0.00;

    $current_month = date('Y-m');

    /*
     * Only retrieve records belonging to the
     * currently logged-in pharmacy and branch.
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

    $stmt = $conn->prepare($sql);

    if (!$stmt) {

        error_log(
            'Expense LIST prepare failed: ' .
            $conn->error
        );

        expense_response(
            'error',
            'Unable to prepare the expense list.',
            [],
            500
        );
    }

    $stmt->bind_param(
        'ii',
        $pharmacy_id,
        $branch_id
    );

    if (!$stmt->execute()) {

        error_log(
            'Expense LIST execute failed: ' .
            $stmt->error
        );

        $stmt->close();

        expense_response(
            'error',
            'Unable to load expenses.',
            [],
            500
        );
    }

    /*
     * bind_result() works without mysqlnd.
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

        $category_value =
            (
                $category !== null &&
                trim((string)$category) !== ''
            )
                ? (string)$category
                : 'General';

        $expenses[] = [
            'id' => (int)$id,

            'name' =>
                (string)$name,

            'amount' =>
                round($amount_value, 2),

            'expense_date' =>
                $expense_date_value,

            'category' =>
                $category_value,

            'recorded_by' =>
                (int)$recorded_by,

            'created_at' =>
                (string)($created_at ?? '')
        ];

        $total_expenses +=
            $amount_value;

        /*
         * Calculate current month.
         */

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
        'Expenses loaded successfully.',
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
   ALL WRITE ACTIONS MUST BE POST
============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {

    expense_response(
        'error',
        'Invalid request method.',
        [],
        405
    );
}

/* ============================================================
   ADD EXPENSE
============================================================ */

if ($action === 'add') {

    $name = trim(
        (string)(
            $_POST['name'] ?? ''
        )
    );

    $amount = (float)(
        $_POST['amount'] ?? 0
    );

    $expense_date = trim(
        (string)(
            $_POST['date'] ?? ''
        )
    );

    $category = trim(
        (string)(
            $_POST['category'] ??
            'General'
        )
    );

    /* --------------------------------------------------------
       VALIDATE NAME
    -------------------------------------------------------- */

    if ($name === '') {

        expense_response(
            'error',
            'Please enter an expense description.',
            [],
            422
        );
    }

    if (mb_strlen($name) > 255) {

        expense_response(
            'error',
            'Expense description is too long.',
            [],
            422
        );
    }

    /* --------------------------------------------------------
       VALIDATE AMOUNT
    -------------------------------------------------------- */

    if (
        !is_finite($amount) ||
        $amount <= 0
    ) {

        expense_response(
            'error',
            'Please enter a valid expense amount.',
            [],
            422
        );
    }

    /* --------------------------------------------------------
       VALIDATE DATE
    -------------------------------------------------------- */

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $expense_date
        )
    ) {

        expense_response(
            'error',
            'Please select a valid expense date.',
            [],
            422
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
            'Invalid expense date.',
            [],
            422
        );
    }

    /* --------------------------------------------------------
       CATEGORY
    -------------------------------------------------------- */

    if (
        !in_array(
            $category,
            $allowed_categories,
            true
        )
    ) {

        $category = 'General';
    }

    $amount = round(
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

        error_log(
            'Expense INSERT prepare failed: ' .
            $conn->error
        );

        expense_response(
            'error',
            'Unable to prepare the expense record.',
            [],
            500
        );
    }

    /*
     * i = pharmacy_id
     * i = branch_id
     * s = name
     * d = amount
     * s = expense_date
     * s = category
     * i = recorded_by
     */

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

        error_log(
            'Expense INSERT failed: ' .
            $stmt->error
        );

        $stmt->close();

        expense_response(
            'error',
            'Unable to save the expense.',
            [],
            500
        );
    }

    $new_id =
        (int)$stmt->insert_id;

    $stmt->close();

    expense_response(
        'success',
        'Expense recorded successfully.',
        [
            'id' => $new_id
        ]
    );
}

/* ============================================================
   DELETE ONE EXPENSE
============================================================ */

if ($action === 'delete') {

    $expense_id =
        (int)(
            $_POST['id'] ?? 0
        );

    if ($expense_id <= 0) {

        expense_response(
            'error',
            'Invalid expense record.',
            [],
            422
        );
    }

    /*
     * Pharmacy + branch restrictions prevent
     * deleting another tenant's expense.
     */

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

        error_log(
            'Expense DELETE prepare failed: ' .
            $conn->error
        );

        expense_response(
            'error',
            'Unable to prepare the delete operation.',
            [],
            500
        );
    }

    $stmt->bind_param(
        'iii',
        $expense_id,
        $pharmacy_id,
        $branch_id
    );

    if (!$stmt->execute()) {

        error_log(
            'Expense DELETE failed: ' .
            $stmt->error
        );

        $stmt->close();

        expense_response(
            'error',
            'Unable to delete the expense.',
            [],
            500
        );
    }

    $affected =
        (int)$stmt->affected_rows;

    $stmt->close();

    if ($affected < 1) {

        expense_response(
            'error',
            'Expense not found or access denied.',
            [],
            404
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

        error_log(
            'Expense CLEAR MONTH prepare failed: ' .
            $conn->error
        );

        expense_response(
            'error',
            'Unable to prepare the clear operation.',
            [],
            500
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

        error_log(
            'Expense CLEAR MONTH failed: ' .
            $stmt->error
        );

        $stmt->close();

        expense_response(
            'error',
            'Unable to clear this month\'s expenses.',
            [],
            500
        );
    }

    $deleted =
        (int)$stmt->affected_rows;

    $stmt->close();

    expense_response(
        'success',
        'This month\'s expense records were cleared.',
        [
            'deleted' => $deleted
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

        error_log(
            'Expense CLEAR YEAR prepare failed: ' .
            $conn->error
        );

        expense_response(
            'error',
            'Unable to prepare the clear operation.',
            [],
            500
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

        error_log(
            'Expense CLEAR YEAR failed: ' .
            $stmt->error
        );

        $stmt->close();

        expense_response(
            'error',
            'Unable to clear this year\'s expenses.',
            [],
            500
        );
    }

    $deleted =
        (int)$stmt->affected_rows;

    $stmt->close();

    expense_response(
        'success',
        'This year\'s expense records were cleared.',
        [
            'deleted' => $deleted
        ]
    );
}

/* ============================================================
   UNKNOWN ACTION
============================================================ */

expense_response(
    'error',
    'Unknown expense action.',
    [],
    400
);
