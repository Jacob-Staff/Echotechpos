<?php
/**
 * ============================================================
 * ECHOTECH POS
 * STAFF MANAGEMENT
 * ============================================================
 *
 * Features:
 * - Staff registration
 * - 10 staff roles
 * - Branch assignment
 * - Profile image upload
 * - Account freeze / unfreeze
 * - Online visibility
 * - Account deletion
 * - 20-page access matrix
 * - Page functions matrix
 * - CSRF protection
 * - Pharmacy tenant isolation
 * - Confirmation modals
 * - Search/filter
 * - Responsive UI
 * ============================================================
 */

declare(strict_types=1);


/* ============================================================
   SESSION
   ============================================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* ============================================================
   DATABASE + AUTH
   ============================================================ */

require_once '../includes/conn.php';
require_once '../includes/auth.php';


/* ============================================================
   STAFF MANAGEMENT REQUIRES ADMIN / MANAGER
   ============================================================ */

require_admin();


/* ============================================================
   CURRENT CONTEXT
   ============================================================ */

$pharmacy_id     = current_pharmacy();
$current_user_id = current_user_id();

if (!$pharmacy_id) {

    http_response_code(403);

    exit('Pharmacy session is not available.');
}


/* ============================================================
   ROLES
   ============================================================ */

$roles = echotech_staff_roles();


/* ============================================================
   PAGES
   ============================================================ */

$pages = echotech_pages();


/* ============================================================
   ROLE ICONS
   ============================================================ */

$icons = [

    'Human Resource'     => 'fa-people-roof',
    'Pharmacist'        => 'fa-prescription-bottle-medical',
    'Manager'           => 'fa-user-tie',
    'Assistant Manager' => 'fa-user-gear',
    'PharmTech'         => 'fa-pills',
    'Clinical Officer'  => 'fa-stethoscope',
    'Registered Nurse'  => 'fa-user-nurse',
    'Cashier'           => 'fa-cash-register',
    'General'           => 'fa-user',
    'Security'          => 'fa-shield-halved'

];


/* ============================================================
   PAGE ICONS
   ============================================================ */

$pageIcons = [

    'Sale now'             => 'fa-cart-shopping',
    'Today transaction'    => 'fa-receipt',
    'Out of stock'         => 'fa-box-open',
    'Expired products'     => 'fa-calendar-xmark',
    'Customer'             => 'fa-users',
    'Online manager'       => 'fa-globe',
    'Pharmacy stock'       => 'fa-boxes-stacked',
    'Purchases orders'     => 'fa-file-invoice',
    'Supplier'             => 'fa-truck',
    'Add Product'          => 'fa-circle-plus',
    'Stock exchange'       => 'fa-right-left',
    'Shift log'            => 'fa-clock-rotate-left',
    'Purchases order list' => 'fa-list-check',
    'Restock'              => 'fa-arrows-rotate',
    'Online orders'        => 'fa-bag-shopping',
    'Sales report'         => 'fa-chart-line',
    'Lay by sale'          => 'fa-hand-holding-dollar',
    'Expenses sales trend' => 'fa-chart-area',
    'Add patient'          => 'fa-user-plus',
    'Settings'             => 'fa-gear'

];


/* ============================================================
   CREATE PAGE PERMISSION TABLE IF REQUIRED
   ============================================================ */

$conn->query(
    "CREATE TABLE IF NOT EXISTS role_page_permissions (

        id INT UNSIGNED NOT NULL AUTO_INCREMENT,

        pharmacy_id INT NOT NULL,

        role VARCHAR(100) NOT NULL,

        page_name VARCHAR(150) NOT NULL,

        can_access TINYINT(1) NOT NULL DEFAULT 0,

        can_action TINYINT(1) NOT NULL DEFAULT 0,

        updated_at TIMESTAMP
            NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),

        UNIQUE KEY uq_role_page
            (pharmacy_id, role, page_name),

        KEY idx_pharmacy
            (pharmacy_id),

        KEY idx_role
            (role)

    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci"
);


/* ============================================================
   ADD FREEZE COLUMN IF REQUIRED
   ============================================================ */

$columnCheck = $conn->query(
    "SELECT COUNT(*) AS c
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'users'
     AND COLUMN_NAME = 'is_frozen'"
);

if (
    $columnCheck
    &&
    (int) ($columnCheck->fetch_assoc()['c'] ?? 0) === 0
) {

    $conn->query(
        "ALTER TABLE users
         ADD COLUMN is_frozen
         TINYINT(1) NOT NULL DEFAULT 0"
    );
}


/* ============================================================
   CSRF TOKEN
   ============================================================ */

if (empty($_SESSION['staff_csrf'])) {

    $_SESSION['staff_csrf'] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION['staff_csrf'];


/* ============================================================
   HELPERS
   ============================================================ */

function h($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function verify_staff_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($token)
        ||
        empty($_SESSION['staff_csrf'])
        ||
        !hash_equals(
            $_SESSION['staff_csrf'],
            $token
        )
    ) {

        http_response_code(419);

        exit(
            'Invalid security token. Please reload the page and try again.'
        );
    }
}


function redirect_staff(string $query = ''): void
{
    header(
        'Location: staff_management.php'
        . ($query ? '?' . $query : '')
    );

    exit;
}


/* ============================================================
   SEED PERMISSION MATRIX
   ============================================================ */

foreach ($roles as $role) {

    foreach ($pages as $page) {

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO role_page_permissions
             (
                 pharmacy_id,
                 role,
                 page_name,
                 can_access,
                 can_action
             )
             VALUES (?, ?, ?, 0, 0)"
        );

        if ($stmt) {

            $stmt->bind_param(
                'iss',
                $pharmacy_id,
                $role,
                $page
            );

            $stmt->execute();
            $stmt->close();
        }
    }
}


