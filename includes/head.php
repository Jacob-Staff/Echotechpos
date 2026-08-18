<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="keywords"
        content="pharmacy, pos, dashboard, html css dashboard, web dashboard, bootstrap 4 admin, bootstrap 4, css3 dashboard, bootstrap 4 dashboard, pharmacy pos, frontend, responsive bootstrap 4 admin template">
    <meta name="description"
        content="A modern pharmacy POS dashboard">
    <meta name="robots" content="noindex,nofollow">
    <title>Pharmacy POS</title>

    <!-- Favicon icon -->
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/a+.png">

    <!-- Custom CSS -->
    <link href="/assets/libs/chartist/dist/chartist.min.css" rel="stylesheet">
    <link href="/dist/css/style.min.css" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
    /* 1. THE DARK BOX ON THE LEFT */
    .topbar .navbar-header {
        width: 250px !important;
        background: #2c3e50 !important;
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        height: 64px;
        display: flex !important;
        align-items: center;
        justify-content: center;
        z-index: 9999 !important; 
        opacity: 1 !important;
        backdrop-filter: none !important;
    }

    /* 🔥 FIX: TARGET THE ACTUAL ELEMENT */
    .logo-icon {
        color: #00ffd0 !important;
        font-size: 1.4rem !important;
        font-weight: 900 !important;
        text-transform: uppercase;

        opacity: 1 !important; 
        filter: none !important; 
        text-shadow: none !important;
        -webkit-font-smoothing: antialiased;
    }

    /* 3. PUSH SEARCH BAR TO THE RIGHT */
    .navbar-collapse {
        margin-left: 250px !important;
        background: #3e4f5f !important;
        height: 64px;
        opacity: 1 !important;
    }

    /* 4. PREVENT OVERLAP */
    .left-sidebar {
        top: 64px !important;
        width: 250px !important;
        position: fixed !important;
    }

    * { font-weight: 700 !important; }
    </style>
</head>
<body>
<!-- Bootstrap JS Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>