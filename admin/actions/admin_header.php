<?php
$pharmacy_name = $pharmacy_name ?? 'PHARMACY POS';
$user_role = $user_role ?? 'Admin';
$user_display_name = $user_display_name ?? 'Administrator';
$branch_count = (int)($branch_count ?? 0);
$total_orders = (int)($total_orders ?? 0);
?>

<style>
:root{
    --bg:#f4f6f8;
    --surface:#ffffff;
    --surface-soft:#f8fafc;
    --charcoal:#202831;
    --charcoal-2:#2b3540;
    --charcoal-3:#374452;
    --text:#1d252d;
    --muted:#6d7782;
    --border:#dfe4e9;
    --blue:#246bfe;
    --blue-soft:#eaf1ff;
    --cyan:#19a9d2;
    --green:#159a68;
    --green-soft:#e8f7f0;
    --yellow:#e7a72e;
    --yellow-soft:#fff6df;
    --red:#d94d61;
    --red-soft:#fff0f2;
    --purple:#7658e8;
    --sidebar:250px;
    --radius:12px;
    --shadow:0 4px 18px rgba(31,40,49,.06);
}

*{
    box-sizing:border-box
}

html,
body{
    margin:0;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,Arial,sans-serif;
    font-size:14px;
}

body{
    overflow-x:hidden
}

button,
input{
    font:inherit
}

button{
    cursor:pointer
}

a{
    text-decoration:none;
    color:inherit
}


/* =========================================================
   ADMIN HEADER
   Black header — layout/functionality unchanged
========================================================= */

.admin-header{
    height:64px;
    border-bottom:1px solid #171717;
    background:#000;

    display:flex;
    align-items:center;
    justify-content:space-between;

    padding:0 28px;

    position:sticky;
    top:0;
    z-index:900;

    box-shadow:0 1px 7px rgba(0,0,0,.18);
}


/* =========================================================
   LEFT SIDE
========================================================= */

.admin-header .top-left{
    display:flex;
    align-items:center;
    gap:12px;
}

.admin-header .mobile-btn{
    display:none;
    width:36px;
    height:36px;

    border:1px solid #3a3a3a;
    border-radius:8px;

    background:#111;
    color:#fff;
}

.admin-header .mobile-btn:hover{
    background:#1c1c1c;
    border-color:#555;
}

.admin-header .crumb{
    font-size:12px;
    color:#aeb5bd;
}

.admin-header .crumb b{
    color:#fff;
    font-size:14px;
}

.admin-header .crumb span{
    color:#8f969e;
}


/* =========================================================
   RIGHT SIDE
========================================================= */

.admin-header .top-right{
    display:flex;
    align-items:center;
    gap:8px;
}


/* =========================================================
   SEARCH
========================================================= */

.admin-header .search-mini{
    width:230px;
    height:37px;

    background:#111;
    border:1px solid #363636;
    border-radius:8px;

    color:#fff;
    font-size:12px;

    padding:0 12px;
    outline:none;
}

.admin-header .search-mini::placeholder{
    color:#8d949c;
}

.admin-header .search-mini:focus{
    border-color:#246bfe;
    box-shadow:0 0 0 3px rgba(36,107,254,.18);
}


/* =========================================================
   HEADER ICON BUTTONS
========================================================= */

.admin-header .top-icon{
    width:37px;
    height:37px;

    background:#111;
    border:1px solid #363636;
    border-radius:8px;

    color:#c5cbd1;

    display:grid;
    place-items:center;
}

.admin-header .top-icon:hover{
    color:#fff;
    border-color:#5a5a5a;
    background:#1b1b1b;
}


/* =========================================================
   ACTIVE BRANCH INDICATOR
========================================================= */

.admin-header .branch{
    height:37px;

    padding:0 11px;

    border:1px solid #363636;
    background:#111;
    border-radius:8px;

    color:#c5cbd1;

    font-size:11px;

    display:flex;
    align-items:center;
    gap:7px;
}

.admin-header .branch i{
    color:#246bfe;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px){

    .admin-header{
        padding:0 16px;
    }

    .admin-header .mobile-btn{
        display:grid;
        place-items:center;
    }

    .admin-header .search-mini{
        display:none;
    }
}


@media(max-width:560px){

    .admin-header .branch{
        display:none;
    }

    .admin-header .top-right{
        gap:5px;
    }

}


/* =========================================================
   VERY SMALL PHONES
========================================================= */

@media(max-width:380px){

    .admin-header{
        padding:0 10px;
    }

    .admin-header .top-left{
        gap:8px;
    }

    .admin-header .crumb{
        font-size:10px;
    }

    .admin-header .crumb b{
        font-size:12px;
    }

    .admin-header .top-icon{
        width:34px;
        height:34px;
    }

    .admin-header .mobile-btn{
        width:34px;
        height:34px;
    }

}


/* =========================================================
   REDUCED MOTION
========================================================= */

@media(prefers-reduced-motion:reduce){

    .admin-header *,
    .admin-header *::before,
    .admin-header *::after{
        scroll-behavior:auto !important;
        transition:none !important;
        animation:none !important;
    }

}

</style>


<header class="admin-header" id="adminHeader">

    <div class="top-left">

        <button
            class="mobile-btn"
            id="adminMobileToggle"
            type="button"
            aria-label="Open admin menu"
        >
            <i class="fas fa-bars"></i>
        </button>

        <div class="crumb">

            <b>Admin Control Center</b>

            <span>&nbsp; / &nbsp;</span>

            <span>
                <?php echo htmlspecialchars(
                    $admin_page_title ?? 'Executive Overview',
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>
            </span>

        </div>

    </div>


    <div class="top-right">

        <input
            class="search-mini"
            id="adminHeaderSearch"
            placeholder="Search dashboard..."
            type="search"
            autocomplete="off"
            aria-label="Search dashboard"
        >


        <div class="branch">
            <i class="fas fa-layer-group"></i>

            <?php echo (int)$branch_count; ?>

            Active
        </div>


        <button
            class="top-icon"
            type="button"
            title="Refresh"
            aria-label="Refresh"
            onclick="location.reload()"
        >
            <i class="fas fa-rotate"></i>
        </button>


        <button
            class="top-icon"
            type="button"
            title="Notifications"
            aria-label="Notifications"
        >
            <i class="far fa-bell"></i>
        </button>


        <button
            class="top-icon"
            type="button"
            title="Account"
            aria-label="Account"
        >
            <i class="far fa-user"></i>
        </button>

    </div>

</header>


<script>
(function () {

    const aside = document.getElementById('adminAside');
    const toggle = document.getElementById('adminMobileToggle');

    if (toggle && aside) {

        toggle.addEventListener('click', function () {

            aside.classList.toggle('open');

        });

    }


    document.querySelectorAll('.admin-aside a').forEach(function (link) {

        link.addEventListener('click', function () {

            if (window.innerWidth <= 900 && aside) {

                aside.classList.remove('open');

            }

        });

    });

})();
</script>