/* ============================================================
   POST ACTIONS
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_staff_csrf();

    $action = $_POST['action'] ?? '';


    /* ========================================================
       PERMISSION TOGGLE
       ======================================================== */

    if ($action === 'permission') {

        $role = trim(
            (string) ($_POST['role'] ?? '')
        );

        $page = trim(
            (string) ($_POST['page'] ?? '')
        );

        $kind = trim(
            (string) ($_POST['perm'] ?? '')
        );

        if (
            !in_array($role, $roles, true)
            ||
            !in_array($page, $pages, true)
            ||
            !in_array(
                $kind,
                ['access', 'function'],
                true
            )
        ) {

            redirect_staff(
                'error=permission'
            );
        }


        /* ----------------------------------------------------
           ACCESS
           ---------------------------------------------------- */

        if ($kind === 'access') {

            $stmt = $conn->prepare(
                "SELECT can_access
                 FROM role_page_permissions
                 WHERE pharmacy_id = ?
                 AND role = ?
                 AND page_name = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                'iss',
                $pharmacy_id,
                $role,
                $page
            );

            $stmt->execute();

            $row = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            $current = (int) (
                $row['can_access'] ?? 0
            );

            $newValue = $current ? 0 : 1;


            /*
             * Turning Access OFF automatically
             * turns Functions OFF.
             */
            if ($newValue === 0) {

                $stmt = $conn->prepare(
                    "UPDATE role_page_permissions
                     SET can_access = 0,
                         can_action = 0
                     WHERE pharmacy_id = ?
                     AND role = ?
                     AND page_name = ?"
                );

            } else {

                $stmt = $conn->prepare(
                    "UPDATE role_page_permissions
                     SET can_access = 1
                     WHERE pharmacy_id = ?
                     AND role = ?
                     AND page_name = ?"
                );
            }

            $stmt->bind_param(
                'iss',
                $pharmacy_id,
                $role,
                $page
            );

            $stmt->execute();
            $stmt->close();

            redirect_staff(
                'permission_updated=1'
            );
        }


        /* ----------------------------------------------------
           FUNCTION
           ---------------------------------------------------- */

        if ($kind === 'function') {

            $stmt = $conn->prepare(
                "SELECT can_access,
                        can_action
                 FROM role_page_permissions
                 WHERE pharmacy_id = ?
                 AND role = ?
                 AND page_name = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                'iss',
                $pharmacy_id,
                $role,
                $page
            );

            $stmt->execute();

            $row = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();


            /*
             * Cannot enable Functions
             * without Access.
             */
            if (
                (int) ($row['can_access'] ?? 0) !== 1
            ) {

                redirect_staff(
                    'error=function_requires_access'
                );
            }


            $current = (int) (
                $row['can_action'] ?? 0
            );

            $newValue = $current ? 0 : 1;


            $stmt = $conn->prepare(
                "UPDATE role_page_permissions
                 SET can_action = ?
                 WHERE pharmacy_id = ?
                 AND role = ?
                 AND page_name = ?"
            );

            $stmt->bind_param(
                'iiss',
                $newValue,
                $pharmacy_id,
                $role,
                $page
            );

            $stmt->execute();
            $stmt->close();

            redirect_staff(
                'permission_updated=1'
            );
        }
    }


    /* ========================================================
       ADD STAFF
       ======================================================== */

    if ($action === 'add') {

        $username = trim(
            (string) ($_POST['username'] ?? '')
        );

        $fullName = trim(
            (string) ($_POST['full_name'] ?? '')
        );

        $email = trim(
            (string) ($_POST['email'] ?? '')
        );

        $role = trim(
            (string) ($_POST['role'] ?? 'General')
        );

        $branchId = (int) (
            $_POST['branch_id'] ?? 0
        );

        $salary = (float) (
            $_POST['salary'] ?? 0
        );

        $rawPassword =
            (string) ($_POST['password'] ?? '');


        if (!in_array(
            $role,
            $roles,
            true
        )) {
            $role = 'General';
        }


        if (
            $username === ''
            ||
            $fullName === ''
            ||
            $email === ''
            ||
            $rawPassword === ''
            ||
            $branchId <= 0
        ) {

            redirect_staff(
                'error=missing'
            );
        }


        /* ----------------------------------------------------
           VERIFY BRANCH BELONGS TO PHARMACY
           ---------------------------------------------------- */

        $stmt = $conn->prepare(
            "SELECT id
             FROM branches
             WHERE id = ?
             AND pharmacy_id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            'ii',
            $branchId,
            $pharmacy_id
        );

        $stmt->execute();

        $branchExists =
            $stmt->get_result()->num_rows > 0;

        $stmt->close();


        if (!$branchExists) {

            redirect_staff(
                'error=branch'
            );
        }


        /* ----------------------------------------------------
           DUPLICATE USERNAME
           ---------------------------------------------------- */

        $stmt = $conn->prepare(
            "SELECT id
             FROM users
             WHERE username = ?
             AND pharmacy_id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            'si',
            $username,
            $pharmacy_id
        );

        $stmt->execute();

        $duplicate =
            $stmt->get_result()->num_rows > 0;

        $stmt->close();


        if ($duplicate) {

            redirect_staff(
                'error=username'
            );
        }


        /* ----------------------------------------------------
           PASSWORD
           ---------------------------------------------------- */

        $passwordHash =
            password_hash(
                $rawPassword,
                PASSWORD_DEFAULT
            );


        /* ----------------------------------------------------
           PROFILE IMAGE
           ---------------------------------------------------- */

        $profilePic =
            'default_avatar.png';

        if (
            !empty($_FILES['profile_pic']['name'])
            &&
            (
                $_FILES['profile_pic']['error']
                ??
                UPLOAD_ERR_NO_FILE
            ) === UPLOAD_ERR_OK
        ) {

            $targetDir =
                '../uploads/staff/';

            if (!is_dir($targetDir)) {

                mkdir(
                    $targetDir,
                    0755,
                    true
                );
            }

            $tmp =
                $_FILES['profile_pic']['tmp_name'];

            $imageInfo =
                @getimagesize($tmp);

            $allowedMimes = [

                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif'

            ];


            if (
                $imageInfo
                &&
                isset(
                    $allowedMimes[
                        $imageInfo['mime']
                    ]
                )
            ) {

                $fileName =
                    time()
                    . '_'
                    . bin2hex(
                        random_bytes(5)
                    )
                    . '.'
                    . $allowedMimes[
                        $imageInfo['mime']
                    ];

                $targetFile =
                    $targetDir
                    . $fileName;


                if (
                    move_uploaded_file(
                        $tmp,
                        $targetFile
                    )
                ) {

                    $profilePic =
                        $fileName;
                }
            }
        }


        /* ----------------------------------------------------
           INSERT USER
           ---------------------------------------------------- */

        $stmt = $conn->prepare(
            "INSERT INTO users
            (
                pharmacy_id,
                username,
                full_name,
                email,
                password,
                role,
                branch_id,
                salary_amount,
                profile_pic,
                status,
                is_frozen
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'Active',
                0
            )"
        );


        if (!$stmt) {

            redirect_staff(
                'error=save'
            );
        }


        $stmt->bind_param(
            'isssssids',
            $pharmacy_id,
            $username,
            $fullName,
            $email,
            $passwordHash,
            $role,
            $branchId,
            $salary,
            $profilePic
        );


        if ($stmt->execute()) {

            $stmt->close();

            redirect_staff(
                'success=1'
            );
        }


        $stmt->close();

        redirect_staff(
            'error=save'
        );
    }


    /* ========================================================
       FREEZE / UNFREEZE
       ======================================================== */

    if ($action === 'toggle_freeze') {

        $targetId = (int) (
            $_POST['target_id'] ?? 0
        );

        /*
         * Never allow the currently logged-in
         * administrator to freeze themselves.
         */
        if (
            $targetId > 0
            &&
            $targetId !== $current_user_id
        ) {

            $stmt = $conn->prepare(
                "UPDATE users
                 SET is_frozen =
                     IF(is_frozen = 1, 0, 1)
                 WHERE id = ?
                 AND pharmacy_id = ?"
            );

            $stmt->bind_param(
                'ii',
                $targetId,
                $pharmacy_id
            );

            $stmt->execute();
            $stmt->close();
        }

        redirect_staff(
            'freeze_updated=1'
        );
    }


    /* ========================================================
       ONLINE VISIBILITY
       ======================================================== */

    if ($action === 'toggle_online') {

        $targetId = (int) (
            $_POST['target_id'] ?? 0
        );

        if ($targetId > 0) {

            $stmt = $conn->prepare(
                "UPDATE users
                 SET is_online_visible =
                     IF(
                         COALESCE(
                             is_online_visible,
                             0
                         ) = 1,
                         0,
                         1
                     )
                 WHERE id = ?
                 AND pharmacy_id = ?"
            );

            $stmt->bind_param(
                'ii',
                $targetId,
                $pharmacy_id
            );

            $stmt->execute();
            $stmt->close();
        }

        redirect_staff(
            'online_updated=1'
        );
    }


    /* ========================================================
       DELETE STAFF
       ======================================================== */

    if ($action === 'delete_staff') {

        $targetId = (int) (
            $_POST['target_id'] ?? 0
        );


        /*
         * Never allow self deletion.
         */
        if (
            $targetId > 0
            &&
            $targetId !== $current_user_id
        ) {

            $stmt = $conn->prepare(
                "DELETE FROM users
                 WHERE id = ?
                 AND pharmacy_id = ?"
            );

            $stmt->bind_param(
                'ii',
                $targetId,
                $pharmacy_id
            );

            $stmt->execute();
            $stmt->close();
        }

        redirect_staff(
            'deleted=1'
        );
    }
}


/* ============================================================
   LOAD BRANCHES
   ============================================================ */

$branches = [];


$stmt = $conn->prepare(
    "SELECT id, branch_name
     FROM branches
     WHERE pharmacy_id = ?
     ORDER BY branch_name ASC"
);

$stmt->bind_param(
    'i',
    $pharmacy_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $branches[] = $row;
}

$stmt->close();


/* ============================================================
   LOAD STAFF
   ============================================================ */

$staff = [];


$stmt = $conn->prepare(
    "SELECT
        u.id,
        u.username,
        u.full_name,
        u.email,
        u.role,
        u.branch_id,
        u.salary_amount,
        u.profile_pic,
        u.status,

        COALESCE(
            u.is_online_visible,
            0
        ) AS is_online_visible,

        COALESCE(
            u.is_frozen,
            0
        ) AS is_frozen,

        b.branch_name

     FROM users u

     LEFT JOIN branches b
       ON b.id = u.branch_id
      AND b.pharmacy_id = u.pharmacy_id

     WHERE u.pharmacy_id = ?

     ORDER BY
        u.is_frozen DESC,
        CASE
            WHEN u.status = 'Active'
            THEN 0
            ELSE 1
        END,
        u.full_name ASC,
        u.username ASC"
);

$stmt->bind_param(
    'i',
    $pharmacy_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $staff[] = $row;
}

$stmt->close();


/* ============================================================
   LOAD PERMISSIONS
   ============================================================ */

$permissions = [];


$stmt = $conn->prepare(
    "SELECT
        role,
        page_name,
        can_access,
        can_action
     FROM role_page_permissions
     WHERE pharmacy_id = ?"
);

