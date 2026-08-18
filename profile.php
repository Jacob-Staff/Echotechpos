<?php
require "api/store_header.php"; 
require "includes/conn.php";

// Redirect if not logged in
if (!isset($_SESSION['client_id'])) {
    header("Location: login_client.php");
    exit();
}

$client_id = $_SESSION['client_id'];

// Fetch latest client data from database
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

// Fallback for missing fields
$member_since = isset($client['created_at']) ? date('M Y', strtotime($client['created_at'])) : "Recent";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account | <?php echo htmlspecialchars($client['full_name']); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.5.95/css/materialdesignicons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --echo-teal: #003339;
            --echo-green: #00b386;
            --echo-blue: #1a4a7c;
        }
        body { background: #f4f7f6; font-family: 'IBM Plex Sans', sans-serif; }
        
        .profile-card { background: white; border-radius: 15px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        
        .nav-pills .nav-link { color: #555; font-weight: 600; border-radius: 10px; padding: 12px 20px; transition: 0.3s; margin-bottom: 5px; text-align: left; }
        .nav-pills .nav-link:hover { background: #f8f9fa; color: var(--echo-teal); }
        .nav-pills .nav-link.active { background: var(--echo-teal); color: white; }
        
        .form-control { border-radius: 8px; padding: 12px; border: 1px solid #e0e0e0; font-size: 14px; }
        .form-control:focus { border-color: var(--echo-green); box-shadow: none; }
        
        .btn-save { background: var(--echo-green); color: white; font-weight: 700; border: none; padding: 12px 30px; border-radius: 8px; transition: 0.3s; width: auto; }
        .btn-save:hover { background: #008f6b; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,179,134,0.3); }
        
        .pay-method-card { background: #f9f9f9; border: 1px solid #eee; border-radius: 12px; padding: 15px; height: 100%; transition: 0.2s; }
        .pay-method-card:hover { border-color: var(--echo-green); background: #fff; }
        
        .hr-text { display: flex; align-items: center; font-size: 11px; font-weight: bold; color: #aaa; text-transform: uppercase; letter-spacing: 1px; }
        .hr-text::before, .hr-text::after { content: ""; flex: 1; height: 1px; background: #eee; }
        .hr-text span { padding: 0 10px; }

        .alert { border-radius: 10px; border: none; font-size: 14px; }
    </style>
</head>
<body>

<div class="container py-5">
    <?php if(isset($_GET['status'])): ?>
        <div class="row justify-content-center">
            <div class="col-lg-11 col-xl-10">
                <?php if($_GET['status'] == 'success'): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm" role="alert">
                        <i class="mdi mdi-check-circle me-2"></i> <strong>Success!</strong> Your details have been updated.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4 shadow-sm" role="alert">
                        <i class="mdi mdi-alert-circle me-2"></i> <strong>Error:</strong> Update failed. Please try again.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-3">
            <div class="profile-card p-4 text-center mb-4">
                <div class="position-relative d-inline-block mb-3">
                    <i class="mdi mdi-account-circle" style="font-size: 90px; color: var(--echo-teal);"></i>
                    <span class="position-absolute bottom-0 end-0 bg-success border border-white border-3 rounded-circle" style="width:20px; height:20px;"></span>
                </div>
                <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($client['full_name']); ?></h5>
                <p class="text-muted small mb-3">ID: #<?php echo str_pad($client['id'], 5, '0', STR_PAD_LEFT); ?></p>
                <div class="badge bg-light text-dark border px-3 py-2 rounded-pill mb-3" style="font-size: 11px;">
                    Member since <?php echo $member_since; ?>
                </div>
                <hr>
                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#details" type="button">
                        <i class="mdi mdi-account-cog-outline me-2"></i> Personal Details
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#payment" type="button">
                        <i class="mdi mdi-credit-card-settings-outline me-2"></i> Payment Info
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#security" type="button">
                        <i class="mdi mdi-shield-lock-outline me-2"></i> Password & Security
                    </button>
                    <a href="logout.php" class="nav-link text-danger mt-2">
                        <i class="mdi mdi-logout me-2"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="profile-card p-4 h-100">
                <div class="tab-content" id="v-pills-tabContent">
                    
                    <div class="tab-pane fade show active" id="details">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0">Personal Profile</h5>
                            <i class="mdi mdi-account-edit text-muted fs-4"></i>
                        </div>
                        <form action="api/update_profile.php" method="POST">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted mb-1">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($client['full_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted mb-1">Mobile Number</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($client['phone']); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold text-muted mb-1">Email Address</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($client['email']); ?>" required>
                                </div>
                                <div class="col-12 text-end pt-3">
                                    <button type="submit" class="btn-save">Save Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="payment">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h5 class="fw-bold mb-1">Payment Methods</h5>
                                <p class="text-muted small mb-0">Manage your Mobile Money and Bank details.</p>
                            </div>
                            <button class="btn btn-sm btn-dark px-3 rounded-pill" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                <i class="mdi mdi-pencil me-1"></i> Update Details
                            </button>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="pay-method-card border-start border-warning border-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="mdi mdi-cellphone-nfc text-warning fs-3 me-2"></i>
                                        <h6 class="fw-bold mb-0">Mobile Money</h6>
                                    </div>
                                    <p class="mb-0 small text-dark">
                                        <strong>Number:</strong> <?php echo !empty($client['payment_info']) ? htmlspecialchars($client['payment_info']) : '<span class="text-muted">Not set</span>'; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="pay-method-card border-start border-primary border-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="mdi mdi-bank text-primary fs-3 me-2"></i>
                                        <h6 class="fw-bold mb-0">Bank Account</h6>
                                    </div>
                                    <?php if(!empty($client['bank_acc_no'])): ?>
                                        <div class="small">
                                            <div class="text-dark"><strong>Bank:</strong> <?php echo htmlspecialchars($client['bank_name']); ?></div>
                                            <div class="text-dark"><strong>Account:</strong> <?php echo htmlspecialchars($client['bank_acc_no']); ?></div>
                                            <div class="text-muted" style="font-size: 11px;"><?php echo htmlspecialchars($client['bank_acc_name']); ?></div>
                                        </div>
                                    <?php else: ?>
                                        <p class="mb-0 small text-muted">No bank details added.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 p-3 bg-light rounded-3">
                            <p class="text-muted mb-0" style="font-size: 12px;">
                                <i class="mdi mdi-information-outline me-1"></i> 
                                These details will be used for automated payments and withdrawal requests.
                            </p>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="security">
                        <h5 class="fw-bold mb-4">Security Settings</h5>
                        <form action="api/update_password.php" method="POST">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-1">Current Password</label>
                                <input type="password" name="old_pass" class="form-control" placeholder="••••••••" required>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-1">New Password</label>
                                <input type="password" name="new_pass" class="form-control" placeholder="Minimum 6 characters" required>
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-1">Verify New Password</label>
                                <input type="password" name="confirm_pass" class="form-control" placeholder="••••••••" required>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn-save" style="background: var(--echo-teal);">Update Security</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white border-0">
                <h6 class="modal-title fw-bold text-white"><i class="mdi mdi-bank-plus me-2"></i>Update Payment Info</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="api/update_payment.php" method="POST">
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="small fw-bold text-muted mb-1">Mobile Money Number</label>
                        <input type="text" name="payment_info" class="form-control" placeholder="e.g. 097..." value="<?php echo htmlspecialchars($client['payment_info'] ?? ''); ?>">
                        <small class="text-muted" style="font-size: 10px;">Airtel, MTN, or Zamtel number</small>
                    </div>

                    <div class="hr-text mb-3">
                        <span>OR BANK DETAILS</span>
                    </div>

                    <div class="row g-2">
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g. Zanaco, FNB" value="<?php echo htmlspecialchars($client['bank_name'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Account Holder Name</label>
                            <input type="text" name="bank_acc_name" class="form-control" placeholder="Name as it appears on bank" value="<?php echo htmlspecialchars($client['bank_acc_name'] ?? ''); ?>">
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">Account Number</label>
                            <input type="text" name="bank_acc_no" class="form-control" placeholder="Enter account number" value="<?php echo htmlspecialchars($client['bank_acc_no'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light btn-sm fw-bold px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-save btn-sm py-2 px-4">Update All Details</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if(file_exists("includes/footer.php")) require "includes/footer.php"; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>