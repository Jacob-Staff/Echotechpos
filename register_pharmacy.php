<?php
session_start();
require_once 'includes/conn.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Capture Form Data
    $brand_name        = trim($_POST['brand_name']);
    $first_branch_name = trim($_POST['first_branch_name']);
    $branch_code       = strtoupper(trim($_POST['branch_code']));
    $location          = trim($_POST['location']);
    $username          = trim($_POST['username']);
    $email             = trim($_POST['email']);
    $password          = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $conn->begin_transaction();

    try {
        // 2. Create the Pharmacy Entity (The Brand)
        $stmt1 = $conn->prepare("INSERT INTO pharmacies (name, address) VALUES (?, ?)");
        $stmt1->bind_param("ss", $brand_name, $location);
        $stmt1->execute();
        $pharmacy_id = $conn->insert_id;

        // 3. Create the FIRST Branch (Using YOUR specific input names)
        $stmt2 = $conn->prepare("INSERT INTO branches (pharmacy_id, branch_name, branch_code, is_active) VALUES (?, ?, ?, 1)");
        $stmt2->bind_param("iss", $pharmacy_id, $first_branch_name, $branch_code);
        $stmt2->execute();
        $branch_id = $conn->insert_id;

        // 4. Create the Master Admin User linked to both IDs
        $role = 'Admin';
        $status = 'Active';
        // The fix is here: "iisssss" instead of "iissss"
        $stmt3 = $conn->prepare("INSERT INTO users (pharmacy_id, branch_id, username, password, email, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt3->bind_param("iisssss", $pharmacy_id, $branch_id, $username, $password, $email, $role, $status);
        $stmt3->execute();

        $conn->commit();
        header("Location: login_inc.php?status=success");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        if ($conn->errno == 1062) {
            $error_msg = "Error: That username or email is already registered.";
        } else {
            $error_msg = "Registration Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHARMA-JACOBS | Register Pharmacy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --body-bg: #0f111a;
            --card-bg: #161b22;
            --card-border: #30363d;
            --input-bg: #0d1117;
            --main-text-color: #ffffff;
            --brand-text-color: #00d2ff; 
            --label-text-color: #94a3b8; /* Lightened for better visibility */
            --placeholder-color: #64748b; /* Standard visible grey for placeholders */
            --input-text-color: #ffffff;
            --accent-color: #00d2ff;
        }

        body { 
            background: var(--body-bg); 
            color: var(--main-text-color); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0; 
            font-family: 'Poppins', sans-serif;
            padding: 20px;
        }

        .reg-card { 
            background: var(--card-bg); 
            border: 1px solid var(--card-border); 
            border-radius: 15px; 
            padding: 2.5rem; 
            width: 100%; 
            max-width: 550px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
        }

        .brand-header {
            text-align: center;
            font-weight: 800;
            color: var(--brand-text-color);
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .brand-subtext {
            text-align: center;
            font-size: 0.8rem;
            color: var(--label-text-color);
            margin-bottom: 30px;
        }

        /* CRITICAL: Input and Placeholder Styling */
        .form-control { 
            background: var(--input-bg) !important; 
            border: 1px solid var(--card-border); 
            color: var(--input-text-color) !important; 
            padding: 12px;
            font-size: 0.9rem;
        }
        
        .form-control::placeholder {
            color: var(--placeholder-color) !important;
            opacity: 1; /* Browser default fix */
        }

        .form-control:focus {
            background: var(--input-bg);
            border-color: var(--accent-color);
            color: var(--input-text-color);
            box-shadow: 0 0 0 0.25rem rgba(0, 210, 255, 0.1);
        }

        .btn-primary { 
            background: var(--accent-color); 
            border: none; 
            color: #000; /* Black text on blue button for high contrast */
            font-weight: 700; 
            padding: 12px;
            transition: 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-primary:hover {
            background: #33e0ff;
            transform: translateY(-2px);
            color: #000;
        }

        hr { border-top: 1px solid var(--card-border); opacity: 0.5; margin: 20px 0; }
        
        .section-title { 
            color: var(--accent-color); 
            font-size: 0.75rem; 
            font-weight: 700; 
            margin-bottom: 15px; 
            letter-spacing: 1px;
        }
        
        label {
            color: var(--label-text-color);
            font-size: 0.8rem;
            margin-bottom: 5px;
            display: block;
        }

        .alert-custom {
            background: #2a1215;
            color: #ff7b72;
            border: 1px solid #f85149;
            font-size: 0.85rem;
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 15px;
        }
    </style>
</head>
<body>

<div class="reg-card">
    <h1 class="brand-header">PHARMA JACOBS</h1>
    <p class="brand-subtext">MULTI-TENANT POS SYSTEM</p>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-custom"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="section-title text-uppercase">1. Brand Identity</div>
        <div class="mb-3">
            <label>Corporate Name</label>
            <input type="text" name="brand_name" class="form-control" placeholder="e.g. Pharmanova" required>
        </div>
        <div class="mb-3">
            <label>Headquarters Location</label>
            <input type="text" name="location" class="form-control" placeholder="e.g. Lusaka, Zambia" required>
        </div>

        <div class="section-title text-uppercase mt-4">2. Initial Branch Configuration</div>
        <div class="row">
            <div class="col-md-8 mb-3">
                <label>Branch Display Name</label>
                <input type="text" name="first_branch_name" class="form-control" placeholder="e.g. Pharmanova - Main" required>
            </div>
            <div class="col-md-4 mb-3">
                <label>Branch Code</label>
                <input type="text" name="branch_code" class="form-control" placeholder="e.g. PN-01" required>
            </div>
        </div>

        <hr>

        <div class="section-title text-uppercase">3. Master Admin Account</div>
        <div class="mb-3">
            <label>Admin Username</label>
            <input type="text" name="username" class="form-control" placeholder="Choose username" required>
        </div>
        <div class="mb-3">
            <label>Business Email</label>
            <input type="email" name="email" class="form-control" placeholder="admin@brand.com" required>
        </div>
        <div class="mb-3">
            <label>Secure Password</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>

        <button type="submit" class="btn btn-primary w-100 mt-3 shadow-sm rounded-pill">INITIALIZE NEW ACCOUNT</button>
    </form>
    
    <div class="text-center mt-4">
        <a href="login_inc.php" style="color: var(--label-text-color); text-decoration: none; font-size: 0.85rem;">Already have an account? <span style="color:var(--accent-color)">Log In</span></a>
    </div>
</div>

</body>
</html>