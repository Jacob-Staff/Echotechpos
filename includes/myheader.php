<?php
require_once "../includes/head.php";
require_once "../includes/auth.php";
require_once "../includes/conn.php";
?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $pageTitle ?? 'PHARMACY POS'; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="dist/css/style.min.css" rel="stylesheet">

<style>
    /* Reset Topbar for full width display */
    .topbar {
        position: fixed !important;
        top: 0;
        left: 0 !important;
        width: 100% !important;
        height: 64px;
        z-index: 1050;
        background: #3e4f5f !important;
    }

    /* Pin Title area to the dark box on wide screens */
    .navbar-header {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 250px !important;
        background: #2c3e50 !important;
        height: 64px;
        display: flex !important;
        align-items: center;
        justify-content: center;
        z-index: 1100;
    }

    .navbar-collapse {
        margin-left: 250px !important; 
        display: flex !important;
        align-items: center;
        height: 100%;
    }

    .left-sidebar {
        width: 250px !important;
        position: fixed !important;
        top: 64px !important;
        height: calc(100vh - 64px) !important;
        z-index: 1000;
    }

    .page-wrapper {
        margin-left: 250px !important;
        margin-top: 64px !important;
        padding: 20px !important;
        background: #f4f6f9;
        flex: 1;
    }

    .aside, .head, * {
        font-weight: 700 !important;
    }
    
    html, body {
        height: 100%;
        margin: 0;
    }

    #main-wrapper {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    footer {
        flex-shrink: 0;
        padding: 10px 20px;
        background: #fff;
        border-top: 1px solid #dee2e6;
        margin-left: 250px !important;
    }

    /* Mobile adjustments */
    @media (max-width: 768px) {
        .navbar-collapse {
            margin-left: 0 !important;
        }
        .page-wrapper, footer {
            margin-left: 0 !important;
            padding: 10px !important;
        }
        .left-sidebar {
            top: 64px !important;
        }
    }
</style>
</head>

<body>
<div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5" data-sidebartype="full" data-sidebar-position="absolute" data-header-position="absolute" data-boxed-layout="full">

    <!-- HEADER -->
    <?php require "../includes/header.php"; ?>

    <!-- SIDEBAR -->
    <?php require_once "../includes/aside.php"; ?>

    <!-- PAGE CONTENT -->
    <div class="page-wrapper">
        <div class="container-fluid">
            <?php
            if (isset($content)) {
                echo $content;
            }
            ?>
        </div>
    </div>

    <!-- FOOTER -->
    <?php require "../includes/footer.php"; ?>

</div>

<!-- SCRIPTS -->
<script src="assets/libs/jquery/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/app-style-switcher.js"></script>
<script src="dist/js/waves.js"></script>
<script src="dist/js/sidebarmenu.js"></script>
<script src="dist/js/custom.js"></script>
</body>
</html>