$stmt->bind_param(
    'i',
    $pharmacy_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $permissions[
        $row['role']
    ][
        $row['page_name']
    ] = [

        'access' =>
            (int) $row['can_access'],

        'action' =>
            (int) $row['can_action']

    ];
}

$stmt->close();


/* ============================================================
   SUMMARY
   ============================================================ */

$totalStaff = count($staff);

$activeStaff = 0;
$frozenStaff = 0;
$onlineStaff = 0;

$roleCounts = [];


foreach ($staff as $member) {

    if (
        strcasecmp(
            (string) $member['status'],
            'Active'
        ) === 0
    ) {

        $activeStaff++;
    }


    if (
        (int) $member['is_frozen'] === 1
    ) {

        $frozenStaff++;
    }


    if (
        (int) $member['is_online_visible'] === 1
    ) {

        $onlineStaff++;
    }


    $memberRole =
        $member['role']
        ?: 'General';


    $roleCounts[$memberRole] =
        ($roleCounts[$memberRole] ?? 0)
        + 1;
}


$activePercent =
    $totalStaff > 0
        ? round(
            ($activeStaff / $totalStaff)
            * 100
        )
        : 0;


/* ============================================================
   CURRENT USER
   ============================================================ */

$user =
    current_user();

$roleNow =
    current_role();


/* ============================================================
   FLASH MESSAGES
   ============================================================ */

$flash = '';
$isError = false;


if (isset($_GET['success'])) {

    $flash =
        'Staff account created successfully.';

} elseif (isset($_GET['freeze_updated'])) {

    $flash =
        'Staff account freeze status updated.';

} elseif (isset($_GET['online_updated'])) {

    $flash =
        'Staff online visibility updated.';

} elseif (isset($_GET['deleted'])) {

    $flash =
        'Staff account deleted.';

} elseif (isset($_GET['permission_updated'])) {

    $flash =
        'Page permission updated successfully.';

} elseif (isset($_GET['error'])) {

    $isError = true;

    $messages = [

        'missing' =>
            'Complete all required staff fields.',

        'branch' =>
            'The selected branch is invalid for this pharmacy.',

        'username' =>
            'That username already exists.',

        'save' =>
            'The staff account could not be created.',

        'permission' =>
            'Invalid permission request.',

        'function_requires_access' =>
            'Enable Access before enabling Functions.'

    ];

    $flash =
        $messages[
            $_GET['error']
        ]
        ??
        'Action could not be completed.';
}

?>
<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Staff Management | EchoTech POS
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        rel="stylesheet"
    >


<style>

:root {

    --charcoal: #202831;
    --charcoal-2: #2b3540;

    --bg: #f4f6f8;
    --white: #ffffff;

    --text: #1d252d;
    --muted: #6d7782;

    --border: #dfe4e9;

    --blue: #246bfe;
    --blue-soft: #eaf1ff;

    --green: #159a68;
    --green-soft: #e8f7f0;

    --red: #d94d61;
    --red-soft: #fff0f2;

    --yellow: #e7a72e;
    --yellow-soft: #fff6df;

    --sidebar: 250px;

    --shadow:
        0 4px 18px
        rgba(31,40,49,.06);

    --radius: 12px;
}


* {
    box-sizing: border-box;
}


html,
body {

    margin: 0;
    min-height: 100%;

    background: var(--bg);
    color: var(--text);

    font-family:
        Inter,
        Arial,
        sans-serif;

    font-size: 14px;
}


body {
    overflow-x: hidden;
}


button,
input,
select {
    font: inherit;
}


a {
    text-decoration: none;
}


/* ============================================================
   SIDEBAR
   ============================================================ */

.sidebar {

    position: fixed;

    inset: 0 auto 0 0;

    width: var(--sidebar);

    background:
        var(--charcoal);

    padding:
        17px 14px 80px;

    z-index: 1000;

    overflow-y: auto;

    border-right:
        1px solid #151b21;
}


.brand {

    display: flex;

    align-items: center;

    gap: 11px;

    padding:
        4px 9px 18px;

    color: #fff;
}


.brand-mark {

    width: 38px;
    height: 38px;

    border-radius: 10px;

    display: grid;
    place-items: center;

    background: var(--blue);
}


.brand-mark i {
    font-size: 16px;
}


.brand strong {

    display: block;

    color: #fff;

    font-size: 15px;
    font-weight: 800;
}


.brand small {

    display: block;

    color: #aeb8c2;

    font-size: 9px;

    letter-spacing: 1px;

    text-transform: uppercase;

    margin-top: 2px;
}


.side-user {

    display: flex;

    align-items: center;

    gap: 9px;

    background: #18212a;

    border:
        1px solid #35414d;

    border-radius: 9px;

    padding: 10px;

    margin:
        0 7px 14px;
}


.avatar {

    width: 33px;
    height: 33px;

    border-radius: 50%;

    display: grid;
    place-items: center;

    background: #3b4857;

    color: #fff;

    font-size: 11px;
    font-weight: 800;

    flex: none;
}


.side-copy {
    min-width: 0;
}


.side-copy b {

    display: block;

    color: #fff;

    font-size: 11px;

    white-space: nowrap;

    overflow: hidden;

    text-overflow: ellipsis;
}


.side-copy span {

    display: block;

    color: #9ba7b3;

    font-size: 9px;

    margin-top: 2px;
}


.caption {

    padding:
        12px 11px 7px;

    color: #8e9aa6;

    font-size: 10px;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: 1px;
}


.nav {

    display: flex;

    flex-direction: column;

    gap: 3px;
}


.nav a {

    min-height: 42px;

    border-radius: 8px;

    display: flex;

    align-items: center;

    gap: 11px;

    padding:
        0 12px;

    color: #bdc6cf;

    font-size: 13px;

    font-weight: 600;

    transition: .18s ease;
}


.nav a i {

    width: 18px;

    text-align: center;

    color: #8996a3;
}


.nav a:hover {

    background: #2a3541;

    color: #fff;
}


.nav a.active {

    background: #344253;

    color: #fff;

    box-shadow:
        inset 3px 0 var(--blue);
}


.nav a.active i {
    color: #70a0ff;
}


.logout {

    position: absolute;

    left: 14px;
    right: 14px;
    bottom: 13px;

    color: #f17a8b !important;
}


/* ============================================================
   MAIN
   ============================================================ */

.main {

    margin-left: var(--sidebar);

    min-height: 100vh;
}


.topbar {

    height: 64px;

    position: sticky;

    top: 0;

    z-index: 900;

    background: #fff;

    border-bottom:
        1px solid var(--border);

    display: flex;

    align-items: center;

    justify-content: space-between;

    padding:
        0 28px;
}


.top-left,
.top-right {

    display: flex;

    align-items: center;

    gap: 9px;
}


.mobile-btn {

    display: none;

    width: 36px;
    height: 36px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #fff;

    color: var(--charcoal);
}


.top-title b {

    display: block;

    font-size: 13px;
}


.top-title span {

    display: block;

    font-size: 10px;

    color: var(--muted);

    margin-top: 2px;
}


.top-search {

    width: 220px;

    height: 37px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    padding:
        0 12px;

    outline: none;

    font-size: 12px;
}


.top-search:focus {

    border-color: #8bb0ff;

    box-shadow:
        0 0 0 3px var(--blue-soft);
}


.top-icon {

    width: 37px;
    height: 37px;

    display: grid;
    place-items: center;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #fff;

    color: #65717d;
}


.top-icon:hover {
    color: var(--blue);
}


.top-branch {

    height: 37px;

    padding:
        0 11px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    display: flex;

    align-items: center;

    gap: 7px;

    background: #fff;

    color: #5f6b77;

    font-size: 11px;
}


.top-branch i {
    color: var(--blue);
}


/* ============================================================
   CONTENT
   ============================================================ */

.content {

    max-width: 1700px;

    margin: auto;

    padding:
        26px 28px 45px;
}


.page-head {

    display: flex;

    align-items: flex-end;

    justify-content: space-between;

    gap: 15px;

    margin-bottom: 17px;
}


.eyebrow {

    color: var(--blue);

    font-size: 10px;

    font-weight: 900;

    text-transform: uppercase;

    letter-spacing: 1.1px;
}


.page-head h1 {

    margin:
        5px 0 0;

    font-size: 27px;

    color: var(--charcoal);

    font-weight: 800;
}


.page-head p {

    margin:
        6px 0 0;

    color: var(--muted);

    font-size: 12px;
}


.page-actions {

    display: flex;

    gap: 8px;
}


.btn-main {

    height: 39px;

    padding:
        0 14px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #fff;

    color: #52606c;

    font-size: 12px;

    font-weight: 700;
}


