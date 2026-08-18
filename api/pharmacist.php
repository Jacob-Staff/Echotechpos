<?php 
require "store_header.php"; 
require "../includes/conn.php"; 

// 1. Get Context: Using bid to identify the Tenant (Pharmacy)
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 10;

// 2. Fetch Pharmacy (Tenant) details
$query = "SELECT p.id as pharmacy_id, p.name as pharmacy_name, b.branch_name 
          FROM branches b 
          JOIN pharmacies p ON b.pharmacy_id = p.id 
          WHERE b.id = $branch_id";
$res = $conn->query($query);
$row = $res->fetch_assoc();

$pharmacy_id = $row['pharmacy_id'] ?? 0;
$pharmacy_name = $row['pharmacy_name'] ?? "Our Pharmacy";

// 3. Query all Pharmacists for this specific Tenant that are online
$staff_sql = "SELECT u.full_name, u.email, u.profile_pic, u.mobile_number, u.whatsapp_number, b.branch_name, b.location 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.id
              WHERE u.pharmacy_id = $pharmacy_id 
              AND u.role = 'Pharmacist' 
              AND u.is_online_visible = 1"; 
$staff_result = $conn->query($staff_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Professional Pharmacists | <?php echo htmlspecialchars($pharmacy_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    <style>
        :root { 
            --primary-teal: #003339; 
            --accent-green: #00b386; 
            --glass-bg: rgba(255, 255, 255, 0.9);
        }

        body { 
            background: linear-gradient(135deg, #f5faff 0%, #e8f1f2 100%);
            font-family: 'Inter', -apple-system, sans-serif;
            min-height: 100vh;
        }

        /* Header Section */
        .page-header { padding: 60px 0 40px; text-align: center; }
        .header-title { color: var(--primary-teal); font-weight: 800; font-size: 2.5rem; letter-spacing: -1px; }
        .header-subtitle { color: #666; max-width: 700px; margin: 15px auto; font-size: 1.1rem; line-height: 1.6; }

        /* Pharmacist Card */
        .pharmacist-card {
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 20px;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            position: relative; 
            overflow: hidden;
        }
        .pharmacist-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,51,57,0.12);
            border-color: var(--accent-green);
        }

        /* Avatar */
        .avatar-wrapper { position: relative; display: inline-block; margin-bottom: 20px; }
        .avatar-img { width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 5px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .status-indicator { position: absolute; bottom: 5px; right: 5px; width: 18px; height: 18px; background: var(--accent-green); border: 3px solid white; border-radius: 50%; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow:0 0 0 0 rgba(0,179,134,0.4);} 70%{box-shadow:0 0 0 10px rgba(0,179,134,0);} 100%{box-shadow:0 0 0 0 rgba(0,179,134,0);} }

        /* Info Text */
        .pharmacist-name { color: var(--primary-teal); font-weight: 700; font-size: 1.2rem; margin-bottom: 4px; }
        .branch-info { font-size: 0.85rem; color: #555; background: rgba(0,179,134,0.1); padding: 5px 15px; border-radius: 30px; display: inline-flex; align-items: center; gap:5px; font-weight: 600; }

        /* Buttons */
        .consult-btn { background: var(--primary-teal); color: white; border-radius: 12px; padding: 10px 15px; font-weight: 600; text-decoration: none; transition:0.3s; border:none; }
        .consult-btn:hover { background: var(--accent-green); color: white; }

        .search-container { max-width:500px; margin:0 auto 50px; }
        .search-input { border-radius: 30px; padding:12px 25px; border:1px solid #ddd; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="container pb-5">
    <div class="pt-4">
        <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-link text-decoration-none text-dark fw-bold px-0">
            <i class="mdi mdi-chevron-left fs-4"></i> Back to Store
        </a>
    </div>

    <header class="page-header">
        <h1 class="header-title">Our Professional Pharmacists</h1>
        <p class="header-subtitle">
            Welcome to <strong><?php echo htmlspecialchars($pharmacy_name); ?></strong> clinical network. 
            Consult with our licensed experts for reliable advice and personalized pharmaceutical care.
        </p>
        <div class="search-container mt-4">
            <input type="text" id="searchPharmacist" class="form-control search-input" placeholder="Search by name or branch...">
        </div>
    </header>

    <div class="row g-4" id="pharmacistContainer">
        <?php if($staff_result && $staff_result->num_rows > 0): ?>
            <?php while($staff = $staff_result->fetch_assoc()): ?>
                <?php 
                    $contact_number = $staff['whatsapp_number'] ?: $staff['mobile_number']; 
                    $whatsapp_link = $contact_number ? "https://wa.me/".preg_replace('/\D/', '', $contact_number) : "#";
                ?>
                <div class="col-md-6 col-lg-4 col-xl-3 pharmacist-item">
                    <div class="pharmacist-card p-4 text-center">
                        <div class="avatar-wrapper">
                            <img src="../uploads/staff/<?php echo htmlspecialchars($staff['profile_pic']); ?>" 
                                 class="avatar-img" 
                                 alt="Pharmacist"
                                 onerror="this.src='../uploads/staff/default_avatar.png'">
                            <div class="status-indicator" title="Online for Consultation"></div>
                        </div>

                        <h3 class="pharmacist-name"><?php echo htmlspecialchars($staff['full_name']); ?></h3>
                        <p class="text-muted small mb-3">Licensed Pharmacist</p>

                        <div class="branch-info mb-3">
                            <i class="mdi mdi-store"></i>
                            <span><?php echo htmlspecialchars($staff['branch_name']); ?></span>
                            <?php if($staff['location']): ?>
                                <span class="opacity-50">|</span>
                                <span class="small"><?php echo htmlspecialchars($staff['location']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-center gap-2">
                            <a href="mailto:<?php echo htmlspecialchars($staff['email']); ?>" class="consult-btn">
                                <i class="mdi mdi-email-outline me-1"></i> Email
                            </a>
                            <?php if($contact_number): ?>
                                <a href="<?php echo $whatsapp_link; ?>" target="_blank" class="consult-btn">
                                    <i class="mdi mdi-whatsapp me-1"></i> WhatsApp
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <div class="bg-white rounded-4 p-5 shadow-sm d-inline-block">
                    <i class="mdi mdi-account-search-outline fs-1 text-muted"></i>
                    <h4 class="text-muted mt-3">No active pharmacists found</h4>
                    <p class="text-muted small mb-0">Please try again later or visit a physical <?php echo htmlspecialchars($pharmacy_name); ?> branch.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('searchPharmacist').addEventListener('keyup', function() {
    const filter = this.value.toLowerCase();
    document.querySelectorAll('.pharmacist-item').forEach(card => {
        const name = card.querySelector('.pharmacist-name').textContent.toLowerCase();
        const branch = card.querySelector('.branch-info').textContent.toLowerCase();
        card.style.display = (name.includes(filter) || branch.includes(filter)) ? "" : "none";
    });
});
</script>

<?php if(file_exists("../includes/footer.php")) require "../includes/footer.php"; ?>
</body>
</html>