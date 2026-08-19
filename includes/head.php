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
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; 
        background-color: #f4f6f9 !important; 
        margin: 0;
        padding: 0;
    }

    #main-wrapper {
        position: relative;
        width: 100%;
        min-height: 100vh;
    }

    /* Fixed Header Topbar */
    .topbar {
        position: fixed !important;
        top: 0;
        left: 0;
        right: 0;
        height: 60px;
        z-index: 1050;
        background: #3e4f5f !important;
    }

    .topbar .navbar-header {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 240px !important;
        height: 60px;
        background: #2b3947 !important;
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        padding: 0 15px;
        z-index: 1100;
    }

    .logo-icon {
        color: #00ffd0 !important;
        font-size: 1.25rem !important;
        font-weight: 900 !important;
        letter-spacing: 0.5px;
    }

    .navbar-collapse {
        margin-left: 240px !important;
        height: 60px;
        background: #3e4f5f !important;
        padding-left: 15px !important;
        padding-right: 15px !important;
    }

    /* Fixed Left Sidebar */
    .left-sidebar {
        position: fixed !important;
        top: 60px !important;
        left: 0;
        width: 240px !important;
        height: calc(100vh - 60px) !important;
        background: #2b3947 !important;
        z-index: 1000;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: transform 0.3s ease;
    }

    .user-profile-box {
        padding: 18px 15px;
        display: flex;
        align-items: center;
        gap: 12px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }
    .user-avatar {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #4a5c6e;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #b2c0ce;
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        padding: 10px 18px;
        color: #c2c9d1;
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

    /* Page Content Area */
    .page-wrapper { 
        margin-left: 240px !important;
        padding-top: 75px !important;
        padding-left: 20px !important;
        padding-right: 20px !important;
        padding-bottom: 20px !important;
        min-height: calc(100vh - 60px);
        background-color: #f4f6f9 !important;
        display: block !important;
        transition: margin-left 0.3s ease;
    }

    .page-breadcrumb { 
        background-color: #ffffff; 
        padding: 0.85rem 1.25rem; 
        border-radius: 0.4rem; 
        margin-bottom: 1.25rem; 
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); 
    }

    /* Desktop Responsive 3-Column Grid */
    .mobile-tile-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
    }

    .card-dash {
        border-radius: 0.5rem;
        border: none;
        color: #ffffff;
        height: 110px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-decoration: none !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.06);
    }
    .card-dash .card-title {
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 0.2rem;
    }
    .card-dash .card-value {
        font-size: 1.5rem;
        font-weight: 800;
    }

    /* Exact Tile Themes */
    .bg-tile-sellnow { background: linear-gradient(180deg, #449cd5, #3586ba); }
    .bg-tile-tx { background: #3f4d5b; }
    .bg-tile-outstock { background: linear-gradient(180deg, #ed850e, #d8311a); }
    .bg-tile-expired { background: linear-gradient(180deg, #c00f1c, #900510); }
    .bg-tile-customer { background: #2f3e4d; }
    .bg-tile-online { background: linear-gradient(180deg, #f17808, #ca1818); }

    .btn-purple {
        background-color: #6c5ce7;
        color: #fff;
        border: none;
        font-weight: 600;
    }

    .logout-btn-container {
        padding: 15px;
        margin-top: auto;
    }

    .logout-btn {
        background-color: #ff3b5c;
        border: none;
        color: white;
        font-weight: 700;
        padding: 10px;
        border-radius: 6px;
    }

    /* Touch-friendly horizontal nav bar for quick actions */
    .quick-actions-nav {
        display: flex;
        align-items: center;
        gap: 12px;
        overflow-x: auto;
        white-space: nowrap;
        padding-bottom: 4px;
        -webkit-overflow-scrolling: touch;
    }
    .quick-actions-nav::-webkit-scrollbar {
        display: none;
    }

    /* =================================================== */
    /* Mobile Off-Canvas / Responsive Layout Rules        */
    /* =================================================== */
    @media (max-width: 768px) {
        .topbar .navbar-header {
            width: 100% !important;
        }

        .navbar-collapse {
            margin-left: 0 !important;
            display: none !important; /* Hide non-essential desktop header widgets on mobile */
        }

        .left-sidebar {
            top: 60px !important;
            width: 260px !important;
            transform: translateX(-100%);
            box-shadow: 4px 0 12px rgba(0,0,0,0.25);
        }

        /* Sidebar Toggle Active Class */
        body.mobile-nav-open .left-sidebar {
            transform: translateX(0);
        }

        .page-wrapper { 
            margin-left: 0 !important; 
            padding-top: 70px !important;
            padding-left: 12px !important;
            padding-right: 12px !important;
        }

        /* Mobile 2-Column Tile Grid */
        .mobile-tile-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .card-dash {
            height: 95px;
            padding: 8px;
        }
        .card-dash .card-title {
            font-size: 0.78rem;
        }
        .card-dash .card-value {
            font-size: 1.25rem;
        }

        .mobile-search-bar {
            width: 100% !important;
            max-width: 160px;
        }
    }
    </style>
</head>
<body>
