<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHARMANOVA - POS</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Material Design Icons & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Absolute Paths to Local Admin Theme Files -->
    <link href="/assets/libs/flot/css/float-chart.css" rel="stylesheet">
    <link href="/dist/css/style.min.css" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #eef2f5;
        }
        #main-wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }
        .page-wrapper {
            flex-grow: 1;
            margin-left: 250px;
            padding-top: 70px;
            padding-left: 20px;
            padding-right: 20px;
            padding-bottom: 20px;
            background-color: #f4f6f9;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .page-wrapper {
                margin-left: 0;
            }
        }
        /* Custom Dashboard Card Styles matching original theme */
        .stat-card-sales { background: linear-gradient(135deg, #3498db, #2980b9); }
        .stat-card-stock { background: linear-gradient(135deg, #34495e, #2c3e50); }
        .stat-card-out-of-stock { background: linear-gradient(135deg, #e67e22, #d35400); }
        .stat-card-expired { background: linear-gradient(135deg, #c0392b, #962d22); }
        .stat-card-items { background: linear-gradient(135deg, #2c3e50, #1a252f); }
        .hover-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .hover-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important; }
    </style>
</head>
<body>
<div id="main-wrapper" data-layout="vertical" data-navbarbg="skin5">