.btn-main.primary {

    background: var(--blue);

    border-color: var(--blue);

    color: #fff;
}


/* ============================================================
   SUMMARY
   ============================================================ */

.summary {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 13px;

    margin-bottom: 14px;
}


.summary-card {

    min-height: 118px;

    background: #fff;

    border:
        1px solid var(--border);

    border-radius: var(--radius);

    box-shadow: var(--shadow);

    padding: 16px;
}


.summary-top {

    display: flex;

    align-items: center;

    justify-content: space-between;
}


.summary-label {

    color: #74808b;

    font-size: 10px;

    font-weight: 800;

    text-transform: uppercase;

    letter-spacing: .8px;
}


.summary-icon {

    width: 34px;
    height: 34px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--blue-soft);

    color: var(--blue);
}


.summary-card.green
.summary-icon {

    background: var(--green-soft);

    color: var(--green);
}


.summary-card.red
.summary-icon {

    background: var(--red-soft);

    color: var(--red);
}


.summary-value {

    margin-top: 10px;

    font-size: 25px;

    font-weight: 800;

    color: var(--charcoal);
}


.summary-sub {

    margin-top: 6px;

    font-size: 10px;

    color: var(--muted);
}


/* ============================================================
   PANEL
   ============================================================ */

.panel {

    background: #fff;

    border:
        1px solid var(--border);

    border-radius: var(--radius);

    box-shadow: var(--shadow);

    overflow: hidden;
}


.panel-head {

    min-height: 59px;

    padding:
        0 17px;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 10px;

    border-bottom:
        1px solid var(--border);
}


.panel-head-left {

    display: flex;

    align-items: center;

    gap: 10px;
}


.panel-icon {

    width: 33px;
    height: 33px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--blue-soft);

    color: var(--blue);
}


.panel h2 {

    margin: 0;

    font-size: 14px;

    font-weight: 800;
}


.panel-head p {

    margin:
        3px 0 0;

    font-size: 10px;

    color: var(--muted);
}


.count {

    background: #eef1f4;

    color: #66727d;

    border-radius: 12px;

    padding:
        4px 8px;

    font-size: 9px;

    font-weight: 800;
}


/* ============================================================
   STAFF TOOLS
   ============================================================ */

.staff-tools {

    padding: 14px 17px;

    background: #fbfcfd;

    border-bottom:
        1px solid var(--border);

    display: grid;

    grid-template-columns:
        minmax(220px, 1.5fr)
        190px
        190px
        auto;

    gap: 8px;
}


.field,
.tool-btn {

    height: 39px;

    border:
        1px solid var(--border);

    border-radius: 8px;

    background: #fff;

    padding:
        0 11px;

    color: #56626e;

    font-size: 12px;

    outline: none;
}


.field:focus {

    border-color: #8bb0ff;

    box-shadow:
        0 0 0 3px var(--blue-soft);
}


.tool-btn {

    font-weight: 700;

    cursor: pointer;
}


/* ============================================================
   STAFF TABLE
   ============================================================ */

.staff-wrap {
    overflow: auto;
}


.staff-table {

    width: 100%;

    min-width: 1080px;

    border-collapse: collapse;
}


.staff-table th {

    background: #f7f9fb;

    color: #6b7681;

    font-size: 9px;

    text-transform: uppercase;

    letter-spacing: .5px;

    padding: 12px;

    border-bottom:
        1px solid var(--border);

    white-space: nowrap;
}


.staff-table td {

    padding: 12px;

    border-bottom:
        1px solid #edf0f3;

    font-size: 11px;

    vertical-align: middle;
}


.staff-table tbody tr:hover {
    background: #fbfcfe;
}


.member {

    display: flex;

    align-items: center;

    gap: 10px;

    min-width: 230px;
}


.staff-pic {

    width: 42px;
    height: 42px;

    border-radius: 10px;

    object-fit: cover;

    border:
        1px solid #dbe1e6;

    background: #f1f4f7;
}


.member-name {

    font-size: 12px;

    font-weight: 800;

    color: var(--charcoal);
}


.member-email {

    margin-top: 3px;

    font-size: 9px;

    color: #8a949e;
}


.pill {

    display: inline-flex;

    align-items: center;

    gap: 5px;

    border-radius: 20px;

    padding:
        5px 8px;

    font-size: 9px;

    font-weight: 800;

    white-space: nowrap;
}


.role-pill {

    background: #eef2f7;

    color: #596674;
}


.ok-pill {

    background: var(--green-soft);

    color: var(--green);
}


.off-pill {

    background: #f1f3f5;

    color: #7d8790;
}


.frozen-pill {

    background: var(--red-soft);

    color: var(--red);
}


.clear-pill {

    background: var(--green-soft);

    color: var(--green);
}


.salary {

    font-size: 11px;

    font-weight: 800;

    color: var(--charcoal);
}


.row-actions {

    display: flex;

    justify-content: center;

    gap: 5px;
}


.action-btn {

    min-height: 32px;

    padding:
        0 9px;

    border:
        1px solid var(--border);

    border-radius: 7px;

    background: #fff;

    color: #697580;

    font-size: 9px;

    font-weight: 800;

    cursor: pointer;
}


.action-btn:hover {

    color: var(--blue);

    border-color: #abc0eb;
}


.action-btn.freeze:hover {

    color: var(--red);

    background: var(--red-soft);
}


.action-btn.unfreeze:hover {

    color: var(--green);

    background: var(--green-soft);
}


.action-btn.delete:hover {

    color: var(--red);

    border-color: #efc0c8;

    background: var(--red-soft);
}


/* ============================================================
   LOWER CARDS
   ============================================================ */

.lower-grid {

    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 14px;

    margin-top: 14px;
}


.side-card {

    padding: 16px;
}


.side-title {

    font-size: 13px;

    font-weight: 800;

    color: var(--charcoal);

    margin-bottom: 12px;
}


.role-item {

    display: flex;

    align-items: center;

    gap: 10px;

    padding:
        9px 0;

    border-bottom:
        1px solid #edf0f3;
}


.role-item:last-child {
    border-bottom: 0;
}


.role-icon {

    width: 31px;
    height: 31px;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: #f1f4f7;

    color: #65727f;
}


.role-copy {

    flex: 1;
}


.role-copy b {

    display: block;

    font-size: 10px;
}


.role-copy span {

    display: block;

    font-size: 8px;

    color: var(--muted);

    margin-top: 2px;
}


.role-number {

    font-size: 11px;

    font-weight: 800;
}


.security-box {

    padding: 14px;

    border-radius: 9px;

    margin-bottom: 10px;
}


.security-box.red {

    background: var(--red-soft);

    border:
        1px solid #f2d1d7;
}


.security-box.green {

    background: var(--green-soft);

    border:
        1px solid #c8e9d9;
}


.security-label {

    font-size: 9px;

    font-weight: 800;

    color: #68737e;
}


.security-number {

    margin-top: 4px;

    font-size: 25px;

    font-weight: 800;
}


.security-box.red
.security-number {

    color: var(--red);
}


.security-box.green
.security-number {

    color: var(--green);
}


.security-text {

    font-size: 9px;

    color: var(--muted);
}


/* ============================================================
   PERMISSION MATRIX
   ============================================================ */

.permission-section {

    margin-top: 14px;
}


.legend {

    display: flex;

    align-items: center;

    gap: 15px;

    padding:
        10px 17px;

    border-bottom:
        1px solid var(--border);

    color: #71808c;

    font-size: 9px;
}


.legend-dot {

    width: 8px;
    height: 8px;

    display: inline-block;

    border-radius: 50%;

    margin-right: 4px;
}


.legend-blue {
    background: var(--blue);
}


.legend-green {
    background: var(--green);
}


.legend-gray {
    background: #aab2ba;
}


.permission-search {

    padding:
        12px 17px;

    border-bottom:
        1px solid var(--border);
}


.permission-search .field {

    max-width: 320px;

    width: 100%;
}


.permission-wrap {

    overflow: auto;
}


.permission-table {

    width: 100%;

    min-width: 1350px;

    border-collapse: collapse;
}


.permission-table th,
.permission-table td {

    border-bottom:
        1px solid #edf0f3;

    padding: 9px;

    text-align: center;

    font-size: 9px;
}


.permission-table th {

    background: #f7f9fb;

    color: #697581;

    text-transform: uppercase;

    font-size: 8px;

    position: sticky;

    top: 0;

    z-index: 2;
}


.permission-table th:first-child,
.permission-table td:first-child {

    position: sticky;

    left: 0;

    z-index: 3;

    background: #fff;

    text-align: left;
}


