<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHARMANOVA - Dashboard</title>

    <!-- CDN Libraries (No local file dependence) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: #f0f2f5 !important; 
            margin: 0;
            padding: 0;
        }

        #main-wrapper {
            position: relative;
            width: 100%;
            min-height: 100vh;
        }

        .topbar {
            position: fixed !important;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            z-index: 1050;
            background: #3e4f5f !important;
        }

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

        .navbar-collapse {
            margin-left: 250px !important;
            height: 64px;
            background: #3e4f5f !important;
        }

        .left-sidebar {
            position: fixed !important;
            top: 64px !important;
            left: 0;
            width: 250px !important;
            height: calc(100vh - 64px) !important;
            background: #2c3e50 !important;
            z-index: 1000;
            overflow-y: auto;
            display: block !important;
        }

        .page-wrapper { 
            margin-left: 250px !important;
            padding-top: 80px !important;
            padding-left: 20px !important;
            padding-right: 20px !important;
            padding-bottom: 20px !important;
            min-height: calc(100vh - 64px);
            background-color: #f0f2f5 !important;
            display: block !important;
        }

        .card-stats { 
            border-radius: 0.75rem; 
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); 
            transition: transform 0.3s ease; 
            color: #fff; 
            border: none;
        }
        .card-stats:hover { 
            transform: translateY(-5px); 
        }
        .stat-card-sales { background: linear-gradient(135deg, #4a90e2, #50b0f0); }
        .stat-card-stock { background: linear-gradient(135deg, #6b7a8f, #4d5e7a); }
        .stat-card-out-of-stock { background: linear-gradient(135deg, #f5a623, #d0021b); }
        .stat-card-expired { background: linear-gradient(135deg, #d0021b, #9b1e22); }
        .stat-card-items { background: linear-gradient(135deg, #34495e, #2c3e50); }

        .page-breadcrumb { 
            background-color: #fff; 
            padding: 1rem; 
            border-radius: 0.75rem; 
            margin-bottom: 2rem; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
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
