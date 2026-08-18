<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php'; 

require_admin();

if (!isset($_SESSION['pharmacy_id'])) {
    header("Location: ../login_inc.php?error=session_expired");
    exit();
}

$pharmacy_id = $_SESSION['pharmacy_id'];

// --- LOGIC: Handle Add/Edit Branch ---
if (isset($_POST['save_branch'])) {
    $name = $conn->real_escape_string($_POST['branch_name']);
    $loc = $conn->real_escape_string($_POST['location']);
    $code = $conn->real_escape_string($_POST['branch_code']);
    $phone = $conn->real_escape_string($_POST['phone']);
    
    // Payment Details - Individually captured
    $bank_name = $conn->real_escape_string($_POST['bank_name']);
    $bank_bcode = $conn->real_escape_string($_POST['bank_branch_code']);
    $acc_name  = $conn->real_escape_string($_POST['acc_name']);
    $acc_no    = $conn->real_escape_string($_POST['acc_no']);
    $momo_mtn  = $conn->real_escape_string($_POST['momo_mtn']);
    $momo_airtel = $conn->real_escape_string($_POST['momo_airtel']);

    // Combine for storage with clear labels
    $bank_combined = "Bank: $bank_name | BCode: $bank_bcode | Acc: $acc_name | No: $acc_no";
    $momo_combined = "MTN: $momo_mtn | Airtel: $momo_airtel";

    if (!empty($_POST['branch_id'])) {
        $id = intval($_POST['branch_id']);
        $stmt = $conn->prepare("UPDATE branches SET branch_name=?, location=?, branch_code=?, phone=?, bank_details=?, mobile_money_details=? WHERE id=? AND pharmacy_id=?");
        $stmt->bind_param("ssssssii", $name, $loc, $code, $phone, $bank_combined, $momo_combined, $id, $pharmacy_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO branches (pharmacy_id, branch_name, location, branch_code, phone, bank_details, mobile_money_details, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("issssss", $pharmacy_id, $name, $loc, $code, $phone, $bank_combined, $momo_combined);
        $stmt->execute();
    }
    header("Location: manage_setup.php?msg=success");
    exit();
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM branches WHERE id=? AND pharmacy_id=?");
    $stmt->bind_param("ii", $id, $pharmacy_id);
    $stmt->execute();
    header("Location: manage_setup.php?deleted=1");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Branch Architecture | PHARMA-JACOBS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root { --accent: #00d2ff; --bg-dark: #0f111a; --card-bg: #161b22; }
        body { background-color: var(--bg-dark); color: #ffffff; font-family: 'Inter', sans-serif; }
        .sidebar { width: 280px; height: 100vh; position: fixed; background: var(--card-bg); border-right: 1px solid #30363d; padding: 1.5rem; }
        .main-content { margin-left: 280px; padding: 2.5rem; }
        .stat-card { background: var(--card-bg); border: 1px solid #30363d; border-radius: 16px; padding: 1.5rem; }
        
        /* High Contrast Labels */
        label.small.text-muted { color: #e1e1e1 !important; font-weight: 500; margin-bottom: 4px; }
        
        /* Input Styling */
        .form-control { 
            background-color: #0d1117; 
            border: 1px solid #444c56; 
            color: #ffffff; 
            font-size: 0.9rem;
            padding: 0.7rem;
        }
        .form-control:focus { background-color: #0d1117; color: white; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(0, 210, 255, 0.2); }
        
        /* Vivid White Placeholders */
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.9) !important;
            opacity: 1;
        }

        /* Fixed Faint Text: Now high-contrast white */
        .nav-link { color: #ffffff !important; padding: 10px; border-radius: 8px; text-decoration: none; display: block; transition: 0.3s; font-weight: 500; }
        .nav-link:hover, .nav-link.active { background: #21262d; color: var(--accent) !important; }
        
        .payment-section { background: rgba(255, 255, 255, 0.03); border: 1px solid #30363d; border-radius: 12px; padding: 15px; margin-top: 15px; }
        .text-accent { color: var(--accent); }
    </style>
</head>
<body>

<div class="sidebar">
    <h4 class="fw-bold mb-4" style="color:var(--accent)"><i class="fas fa-layer-group"></i> STRUCTURE</h4>
    <nav class="nav flex-column">
        <a class="nav-link mb-2" href="admin_dashboard.php"><i class="fas fa-arrow-left me-2"></i> Dashboard Overview</a>
        <a class="nav-link active" href="manage_setup.php"><i class="fas fa-store me-2"></i> Manage Branches</a>
    </nav>
</div>

<div class="main-content">
    <div class="row g-4">
        <div class="col-md-5">
            <div class="stat-card">
                <h4 class="fw-bold mb-4 text-white" id="formTitle">Branch Configuration</h4>
                <form method="POST" id="branchForm">
                    <input type="hidden" name="branch_id" id="branch_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="small text-muted">Branch Code</label>
                            <input type="text" name="branch_code" id="branch_code" class="form-control" placeholder="EX: LSK-01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small text-muted">Contact Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control" placeholder="+260...">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="small text-muted">Branch Name</label>
                        <input type="text" name="branch_name" id="branch_name" class="form-control" placeholder="Enter Full Branch Name" required>
                    </div>

                    <div class="mb-3">
                        <label class="small text-muted">Location / City</label>
                        <input type="text" name="location" id="location" class="form-control" placeholder="e.g. Town Center, Lusaka">
                    </div>

                    <div class="payment-section">
                        <h6 class="mb-3 text-info fw-bold"><i class="fas fa-university me-2"></i>BANKING DETAILS</h6>
                        <div class="row g-2 mb-2">
                            <div class="col-7">
                                <input type="text" name="bank_name" id="bank_name" class="form-control" placeholder="Bank Name (FNB/ABSA)">
                            </div>
                            <div class="col-5">
                                <input type="text" name="bank_branch_code" id="bank_branch_code" class="form-control" placeholder="Branch Code">
                            </div>
                        </div>
                        <div class="mb-2">
                            <input type="text" name="acc_name" id="acc_name" class="form-control" placeholder="Account Holder Name">
                        </div>
                        <div class="mb-2">
                            <input type="text" name="acc_no" id="acc_no" class="form-control" placeholder="Account Number">
                        </div>

                        <h6 class="mt-4 mb-3 text-warning fw-bold"><i class="fas fa-mobile-alt me-2"></i>MOBILE MONEY</h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="text" name="momo_mtn" id="momo_mtn" class="form-control" placeholder="MTN Number">
                            </div>
                            <div class="col-6">
                                <input type="text" name="momo_airtel" id="momo_airtel" class="form-control" placeholder="Airtel Number">
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="save_branch" class="btn btn-primary w-100 rounded-pill mt-4 fw-bold">UPDATE BRANCH DATA</button>
                    <button type="button" onclick="resetForm()" class="btn btn-link btn-sm w-100 text-white mt-2 text-decoration-none">Clear Form</button>
                </form>
            </div>
        </div>

        <div class="col-md-7">
            <div class="stat-card p-0 overflow-hidden">
                <table class="table table-dark table-hover mb-0 align-middle">
                    <thead class="table-light text-dark">
                        <tr>
                            <th class="ps-3">Branch Identity</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("SELECT * FROM branches WHERE pharmacy_id = ? ORDER BY id DESC");
                        $stmt->bind_param("i", $pharmacy_id);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        
                        while($row = $res->fetch_assoc()):
                        ?>
                        <tr>
                            <td class="ps-3 py-3">
                                <span class="badge bg-info mb-1"><?php echo htmlspecialchars($row['branch_code']); ?></span>
                                <div class="fw-bold text-white"><?php echo htmlspecialchars($row['branch_name']); ?></div>
                                <small class="text-secondary"><?php echo htmlspecialchars($row['location']); ?></small>
                            </td>
                            <td>
                                <div style="font-size: 0.75rem;">
                                    <span class="<?php echo !empty($row['bank_details']) ? 'text-accent' : 'text-muted'; ?> d-block">
                                        <i class="fas fa-circle me-1" style="font-size:8px"></i> Bank Info
                                    </span>
                                    <span class="<?php echo !empty($row['mobile_money_details']) ? 'text-warning' : 'text-muted'; ?> d-block">
                                        <i class="fas fa-circle me-1" style="font-size:8px"></i> Mobile Money
                                    </span>
                                </div>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-info" onclick='editBranch(<?php echo json_encode($row); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Remove branch?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function editBranch(data) {
        document.getElementById('formTitle').innerText = "Edit " + data.branch_name;
        document.getElementById('branch_id').value = data.id;
        document.getElementById('branch_code').value = data.branch_code;
        document.getElementById('branch_name').value = data.branch_name;
        document.getElementById('location').value = data.location;
        document.getElementById('phone').value = data.phone;

        if(data.bank_details) {
            let bParts = data.bank_details.split(' | ');
            document.getElementById('bank_name').value = bParts[0] ? bParts[0].split(': ')[1] : '';
            document.getElementById('bank_branch_code').value = bParts[1] ? bParts[1].split(': ')[1] : '';
            document.getElementById('acc_name').value  = bParts[2] ? bParts[2].split(': ')[1] : '';
            document.getElementById('acc_no').value    = bParts[3] ? bParts[3].split(': ')[1] : '';
        }

        if(data.mobile_money_details) {
            let mParts = data.mobile_money_details.split(' | ');
            document.getElementById('momo_mtn').value    = mParts[0] ? mParts[0].split(': ')[1] : '';
            document.getElementById('momo_airtel').value = mParts[1] ? mParts[1].split(': ')[1] : '';
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('formTitle').innerText = "Branch Configuration";
        document.getElementById('branchForm').reset();
        document.getElementById('branch_id').value = "";
    }
</script>
</body>
</html>