.permission-table th:first-child {
    background: #f7f9fb;
}


.page-cell {

    display: flex;

    align-items: center;

    gap: 8px;

    min-width: 220px;
}


.page-icon {

    width: 29px;
    height: 29px;

    display: grid;
    place-items: center;

    border-radius: 7px;

    background: var(--blue-soft);

    color: var(--blue);
}


.page-cell b {

    display: block;

    color: var(--charcoal);

    font-size: 10px;
}


.page-cell span {

    display: block;

    color: var(--muted);

    font-size: 8px;

    margin-top: 2px;
}


.permission-form {
    margin: 0;
}


.permission-btn {

    width: 82px;

    height: 28px;

    border-radius: 15px;

    border:
        1px solid #dce2e7;

    background: #f3f5f7;

    color: #7a848e;

    font-size: 7px;

    font-weight: 900;

    cursor: pointer;
}


.permission-btn.access {

    background: var(--blue-soft);

    border-color: #b8d3ff;

    color: var(--blue);
}


.permission-btn.function {

    background: var(--green-soft);

    border-color: #bde5d2;

    color: var(--green);
}


.permission-btn:disabled {

    opacity: .45;

    cursor: not-allowed;
}


/* ============================================================
   MODALS
   ============================================================ */

.modal-content {

    border: 0;

    border-radius: 15px;

    overflow: hidden;

    box-shadow:
        0 24px 70px
        rgba(20,28,36,.22);
}


.modal-header {

    background: var(--charcoal);

    color: #fff;

    padding:
        17px 19px;

    border: 0;
}


.modal-title {

    font-size: 15px;

    font-weight: 800;
}


.modal-sub {

    margin-top: 3px;

    color: #aeb8c2;

    font-size: 9px;
}


.modal-body {
    padding: 20px;
}


.form-label {

    margin-bottom: 5px;

    color: #56626d;

    font-size: 10px;

    font-weight: 800;
}


.form-control,
.form-select {

    height: 40px;

    border-radius: 8px;

    font-size: 12px;
}


.confirm {

    text-align: center;

    padding:
        27px 25px 23px;
}


.confirm-icon {

    width: 55px;
    height: 55px;

    margin:
        0 auto 13px;

    border-radius: 50%;

    display: grid;
    place-items: center;

    background: var(--red-soft);

    color: var(--red);

    font-size: 21px;
}


.confirm.success
.confirm-icon {

    background: var(--green-soft);

    color: var(--green);
}


.confirm h5 {

    margin-bottom: 7px;

    color: var(--charcoal);

    font-size: 17px;

    font-weight: 800;
}


.confirm p {

    max-width: 400px;

    margin:
        0 auto;

    color: var(--muted);

    font-size: 11px;

    line-height: 1.6;
}


/* ============================================================
   TOAST
   ============================================================ */

.toast-stack {

    position: fixed;

    top: 82px;
    right: 20px;

    z-index: 2000;
}


.toast-card {

    min-width: 285px;

    max-width: 390px;

    display: flex;

    align-items: flex-start;

    gap: 10px;

    background: #fff;

    border:
        1px solid var(--border);

    border-radius: 10px;

    box-shadow:
        0 12px 35px
        rgba(31,40,49,.14);

    padding: 12px 13px;
}


.toast-icon {

    width: 31px;
    height: 31px;

    flex: none;

    display: grid;
    place-items: center;

    border-radius: 8px;

    background: var(--green-soft);

    color: var(--green);
}


.toast-card.error
.toast-icon {

    background: var(--red-soft);

    color: var(--red);
}


.toast-copy b {

    display: block;

    font-size: 11px;
}


.toast-copy span {

    display: block;

    margin-top: 2px;

    color: var(--muted);

    font-size: 9px;

    line-height: 1.4;
}


/* ============================================================
   RESPONSIVE
   ============================================================ */

@media (max-width: 1200px) {

    .summary {

        grid-template-columns:
            repeat(2, 1fr);
    }
}


@media (max-width: 950px) {

    :root {
        --sidebar: 0px;
    }


    .sidebar {

        width: 250px;

        transform:
            translateX(-100%);

        transition:
            .22s ease;

        box-shadow:
            15px 0 35px
            rgba(0,0,0,.22);
    }


    .sidebar.open {
        transform:
            translateX(0);
    }


    .mobile-btn {

        display: grid;

        place-items: center;
    }


    .top-search {
        display: none;
    }


    .topbar {

        padding:
            0 16px;
    }


    .content {

        padding:
            20px 16px 35px;
    }


    .staff-tools {

        grid-template-columns:
            1fr 1fr;
    }


    .lower-grid {

        grid-template-columns:
            1fr;
    }
}


@media (max-width: 600px) {

    .summary {

        grid-template-columns:
            1fr;
    }


    .page-head {

        flex-direction: column;

        align-items: flex-start;
    }


    .page-actions {

        width: 100%;
    }


    .page-actions .btn-main {

        flex: 1;
    }


    .staff-tools {

        grid-template-columns:
            1fr;
    }


    .top-branch {

        display: none;
    }


    .page-head h1 {

        font-size: 23px;
    }


    .legend {

        flex-wrap: wrap;
    }
}


</style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside
    class="sidebar"
    id="sidebar"
>

    <a
        class="brand"
        href="admin_dashboard.php"
    >

        <span class="brand-mark">
            <i class="fa-solid fa-capsules"></i>
        </span>

        <span>

            <strong>
                ECHOTECH POS
            </strong>

            <small>
                Administration
            </small>

        </span>

    </a>


    <div class="side-user">

        <div class="avatar">

            <?php
            echo h(
                strtoupper(
                    substr(
                        $user,
                        0,
                        1
                    )
                )
            );
            ?>

        </div>

        <div class="side-copy">

            <b>
                <?php echo h($user); ?>
            </b>

            <span>
                <?php echo h($roleNow ?: 'Admin'); ?>
            </span>

        </div>

    </div>


    <div class="caption">
        Workspace
    </div>


    <nav class="nav">

        <a href="admin_dashboard.php">

            <i class="fa-solid fa-chart-pie"></i>

            Dashboard

        </a>


        <a
            class="active"
            href="staff_management.php"
        >

            <i class="fa-solid fa-user-shield"></i>

            Staff Management

        </a>


        <a href="customers.php">

            <i class="fa-solid fa-users"></i>

            Customers

        </a>


        <a href="sales_report.php">

            <i class="fa-solid fa-chart-line"></i>

            Sales Reports

        </a>


        <a href="pharmacy_stock.php">

            <i class="fa-solid fa-boxes-stacked"></i>

            Inventory

        </a>


        <a href="online_orders.php">

            <i class="fa-solid fa-bag-shopping"></i>

            Online Orders

        </a>

    </nav>


    <div class="caption">
        Administration
    </div>


    <nav class="nav">

        <a href="suppliers.php">

            <i class="fa-solid fa-truck"></i>

            Suppliers

        </a>


        <a href="expenses.php">

            <i class="fa-solid fa-wallet"></i>

            Expenses

        </a>

    </nav>


    <a
        class="nav logout"
        href="../logout.php"
    >

        <i class="fa-solid fa-right-from-bracket"></i>

        Logout

    </a>

</aside>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


<header class="topbar">


    <div class="top-left">

        <button
            class="mobile-btn"
            type="button"
            onclick="toggleSidebar()"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


        <div class="top-title">

            <b>
                Staff & Access Control
            </b>

            <span>
                Manage accounts, security and POS permissions
            </span>

        </div>

    </div>


    <div class="top-right">

        <input
            type="text"
            id="globalSearch"
            class="top-search"
            placeholder="Search staff..."
        >


        <div class="top-branch">

            <i class="fa-solid fa-building"></i>

            <?php echo count($branches); ?>

            branch<?php echo count($branches) === 1 ? '' : 'es'; ?>

        </div>


        <button
            type="button"
            class="top-icon"
        >

            <i class="fa-regular fa-bell"></i>

        </button>


        <a
            class="top-icon"
            href="admin_dashboard.php"
        >

            <i class="fa-solid fa-house"></i>

        </a>

    </div>

</header>


<section class="content">


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="page-head">

    <div>

        <div class="eyebrow">
            Administration
        </div>

        <h1>
            Staff Management
        </h1>

        <p>
            Manage staff accounts, roles, security and access to every POS page.
        </p>

    </div>


    <div class="page-actions">

        <button
            class="btn-main"
            type="button"
            onclick="window.print()"
        >

            <i class="fa-solid fa-print me-1"></i>

            Print

        </button>


        <button
            class="btn-main primary"
            type="button"
            data-bs-toggle="modal"
            data-bs-target="#addStaffModal"
        >

            <i class="fa-solid fa-user-plus me-1"></i>

            Register Staff

        </button>

    </div>

