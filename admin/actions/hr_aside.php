<?php
/**
 * EchoTech POS - Human Resource Sidebar
 *
 * Use this sidebar ONLY on HR portal pages.
 * It does not modify or replace the Admin sidebar.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$hrCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');

$hrUserName = function_exists('current_user')
    ? (string) current_user()
    : (string) ($_SESSION['username'] ?? $_SESSION['full_name'] ?? 'Human Resource');

$hrUserRole = function_exists('current_role')
    ? (string) (current_role() ?? 'Human Resource')
    : (string) ($_SESSION['role'] ?? 'Human Resource');

$hrInitial = strtoupper(substr(trim($hrUserName), 0, 1));
if ($hrInitial === '') {
    $hrInitial = 'H';
}

$hrIsActive = static function (string $page) use ($hrCurrentPage): string {
    return $hrCurrentPage === $page ? 'active' : '';
};
?>

<style>
/* =========================================================
   ECHOTECH POS - HR SIDEBAR
   Completely scoped to .hr-sidebar.
   This will NOT affect the Admin sidebar.
========================================================= */

.hr-sidebar{
    --hr-side-width:250px;

    position:fixed;
    left:0;
    top:0;
    bottom:0;
    width:var(--hr-side-width);
    background:#202831;
    color:#fff;
    padding:18px 14px;
    display:flex;
    flex-direction:column;
    overflow-y:auto;
    z-index:1000;
    box-sizing:border-box;
}

.hr-sidebar *,
.hr-sidebar *::before,
.hr-sidebar *::after{
    box-sizing:border-box;
}

.hr-sidebar .hr-brand{
    display:flex;
    align-items:center;
    gap:10px;
    padding:4px 7px 20px;
    text-decoration:none;
    color:#fff;
}

.hr-sidebar .hr-brand-mark{
    width:38px;
    height:38px;
    border-radius:10px;
    background:#2f6df6;
    color:#fff;
    display:grid;
    place-items:center;
    font-size:17px;
    flex:0 0 38px;
}

.hr-sidebar .hr-brand-title{
    min-width:0;
}

.hr-sidebar .hr-brand-title b{
    display:block;
    color:#fff;
    font-size:15px;
    line-height:1.2;
    font-weight:800;
}

.hr-sidebar .hr-brand-title small{
    display:block;
    margin-top:3px;
    color:#aeb8c2;
    font-size:9px;
    letter-spacing:1px;
    font-weight:700;
}

.hr-sidebar .hr-user{
    display:flex;
    align-items:center;
    gap:10px;
    margin:0 7px 17px;
    padding:10px;
    background:#18212a;
    border:1px solid #35414d;
    border-radius:9px;
}

.hr-sidebar .hr-avatar{
    width:34px;
    height:34px;
    border-radius:50%;
    background:#344250;
    color:#fff;
    display:grid;
    place-items:center;
    font-size:13px;
    font-weight:800;
    flex:0 0 34px;
}

.hr-sidebar .hr-user-info{
    min-width:0;
}

.hr-sidebar .hr-user-info b{
    display:block;
    color:#fff;
    font-size:13px;
    font-weight:800;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.hr-sidebar .hr-user-info small{
    display:block;
    margin-top:2px;
    color:#9aa7b3;
    font-size:11px;
}

.hr-sidebar .hr-section-title{
    margin:8px 9px 7px;
    color:#71808d;
    font-size:10px;
    line-height:1.2;
    text-transform:uppercase;
    letter-spacing:1px;
    font-weight:800;
}

.hr-sidebar .hr-nav{
    display:flex;
    flex-direction:column;
    gap:3px;
}

.hr-sidebar .hr-nav a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 11px;
    border-radius:8px;
    color:#bdc6cf;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
    transition:background .15s ease,color .15s ease;
}

.hr-sidebar .hr-nav a:hover{
    background:#2b3743;
    color:#fff;
}

.hr-sidebar .hr-nav a.active{
    background:#344253;
    color:#fff;
}

.hr-sidebar .hr-nav a i{
    width:18px;
    text-align:center;
    color:#91a0ae;
    font-size:13px;
}

.hr-sidebar .hr-nav a.active i{
    color:#70a0ff;
}

.hr-sidebar .hr-logout{
    margin-top:auto;
    padding-top:16px;
}

.hr-sidebar .hr-logout a{
    color:#ffb2bd;
}

.hr-sidebar .hr-logout a:hover{
    background:#3a2930;
    color:#fff;
}

/* Mobile */
@media(max-width:950px){
    .hr-sidebar{
        transform:translateX(-100%);
        transition:transform .2s ease;
    }

    .hr-sidebar.hr-open{
        transform:translateX(0);
    }
}
</style>

<aside class="hr-sidebar" id="hrSidebar">

    <a class="hr-brand" href="employee_management.php">
        <span class="hr-brand-mark">
            <i class="fa-solid fa-user-group"></i>
        </span>

        <span class="hr-brand-title">
            <b>PHARMANOVA</b>
            <small>HR / EMPLOYEE CONTROL</small>
        </span>
    </a>

    <div class="hr-user">
        <div class="hr-avatar"><?= htmlspecialchars($hrInitial, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="hr-user-info">
            <b><?= htmlspecialchars($hrUserName, ENT_QUOTES, 'UTF-8') ?></b>
            <small><?= htmlspecialchars($hrUserRole, ENT_QUOTES, 'UTF-8') ?></small>
        </div>
    </div>

    <div class="hr-section-title">Human Resources</div>

    <nav class="hr-nav">

        <a class="<?= $hrIsActive('staff_management.php') ?>"
           href="staff_management.php">
            <i class="fa-solid fa-users"></i>
            <span>Staff Management</span>
        </a>

        <a class="<?= $hrIsActive('payroll.php') ?>"
           href="payroll.php">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Payroll</span>
        </a>

        <a class="<?= $hrIsActive('loans_advances.php') ?>"
           href="loans_advances.php">
            <i class="fa-solid fa-hand-holding-dollar"></i>
            <span>Loans &amp; Advances</span>
        </a>

        <a class="<?= $hrIsActive('employee_management.php') ?>"
           href="employee_management.php">
            <i class="fa-solid fa-user-gear"></i>
            <span>Employee Management</span>
        </a>

    </nav>

    <div class="hr-logout">
        <nav class="hr-nav">
            <a href="../logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Sign out</span>
            </a>
        </nav>
    </div>

</aside>
