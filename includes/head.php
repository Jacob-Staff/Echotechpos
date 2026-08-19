<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Pharmacy Management & Point of Sale System">
    <title>PHARMACY POS</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Material Design Icons & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- jQuery (Required before header scripts load) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- Base Layout & Custom Styles -->
    <style>
        :root {
            --sidebar-width: 250px;
            --topbar-height: 64px;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f9;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        /* Layout Structure */
        #main-wrapper {
            width: 100%;
            min-height: 100vh;
            position: relative;
        }

        .page-wrapper {
            margin-left: var(--sidebar-width);
            padding-top: var(--topbar-height);
            min-height: calc(100vh - var(--topbar-height));
            transition: all 0.2s ease-in-out;
            padding-left: 1.5rem;
            padding-right: 1.5rem;
            padding-bottom: 2rem;
        }

        .topbar {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1030;
            height: var(--topbar-height);
            background-color: #343a40;
        }

        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0;
            }
        }

        /* Hover animation for dashboard cards */
        .hover-shadow {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .hover-shadow:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1) !important;
        }

        /* Custom Alert Backgrounds */
        .bg-light-danger { background-color: #f8d7da; }
        .bg-light-warning { background-color: #fff3cd; }
    </style>
</head>
<body>
<!-- Main Layout Wrapper (Closed in footer.php) -->
<div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5">