</div>


<!-- =========================================================
     SUMMARY
========================================================= -->

<div class="summary">


    <div class="summary-card">

        <div class="summary-top">

            <span class="summary-label">
                Total Staff
            </span>

            <span class="summary-icon">
                <i class="fa-solid fa-users"></i>
            </span>

        </div>

        <div class="summary-value">
            <?php echo $totalStaff; ?>
        </div>

        <div class="summary-sub">
            Staff accounts in this pharmacy
        </div>

    </div>


    <div class="summary-card green">

        <div class="summary-top">

            <span class="summary-label">
                Active Staff
            </span>

            <span class="summary-icon">
                <i class="fa-solid fa-user-check"></i>
            </span>

        </div>

        <div class="summary-value">
            <?php echo $activeStaff; ?>
        </div>

        <div class="summary-sub">
            <?php echo $activePercent; ?>% currently active
        </div>

    </div>


    <div class="summary-card">

        <div class="summary-top">

            <span class="summary-label">
                Online Visibility
            </span>

            <span class="summary-icon">
                <i class="fa-solid fa-eye"></i>
            </span>

        </div>

        <div class="summary-value">
            <?php echo $onlineStaff; ?>
        </div>

        <div class="summary-sub">
            Staff marked visible online
        </div>

    </div>


    <div class="summary-card red">

        <div class="summary-top">

            <span class="summary-label">
                Frozen Accounts
            </span>

            <span class="summary-icon">
                <i class="fa-solid fa-snowflake"></i>
            </span>

        </div>

        <div class="summary-value">
            <?php echo $frozenStaff; ?>
        </div>

        <div class="summary-sub">
            Accounts currently frozen
        </div>

    </div>

</div>


<!-- =========================================================
     STAFF DIRECTORY
========================================================= -->

<section class="panel">


    <div class="panel-head">

        <div class="panel-head-left">

            <div class="panel-icon">

                <i class="fa-solid fa-id-card"></i>

            </div>


            <div>

                <h2>
                    Staff Directory
                </h2>

                <p>
                    Search and manage every staff account.
                </p>

            </div>

        </div>


        <span
            class="count"
            id="staffCount"
        >

            <?php echo $totalStaff; ?>

            Record<?php echo $totalStaff === 1 ? '' : 's'; ?>

        </span>

    </div>


    <div class="staff-tools">

        <input
            id="staffSearch"
            class="field"
            type="text"
            placeholder="Search name, username or email..."
        >


        <select
            id="roleFilter"
            class="field"
        >

            <option value="">
                All Roles
            </option>

            <?php foreach ($roles as $role): ?>

                <option
                    value="<?php echo h(strtolower($role)); ?>"
                >
                    <?php echo h($role); ?>
                </option>

            <?php endforeach; ?>

        </select>


        <select
            id="branchFilter"
            class="field"
        >

            <option value="">
                All Branches
            </option>

            <?php foreach ($branches as $branch): ?>

                <option
                    value="<?php echo (int) $branch['id']; ?>"
                >

                    <?php echo h(
                        $branch['branch_name']
                    ); ?>

                </option>

            <?php endforeach; ?>

        </select>


        <button
            class="tool-btn"
            type="button"
            onclick="resetStaffFilters()"
        >

            Reset

        </button>

    </div>


    <div class="staff-wrap">

        <table class="staff-table">

            <thead>

                <tr>

                    <th>
                        Staff Member
                    </th>

                    <th>
                        Role
                    </th>

                    <th>
                        Branch
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Online
                    </th>

                    <th>
                        Security
                    </th>

                    <th>
                        Salary
                    </th>

                    <th>
                        Actions
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if (!$staff): ?>

                <tr>

                    <td
                        colspan="8"
                        class="text-center py-5 text-muted"
                    >

                        <i
                            class="fa-solid fa-users-slash fa-2x mb-3"
                        ></i>

                        <div>
                            No staff accounts found.
                        </div>

                    </td>

                </tr>

            <?php endif; ?>


            <?php foreach ($staff as $member): ?>

                <?php

                $id =
                    (int) $member['id'];

                $name =
                    trim(
                        $member['full_name']
                        ?: $member['username']
                    );

                $frozen =
                    (int) $member['is_frozen'] === 1;

                $online =
                    (int) $member['is_online_visible'] === 1;

                $active =
                    strcasecmp(
                        (string) $member['status'],
                        'Active'
                    ) === 0;

                $profile =
                    $member['profile_pic']
                    ?: 'default_avatar.png';

                $profileUrl =
                    '../uploads/staff/'
                    . rawurlencode(
                        $profile
                    );

                $roleName =
                    $member['role']
                    ?: 'General';

                ?>


                <tr
                    class="staff-row"

                    data-search="<?php
                        echo h(
                            strtolower(
                                $name
                                . ' '
                                . $member['username']
                                . ' '
                                . $member['email']
                            )
                        );
                    ?>"

                    data-role="<?php
                        echo h(
                            strtolower(
                                $roleName
                            )
                        );
                    ?>"

                    data-branch="<?php
                        echo (int)
                            $member['branch_id'];
                    ?>"
                >


                    <td>

                        <div class="member">

                            <img
                                class="staff-pic"
                                src="<?php echo h($profileUrl); ?>"
                                alt=""
                            >


                            <div>

                                <div class="member-name">

                                    <?php
                                    echo h($name);
                                    ?>

                                </div>


                                <div class="member-email">

                                    @<?php
                                    echo h(
                                        $member['username']
                                    );
                                    ?>

                                    <?php if (!empty($member['email'])): ?>

                                        ·

                                        <?php
                                        echo h(
                                            $member['email']
                                        );
                                        ?>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </td>


                    <td>

                        <span class="pill role-pill">

                            <i
                                class="fa-solid <?php
                                    echo h(
                                        $icons[$roleName]
                                        ?? 'fa-user'
                                    );
                                ?>"
                            ></i>

                            <?php
                            echo h($roleName);
                            ?>

                        </span>

                    </td>


                    <td>

                        <?php
                        echo h(
                            $member['branch_name']
                            ?: 'Unassigned'
                        );
                        ?>

                    </td>


                    <td>

                        <span
                            class="pill <?php
                                echo $active
                                    ? 'ok-pill'
                                    : 'off-pill';
                            ?>"
                        >

                            <?php
                            echo $active
                                ? 'Active'
                                : 'Inactive';
                            ?>

                        </span>

                    </td>


                    <td>

                        <span
                            class="pill <?php
                                echo $online
                                    ? 'ok-pill'
                                    : 'off-pill';
                            ?>"
                        >

                            <?php
                            echo $online
                                ? 'Live'
                                : 'Off';
                            ?>

                        </span>

                    </td>


                    <td>

                        <span
                            class="pill <?php
                                echo $frozen
                                    ? 'frozen-pill'
                                    : 'clear-pill';
                            ?>"
                        >

                            <?php
                            echo $frozen
                                ? 'Frozen'
                                : 'Clear';
                            ?>

                        </span>

                    </td>


                    <td>

                        <span class="salary">

                            K<?php
                            echo number_format(
                                (float)
                                $member[
                                    'salary_amount'
                                ],
                                2
                            );
                            ?>

                        </span>

                    </td>


                    <td>

                        <div class="row-actions">


                        <?php if (
                            $id !==
                            $current_user_id
                        ): ?>


                            <button
                                type="button"

                                class="action-btn <?php
                                    echo $frozen
                                        ? 'unfreeze'
                                        : 'freeze';
                                ?>"

                                onclick='openFreezeModal(
                                    <?php echo $id; ?>,
                                    <?php echo $frozen ? 1 : 0; ?>,
                                    <?php echo json_encode($name); ?>
                                )'
                            >

                                <?php
                                echo $frozen
                                    ? 'Unfreeze'
                                    : 'Freeze';
                                ?>

                            </button>


                            <button
                                type="button"

                                class="action-btn"

                                onclick='openOnlineModal(
                                    <?php echo $id; ?>,
                                    <?php echo $online ? 1 : 0; ?>,
                                    <?php echo json_encode($name); ?>
                                )'
                            >

                                <?php
                                echo $online
                                    ? 'Offline'
                                    : 'Online';
                                ?>

                            </button>


                            <button
                                type="button"

                                class="action-btn delete"

                                onclick='openDeleteModal(
                                    <?php echo $id; ?>,
                                    <?php echo json_encode($name); ?>
                                )'
                            >

                                <i
                                    class="fa-solid fa-trash"
                                ></i>

                            </button>


                        <?php else: ?>


                            <span
                                class="action-btn"
                                style="
                                    opacity:.45;
                                    cursor:not-allowed;
                                "
                                title="Current account"
                            >

                                <i
                                    class="fa-solid fa-lock"
                                ></i>

                            </span>


                        <?php endif; ?>


                        </div>

                    </td>

                </tr>


            <?php endforeach; ?>


            </tbody>

        </table>

    </div>

