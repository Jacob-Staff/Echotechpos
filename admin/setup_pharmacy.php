<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/conn.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pharmacy_name = trim((string)($_POST['pharmacy_name'] ?? ''));
    $hq_address   = trim((string)($_POST['address'] ?? ''));
    $branch_code  = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
    $admin_user   = trim((string)($_POST['username'] ?? ''));
    $admin_email  = trim((string)($_POST['email'] ?? ''));
    $password     = (string)($_POST['password'] ?? '');

    if ($pharmacy_name === '' || $hq_address === '' || $branch_code === '' || $admin_user === '' || $admin_email === '' || $password === '') {
        $error = 'Please complete all required fields.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,9}$/', $branch_code)) {
        $error = 'Branch code may contain only letters, numbers, hyphen and underscore, up to 10 characters.';
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT id FROM branches WHERE branch_code = ? LIMIT 1');
            if (!$stmt) throw new RuntimeException('Unable to validate branch code.');
            $stmt->bind_param('s', $branch_code); $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) throw new RuntimeException('That branch code is already in use. Please choose another code.');
            $stmt->close();

            $stmt1 = $conn->prepare('INSERT INTO pharmacies (name, address) VALUES (?, ?)');
            if (!$stmt1) throw new RuntimeException('Unable to create pharmacy.');
            $stmt1->bind_param('ss', $pharmacy_name, $hq_address); $stmt1->execute();
            $pharmacy_id = (int)$conn->insert_id; $stmt1->close();

            $branch_name = $pharmacy_name . ' - Main Branch';
            $stmt2 = $conn->prepare('INSERT INTO branches (pharmacy_id, branch_code, branch_name, location, is_active) VALUES (?, ?, ?, ?, 1)');
            if (!$stmt2) throw new RuntimeException('Unable to create main branch.');
            $stmt2->bind_param('isss', $pharmacy_id, $branch_code, $branch_name, $hq_address); $stmt2->execute();
            $branch_id = (int)$conn->insert_id; $stmt2->close();

            $admin_pass = password_hash($password, PASSWORD_DEFAULT);
            $role = 'Admin'; $status = 'Active';
            $stmt3 = $conn->prepare('INSERT INTO users (username, password, email, role, branch_id, status) VALUES (?, ?, ?, ?, ?, ?)');
            if (!$stmt3) throw new RuntimeException('Unable to create administrator.');
            $stmt3->bind_param('ssssis', $admin_user, $admin_pass, $admin_email, $role, $branch_id, $status); $stmt3->execute(); $stmt3->close();

            $conn->commit();
            header('Location: ../index.php?status=setup_complete'); exit;
        } catch (Throwable $e) {
            $conn->rollback(); $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup New Pharmacy Tenant</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#0f111a;color:#fff;padding-top:50px}.setup-card{background:#161b22;border:1px solid #30363d;padding:30px;border-radius:15px}.form-control{background:#0d1117;color:#fff;border:1px solid #30363d}.form-control:focus{background:#0d1117;color:#fff}.form-text{color:#8b949e}</style></head>
<body><div class="container"><div class="row justify-content-center"><div class="col-md-6"><div class="setup-card shadow">
<h2 class="text-center mb-4">Register New Pharmacy</h2>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="POST">
<h5 class="text-info border-bottom pb-2">Business Details</h5>
<div class="mb-3"><label>Pharmacy Name</label><input type="text" name="pharmacy_name" class="form-control" required value="<?= htmlspecialchars((string)($_POST['pharmacy_name'] ?? ''), ENT_QUOTES) ?>"></div>
<div class="mb-3"><label>Headquarters Address</label><input type="text" name="address" class="form-control" required value="<?= htmlspecialchars((string)($_POST['address'] ?? ''), ENT_QUOTES) ?>"></div>
<div class="mb-3"><label>First Branch Code</label><input type="text" name="branch_code" class="form-control" maxlength="10" placeholder="e.g. PMV-01" required value="<?= htmlspecialchars((string)($_POST['branch_code'] ?? ''), ENT_QUOTES) ?>"><div class="form-text">This exact code becomes the prefix of every online order from this branch.</div></div>
<h5 class="text-info border-bottom pb-2 mt-4">Admin Account</h5>
<div class="mb-3"><label>Admin Username</label><input type="text" name="username" class="form-control" required></div>
<div class="mb-3"><label>Admin Email</label><input type="email" name="email" class="form-control" required></div>
<div class="mb-3"><label>Admin Password</label><input type="password" name="password" class="form-control" required></div>
<button type="submit" class="btn btn-primary w-100 mt-3">Finish Setup &amp; Create Admin</button></form>
</div></div></div></div></body></html>
