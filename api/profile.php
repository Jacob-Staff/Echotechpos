<?php
/**
 * PHARMANOVA Online Store - Customer Profile / Account
 */
require_once __DIR__ . '/store_header.php';

$client = null;
if (isset($_SESSION['client_id'])) {
    $client_id = (int)$_SESSION['client_id'];
    if ($stmt = $conn->prepare("SELECT id, full_name, phone, email FROM clients WHERE id = ? LIMIT 1")) {
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
}
?>

<style>
    .profile-page{background:#f7f9fb;min-height:calc(100vh - 180px);padding:24px 0 55px;}
    .profile-shell{max-width:1050px;margin:0 auto;}
    .profile-hero{background:#fff;border:1px solid #e3e9ed;border-radius:14px;padding:22px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(20,40,60,.04);margin-bottom:16px;}
    .profile-avatar{width:66px;height:66px;border-radius:50%;background:#eef5ff;color:#1769d1;display:flex;align-items:center;justify-content:center;font-size:34px;flex:0 0 66px;}
    .profile-hero h1{margin:0;color:#243746;font-size:22px;font-weight:800;}
    .profile-hero p{margin:5px 0 0;color:#71808d;font-size:12px;}
    .profile-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:16px;}
    .profile-menu,.profile-card{background:#fff;border:1px solid #e3e9ed;border-radius:12px;box-shadow:0 2px 8px rgba(20,40,60,.03);overflow:hidden;}
    .profile-menu-title{padding:14px 16px;border-bottom:1px solid #edf0f2;color:#344958;font-size:13px;font-weight:800;}
    .profile-menu a{display:flex;align-items:center;gap:10px;padding:13px 15px;text-decoration:none;color:#536775;font-size:12px;font-weight:600;border-bottom:1px solid #f0f2f4;}
    .profile-menu a:hover,.profile-menu a.active{background:#f3f8fe;color:#1769d1;}
    .profile-menu a i{font-size:19px;width:23px;text-align:center;}
    .profile-card{padding:0;margin-bottom:16px;}
    .profile-card:last-child{margin-bottom:0;}
    .profile-card-head{padding:15px 18px;border-bottom:1px solid #edf0f2;display:flex;align-items:center;justify-content:space-between;}
    .profile-card-head h2{margin:0;color:#293e4d;font-size:16px;font-weight:800;}
    .profile-card-head span{font-size:11px;color:#84919b;}
    .profile-fields{display:grid;grid-template-columns:1fr 1fr;gap:0;}
    .profile-field{padding:15px 18px;border-bottom:1px solid #f0f2f4;}
    .profile-field:nth-child(odd){border-right:1px solid #f0f2f4;}
    .profile-field label{display:block;color:#8a969f;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
    .profile-field div{color:#344958;font-size:13px;font-weight:600;word-break:break-word;}
    .profile-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:16px 18px;}
    .profile-action{display:flex;align-items:center;gap:11px;padding:13px;border:1px solid #e3e9ed;border-radius:9px;text-decoration:none;color:#344958;background:#fff;transition:.15s;}
    .profile-action:hover{border-color:#c9ddf8;background:#f7fbff;color:#1769d1;}
    .profile-action i{font-size:23px;color:#1769d1;}
    .profile-action strong{display:block;font-size:12px;}
    .profile-action span{display:block;color:#8a969f;font-size:10px;margin-top:2px;}
    .profile-login{max-width:600px;margin:25px auto;background:#fff;border:1px solid #e3e9ed;border-radius:14px;padding:30px;text-align:center;box-shadow:0 2px 8px rgba(20,40,60,.04);}
    .profile-login i{font-size:55px;color:#1769d1;}
    .profile-login h1{font-size:22px;font-weight:800;color:#243746;margin:10px 0 6px;}
    .profile-login p{font-size:12px;color:#71808d;margin-bottom:18px;}
    @media(max-width:767.98px){
        .profile-page{padding:12px 10px 25px;}
        .profile-hero{padding:15px;border-radius:10px;margin-bottom:10px;}
        .profile-avatar{width:52px;height:52px;flex-basis:52px;font-size:27px;}
        .profile-hero h1{font-size:18px;}
        .profile-hero p{font-size:10px;}
        .profile-layout{display:block;}
        .profile-menu{margin-bottom:10px;}
        .profile-menu-title{padding:12px 14px;}
        .profile-menu a{padding:11px 13px;}
        .profile-fields{grid-template-columns:1fr;}
        .profile-field{padding:13px 14px;}
        .profile-field:nth-child(odd){border-right:0;}
        .profile-actions{grid-template-columns:1fr;padding:12px 14px;}
        .profile-card-head{padding:13px 14px;}
        .profile-login{margin:15px 0;padding:25px 18px;}
    }
</style>

<main class="profile-page">
    <div class="container-fluid px-2 px-md-4">
        <div class="profile-shell">
            <?php if (!$client): ?>
                <section class="profile-login">
                    <i class="mdi mdi-account-circle-outline"></i>
                    <h1>Welcome to <?php echo htmlspecialchars($pharmacy_name); ?></h1>
                    <p>Sign in to manage your profile, orders, prescriptions and pharmacy account.</p>
                    <a href="login_client.php?bid=<?php echo $branch_id; ?>" class="btn btn-primary px-4">Login / Register</a>
                </section>
            <?php else: ?>
                <?php
                    $display_name = trim($client['full_name'] ?? 'Customer');
                    $first_letter = strtoupper(substr($display_name, 0, 1));
                ?>
                <section class="profile-hero">
                    <div class="profile-avatar"><?php echo htmlspecialchars($first_letter ?: 'C'); ?></div>
                    <div>
                        <h1>Hello, <?php echo htmlspecialchars($display_name); ?></h1>
                        <p>Manage your <?php echo htmlspecialchars($pharmacy_name); ?> account · <?php echo htmlspecialchars($branch_name); ?></p>
                    </div>
                </section>

                <div class="profile-layout">
                    <aside class="profile-menu">
                        <div class="profile-menu-title">My Account</div>
                        <a class="active" href="profile.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-account-outline"></i> Profile</a>
                        <a href="client_orders.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-package-variant-closed"></i> My Orders</a>
                        <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-prescription"></i> Prescriptions</a>
                        <a href="view_cart.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-cart-outline"></i> My Cart</a>
                        <a href="help.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-help-circle-outline"></i> Help & Support</a>
                        <a href="logout_client.php" class="text-danger"><i class="mdi mdi-logout"></i> Logout</a>
                    </aside>

                    <section>
                        <article class="profile-card">
                            <div class="profile-card-head">
                                <h2>Personal Information</h2>
                                <span>Account #<?php echo str_pad((string)$client['id'], 5, '0', STR_PAD_LEFT); ?></span>
                            </div>
                            <div class="profile-fields">
                                <div class="profile-field"><label>Full Name</label><div><?php echo htmlspecialchars($client['full_name'] ?? 'Not provided'); ?></div></div>
                                <div class="profile-field"><label>Phone</label><div><?php echo htmlspecialchars($client['phone'] ?? 'Not provided'); ?></div></div>
                                <div class="profile-field"><label>Email</label><div><?php echo htmlspecialchars($client['email'] ?? 'Not provided'); ?></div></div>
                                <div class="profile-field"><label>Shopping Branch</label><div><?php echo htmlspecialchars($branch_name); ?></div></div>
                            </div>
                        </article>

                        <article class="profile-card">
                            <div class="profile-card-head"><h2>Quick Access</h2><span>Manage your pharmacy services</span></div>
                            <div class="profile-actions">
                                <a class="profile-action" href="client_orders.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-package-variant-closed"></i><span><strong>My Orders</strong><span>View your online orders</span></span></a>
                                <a class="profile-action" href="upload_prescription.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-file-upload-outline"></i><span><strong>Upload Prescription</strong><span>Send a prescription securely</span></span></a>
                                <a class="profile-action" href="lab_results.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-flask-outline"></i><span><strong>Lab Results</strong><span>Access available results</span></span></a>
                                <a class="profile-action" href="view_cart.php?bid=<?php echo $branch_id; ?>"><i class="mdi mdi-cart-outline"></i><span><strong>My Cart</strong><span>Continue shopping</span></span></a>
                            </div>
                        </article>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php
$footer_path = __DIR__ . "/includes/footer.php";
if (file_exists($footer_path)) require $footer_path;
?>