</section>


<!-- =========================================================
     ROLE / SECURITY
========================================================= -->

<div class="lower-grid">


<section class="panel side-card">


    <div class="side-title">
        Team Composition
    </div>


    <?php foreach ($roles as $role): ?>

        <div class="role-item">

            <div class="role-icon">

                <i
                    class="fa-solid <?php
                        echo h(
                            $icons[$role]
                        );
                    ?>"
                ></i>

            </div>


            <div class="role-copy">

                <b>
                    <?php echo h($role); ?>
                </b>

                <span>
                    Staff accounts
                </span>

            </div>


            <div class="role-number">

                <?php
                echo (int)
                    ($roleCounts[$role] ?? 0);
                ?>

            </div>

        </div>

    <?php endforeach; ?>


</section>


<section class="panel side-card">


    <div class="side-title">
        Security Overview
    </div>


    <div class="security-box red">

        <div class="security-label">
            FROZEN ACCOUNTS
        </div>

        <div class="security-number">
            <?php echo $frozenStaff; ?>
        </div>

        <div class="security-text">
            Frozen accounts cannot continue authenticated use.
        </div>

    </div>


    <div class="security-box green">

        <div class="security-label">
            ONLINE VISIBILITY
        </div>

        <div class="security-number">
            <?php echo $onlineStaff; ?>
        </div>

        <div class="security-text">
            Staff marked visible online.
        </div>

    </div>


</section>


</div>


<!-- =========================================================
     PAGE PERMISSIONS
========================================================= -->

<section class="panel permission-section">


    <div class="panel-head">

        <div class="panel-head-left">

            <div class="panel-icon">

                <i
                    class="fa-solid fa-shield-halved"
                ></i>

            </div>


            <div>

                <h2>
                    Page Access & Functions
                </h2>

                <p>
                    Control who may open each POS page and who may perform functions.
                </p>

            </div>

        </div>


        <span class="count">

            <?php echo count($pages); ?>

            Pages

        </span>

    </div>


    <div class="legend">

        <span>

            <i
                class="legend-dot legend-blue"
            ></i>

            Access

        </span>


        <span>

            <i
                class="legend-dot legend-green"
            ></i>

            Functions

        </span>


        <span>

            <i
                class="legend-dot legend-gray"
            ></i>

            Functions require Access

        </span>


        <span
            style="margin-left:auto"
        >

            Admin has unrestricted access.

        </span>

    </div>


    <div class="permission-search">

        <input
            id="pageSearch"
            class="field"
            type="text"
            placeholder="Search POS page..."
        >

    </div>


    <div class="permission-wrap">

        <table class="permission-table">

            <thead>

                <tr>

                    <th>
                        POS Page
                    </th>


                    <?php foreach ($roles as $role): ?>

                        <th>
                            <?php echo h($role); ?>
                        </th>

                    <?php endforeach; ?>

                </tr>

            </thead>


            <tbody>


            <?php foreach ($pages as $page): ?>

                <tr
                    class="page-row"
                    data-page="<?php
                        echo h(
                            strtolower($page)
                        );
                    ?>"
                >


                    <td>

                        <div class="page-cell">

                            <div class="page-icon">

                                <i
                                    class="fa-solid <?php
                                        echo h(
                                            $pageIcons[$page]
                                            ?? 'fa-file'
                                        );
                                    ?>"
                                ></i>

                            </div>


                            <div>

                                <b>
                                    <?php echo h($page); ?>
                                </b>

                                <span>
                                    Access + Functions
                                </span>

                            </div>

                        </div>

                    </td>


                    <?php foreach ($roles as $role): ?>


                        <?php

                        $permission =
                            $permissions[
                                $role
                            ][
                                $page
                            ]
                            ??
                            [
                                'access' => 0,
                                'action' => 0
                            ];


                        $access =
                            (int)
                            $permission['access']
                            === 1;


                        $function =
                            (int)
                            $permission['action']
                            === 1;

                        ?>


                        <td>

                            <div
                                style="
                                    display:flex;
                                    flex-direction:column;
                                    align-items:center;
                                    gap:4px;
                                "
                            >


                                <!-- ACCESS -->

                                <form
                                    method="post"
                                    class="permission-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php
                                            echo h($csrf);
                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="permission"
                                    >

                                    <input
                                        type="hidden"
                                        name="role"
                                        value="<?php
                                            echo h($role);
                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?php
                                            echo h($page);
                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="perm"
                                        value="access"
                                    >


                                    <button
                                        type="submit"

                                        class="permission-btn <?php
                                            echo $access
                                                ? 'access'
                                                : '';
                                        ?>"
                                    >

                                        <?php
                                        echo $access
                                            ? '✓ ACCESS'
                                            : '🔒 DENIED';
                                        ?>

                                    </button>

                                </form>


                                <!-- FUNCTIONS -->

                                <form
                                    method="post"
                                    class="permission-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php
                                            echo h($csrf);
                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="permission"
                                    >

                                    <input
                                        type="hidden"
                                        name="role"
                                        value="<?php
                                            echo h($role);
                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?php
                                            echo h($page);
                                        ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="perm"
                                        value="function"
                                    >


                                    <button
                                        type="submit"

                                        class="permission-btn <?php
                                            echo $function
                                                ? 'function'
                                                : '';
                                        ?>"

                                        <?php
                                        echo !$access
                                            ? 'disabled'
                                            : '';
                                        ?>
                                    >

                                        <?php
                                        echo $function
                                            ? '⚡ FUNCTIONS'
                                            : 'NO FUNCTIONS';
                                        ?>

                                    </button>

                                </form>


                            </div>

                        </td>


                    <?php endforeach; ?>


                </tr>

            <?php endforeach; ?>


            </tbody>

        </table>

    </div>

</section>


</section>

</main>


<!-- =========================================================
     ADD STAFF MODAL
========================================================= -->

<div
    class="modal fade"
    id="addStaffModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="modal-dialog modal-lg modal-dialog-centered"
    >

        <div class="modal-content">


            <form
                method="post"
                enctype="multipart/form-data"
            >


                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo h($csrf); ?>"
                >


                <input
                    type="hidden"
                    name="action"
                    value="add"
                >


                <div class="modal-header">

                    <div>

                        <div class="modal-title">
                            Register Staff Member
                        </div>

                        <div class="modal-sub">
                            Create account, assign role and branch.
                        </div>

                    </div>


                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>

                </div>


                <div class="modal-body">


                    <div class="row g-3">


                        <div class="col-md-6">

                            <label class="form-label">
                                Username / Login
                            </label>

                            <input
                                type="text"
                                name="username"
                                class="form-control"
                                autocomplete="username"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Full Name
                            </label>

                            <input
                                type="text"
                                name="full_name"
                                class="form-control"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Email
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                autocomplete="new-password"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Role
                            </label>

                            <select
                                name="role"
                                class="form-select"
                                required
                            >

                                <?php foreach ($roles as $role): ?>

                                    <option
                                        value="<?php echo h($role); ?>"
                                    >

                                        <?php
                                        echo h($role);
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-md-6">

                            <label class="form-label">
                                Monthly Salary
                            </label>

                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="salary"
                                class="form-control"
                                required
                            >

                        </div>


                        <div class="col-12">

                            <label class="form-label">
                                Branch
                            </label>

                            <select
                                name="branch_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select branch...
                                </option>


                                <?php foreach ($branches as $branch): ?>

                                    <option
                                        value="<?php
                                            echo (int)
                                                $branch['id'];
                                        ?>"
                                    >

                                        <?php
                                        echo h(
                                            $branch['branch_name']
                                        );
                                        ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-12">

                            <label class="form-label">
                                Profile Image
                            </label>

                            <input
                                type="file"
                                name="profile_pic"
                                class="form-control"
                                accept="
                                    image/jpeg,
                                    image/png,
                                    image/webp,
                                    image/gif
                                "
                            >

                        </div>


                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light border"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="fa-solid fa-user-plus me-1"></i>

                        Create Account

                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     CONFIRM MODAL
========================================================= -->

