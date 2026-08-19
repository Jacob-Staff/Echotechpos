<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PHARMANOVA - Dashboard</title>

    <!-- Core CDN Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.2.96/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: #f4f6f9 !important; 
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
            height: 60px;
            z-index: 1050;
            background: #2b3a4a !important;
        }

        .topbar .navbar-header {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 240px !important;
            height: 60px;
            background: #1e2832 !important;
            display: flex !important;
            align-items: center;
            justify-content: center;
            z-index: 1100;
        }

        .logo-icon {
            color: #00e6ac !important;
            font-size: 1.25rem !important;
            font-weight: 800 !important;
            letter-spacing: 0.5px;
        }

        .navbar-collapse {
            margin-left: 240px !important;
            height: 60px;
            background: #2b3a4a !important;
        }

        .left-sidebar {
            position: fixed !important;
            top: 60px !important;
            left: 0;
            width: 240px !important;
            height: calc(100vh - 60px) !important;
            background: #2c3846 !important;
            z-index: 1000;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* User Info Box in Sidebar */
        .user-profile-box {
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #4a5c6e;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 10px 20px;
            color: #b0bec5;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .sidebar-link:hover, .sidebar-link.active {
            color: #ffffff;
            background: #202b36;
        }

        .sidebar-link i {
            margin-right: 12px;
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .page-wrapper { 
            margin-left: 240px !important;
            padding-top: 75px !important;
            padding-left: 25px !important;
            padding-right: 25px !important;
            padding-bottom: 25px !important;
            min-height: calc(100vh - 60px);
            background-color: #f4f6f9 !important;
            display: block !important;
        }

        .page-breadcrumb { 
            background-color: #ffffff; 
            padding: 1rem 1.25rem; 
            border-radius: 0.5rem; 
            margin-bottom: 1.5rem; 
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); 
        }

        /* Dash Tile Styles */
        .card-dash {
            border-radius: 0.5rem;
            border: none;
            color: #ffffff;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-decoration: none !important;
            transition: transform 0.2s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.08);
        }
        .card-dash:hover {
            transform: translateY(-3px);
            color: #ffffff;
        }
        .card-dash .card-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .card-dash .card-value {
            font-size: 1.75rem;
            font-weight: 800;
        }

        /* Specific Tile Background Colors */
        .bg-tile-blue { background: linear-gradient(135deg, #42a5f5, #1e88e5); }
        .bg-tile-darkblue { background: #37474f; }
        .bg-tile-orange { background: linear-gradient(135deg, #f57c00, #e65100); }
        .bg-tile-red { background: linear-gradient(135deg, #d32f2f, #b71c1c); }
        .bg-tile-navy { background: #263238; }

        .btn-purple {
            background-color: #6c5ce7;
            color: #fff;
            border: none;
        }
        .btn-purple:hover {
            background-color: #5b4bc4;
            color: #fff;
        }

        .logout-btn-container {
            padding: 15px;
            margin-top: auto;
        }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0 !important; }
            .left-sidebar { width: 100% !important; position: relative !important; height: auto !important; top: 0 !important; }
            .navbar-collapse { margin-left: 0 !important; }
        }
    </style>
</head>
<body>
