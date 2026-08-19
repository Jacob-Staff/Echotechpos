<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHARMANOVA - POS</title>

    <!-- Core CDNs -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Theme Styles -->
    <link href="/assets/libs/flot/css/float-chart.css" rel="stylesheet">
    <link href="/dist/css/style.min.css" rel="stylesheet">

    <!-- Core Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <style>
    body {
        margin: 0;
        padding: 0;
        background-color: #f4f6f9;
        font-family: 'Poppins', sans-serif;
    }

    #main-wrapper {
        position: relative;
        width: 100%;
        min-height: 100vh;
    }

    /* Fixed top navigation bar */
    .topbar {
        position: fixed !important;
        top: 0;
        left: 0;
        right: 0;
        height: 64px;
        z-index: 1050;
        background: #3e4f5f !important;
    }

    /* Far-left dark header box for company logo/name */
    .topbar .navbar-header {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 250px !important;
        height: 64px;
        background: #2c3e50 !important;
        display: flex !important;
        align-items: center;
        justify-content: center;
        z-index: 1100;
    }

    .logo-icon {
        color: #00ffd0 !important;
        font-size: 1.3rem !important;
        font-weight: 900 !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Search bar container offset */
    .navbar-collapse {
        margin-left: 250px !important;
        height: 64px;
        background: #3e4f5f !important;
    }

    /* Left sidebar layout and dark theme */
    .left-sidebar {
        position: fixed !important;
        top: 64px !important;
        left: 0;
        width: 250px !important;
        height: calc(100vh - 64px) !important;
        background: #2c3e50 !important;
        z-index: 1000;
        overflow-y: auto;
    }

    .scroll-sidebar {
        height: calc(100vh - 64px) !important;
        overflow-y: auto !important;
    }

    /* Main content container offset */
    .page-wrapper {
        margin-left: 250px !important;
        margin-top: 64px !important;
        padding: 20px !important;
        min-height: calc(100vh - 64px);
        background: #f4f6f9;
    }

    @media (max-width: 768px) {
        .page-wrapper {
            margin-left: 0 !important;
        }
        .left-sidebar {
            width: 100% !important;
            position: relative !important;
            height: auto !important;
            top: 0 !important;
        }
        .navbar-collapse {
            margin-left: 0 !important;
        }
    }
    </style>
</head>
<body>