<div
    class="modal fade"
    id="confirmModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="modal-dialog modal-dialog-centered"
    >

        <div class="modal-content">


            <div class="confirm">


                <div
                    class="confirm-icon"
                    id="confirmIcon"
                >

                    <i
                        id="confirmIconSymbol"
                        class="fa-solid fa-triangle-exclamation"
                    ></i>

                </div>


                <h5 id="confirmTitle">
                    Confirm Action
                </h5>


                <p id="confirmText">
                    Are you sure?
                </p>


                <form
                    method="post"
                    id="confirmForm"
                    class="mt-4"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php
                            echo h($csrf);
                        ?>"
                    >


                    <input
                        type="hidden"
                        name="action"
                        id="confirmAction"
                        value=""
                    >


                    <input
                        type="hidden"
                        name="target_id"
                        id="confirmTarget"
                        value=""
                    >


                    <div
                        class="d-flex justify-content-center gap-2"
                    >

                        <button
                            type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>


                        <button
                            type="submit"
                            class="btn btn-danger"
                            id="confirmSubmit"
                        >
                            Continue
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     FLASH
========================================================= -->

<?php if ($flash): ?>

<div class="toast-stack">

    <div
        class="toast-card <?php
            echo $isError ? 'error' : '';
        ?>"
        id="flashToast"
    >

        <div class="toast-icon">

            <i
                class="fa-solid <?php
                    echo $isError
                        ? 'fa-circle-exclamation'
                        : 'fa-circle-check';
                ?>"
            ></i>

        </div>


        <div class="toast-copy">

            <b>

                <?php
                echo $isError
                    ? 'Action not completed'
                    : 'Staff Management';
                ?>

            </b>


            <span>

                <?php
                echo h($flash);
                ?>

            </span>

        </div>

    </div>

</div>

<?php endif; ?>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<script>

/* ============================================================
   SIDEBAR
   ============================================================ */

function toggleSidebar() {

    const sidebar =
        document.getElementById('sidebar');

    if (sidebar) {

        sidebar.classList.toggle(
            'open'
        );
    }
}


/* ============================================================
   STAFF FILTER
   ============================================================ */

function filterStaff() {

    const search =
        (
            document.getElementById(
                'staffSearch'
            )?.value
            || ''
        )
        .toLowerCase()
        .trim();


    const role =
        document.getElementById(
            'roleFilter'
        )?.value
        || '';


    const branch =
        document.getElementById(
            'branchFilter'
        )?.value
        || '';


    let visible = 0;


    document
        .querySelectorAll(
            '.staff-row'
        )
        .forEach(row => {


            const rowSearch =
                row.dataset.search
                || '';


            const rowRole =
                row.dataset.role
                || '';


            const rowBranch =
                row.dataset.branch
                || '';


            const matchesSearch =
                !search
                ||
                rowSearch.includes(
                    search
                );


            const matchesRole =
                !role
                ||
                rowRole === role;


            const matchesBranch =
                !branch
                ||
                rowBranch === branch;


            const show =
                matchesSearch
                &&
                matchesRole
                &&
                matchesBranch;


            row.style.display =
                show
                    ? ''
                    : 'none';


            if (show) {
                visible++;
            }

        });


    const count =
        document.getElementById(
            'staffCount'
        );


    if (count) {

        count.textContent =
            visible
            + ' Record'
            + (
                visible === 1
                    ? ''
                    : 's'
            );
    }
}


/* ============================================================
   RESET FILTERS
   ============================================================ */

function resetStaffFilters() {

    const search =
        document.getElementById(
            'staffSearch'
        );

    const role =
        document.getElementById(
            'roleFilter'
        );

    const branch =
        document.getElementById(
            'branchFilter'
        );


    if (search) {
        search.value = '';
    }


    if (role) {
        role.value = '';
    }


    if (branch) {
        branch.value = '';
    }


    filterStaff();
}


/* ============================================================
   PAGE FILTER
   ============================================================ */

function filterPages() {

    const search =
        (
            document.getElementById(
                'pageSearch'
            )?.value
            || ''
        )
        .toLowerCase()
        .trim();


    document
        .querySelectorAll(
            '.page-row'
        )
        .forEach(row => {

            const page =
                row.dataset.page
                || '';


            row.style.display =
                !search
                ||
                page.includes(search)
                    ? ''
                    : 'none';

        });
}


/* ============================================================
   CONFIRM MODAL
   ============================================================ */

function showConfirmModal(
    title,
    text,
    action,
    targetId,
    submitText,
    danger = true
) {

    const titleEl =
        document.getElementById(
            'confirmTitle'
        );


    const textEl =
        document.getElementById(
            'confirmText'
        );


    const actionEl =
        document.getElementById(
            'confirmAction'
        );


    const targetEl =
        document.getElementById(
            'confirmTarget'
        );


    const submitEl =
        document.getElementById(
            'confirmSubmit'
        );


    const icon =
        document.getElementById(
            'confirmIcon'
        );


    const iconSymbol =
        document.getElementById(
            'confirmIconSymbol'
        );


    if (titleEl) {
        titleEl.textContent = title;
    }


    if (textEl) {
        textEl.textContent = text;
    }


    if (actionEl) {
        actionEl.value = action;
    }


    if (targetEl) {
        targetEl.value = targetId;
    }


    if (submitEl) {

        submitEl.textContent =
            submitText;

        submitEl.className =
            danger
                ? 'btn btn-danger'
                : 'btn btn-success';
    }


    if (icon) {

        icon.className =
            danger
                ? 'confirm-icon'
                : 'confirm-icon';

        icon.style.background =
            danger
                ? '#fff0f2'
                : '#e8f7f0';

        icon.style.color =
            danger
                ? '#d94d61'
                : '#159a68';
    }


    if (iconSymbol) {

        iconSymbol.className =
            danger
                ? 'fa-solid fa-triangle-exclamation'
                : 'fa-solid fa-circle-check';
    }


    const modalElement =
        document.getElementById(
            'confirmModal'
        );


    const modal =
        bootstrap.Modal
            .getOrCreateInstance(
                modalElement
            );


    modal.show();
}


/* ============================================================
   FREEZE
   ============================================================ */

function openFreezeModal(
    id,
    frozen,
    name
) {

    if (frozen) {

        showConfirmModal(

            'Unfreeze staff account?',

            name
            + ' will be allowed to sign in again.',

            'toggle_freeze',

            id,

            'Unfreeze',

            false

        );

    } else {

        showConfirmModal(

            'Freeze staff account?',

            name
            + ' will be frozen and unable to continue authenticated use.',

            'toggle_freeze',

            id,

            'Freeze',

            true

        );
    }
}


/* ============================================================
   ONLINE
   ============================================================ */

function openOnlineModal(
    id,
    online,
    name
) {

    if (online) {

        showConfirmModal(

            'Set staff offline?',

            name
            + ' will be removed from online visibility.',

            'toggle_online',

            id,

            'Set Offline',

            false

        );

    } else {

        showConfirmModal(

            'Push staff online?',

            name
            + ' will be marked visible online.',

            'toggle_online',

            id,

            'Set Online',

            false

        );
    }
}


/* ============================================================
   DELETE
   ============================================================ */

function openDeleteModal(
    id,
    name
) {

    showConfirmModal(

        'Delete staff account?',

        'Delete '
        + name
        + ' permanently? This cannot be undone.',

        'delete_staff',

        id,

        'Delete Account',

        true

    );
}


/* ============================================================
   EVENTS
   ============================================================ */

document
    .getElementById('staffSearch')
    ?.addEventListener(
        'input',
        filterStaff
    );


document
    .getElementById('roleFilter')
    ?.addEventListener(
        'change',
        filterStaff
    );


document
    .getElementById('branchFilter')
    ?.addEventListener(
        'change',
        filterStaff
    );


document
    .getElementById('globalSearch')
    ?.addEventListener(
        'input',
        function () {

            const staffSearch =
                document.getElementById(
                    'staffSearch'
                );


            if (staffSearch) {

                staffSearch.value =
                    this.value;
            }


            filterStaff();
        }
    );


document
    .getElementById('pageSearch')
    ?.addEventListener(
        'input',
        filterPages
    );


/* ============================================================
   AUTO HIDE FLASH
   ============================================================ */

setTimeout(
    function () {

        const toast =
            document.getElementById(
                'flashToast'
            );


        if (toast) {

            toast.style.opacity =
                '0';

            toast.style.transform =
                'translateY(-6px)';

            toast.style.transition =
                '.25s ease';


            setTimeout(
                () => toast.remove(),
                300
            );
        }

    },
    4500
);

</script>


</body>

</html>
