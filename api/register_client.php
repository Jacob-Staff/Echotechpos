<?php
require "includes/conn.php";
$msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $pass  = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $check = $conn->query("SELECT id FROM clients WHERE email = '$email'");
    if ($check->num_rows > 0) {
        $msg = "<div class='alert alert-danger'>Email already registered!</div>";
    } else {
        $sql = "INSERT INTO clients (full_name, email, phone, password) VALUES ('$name', '$email', '$phone', '$pass')";
        if ($conn->query($sql)) {
            header("Location: login_client.php?success=1");
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register | Client Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{ background: #f5faff; } .card{ border: none; border-radius: 15px; }</style>
</head>
<body class="d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow-lg p-4">
                <h3 class="fw-bold text-center mb-4">Create Account</h3>
                <?php echo $msg; ?>
                <form method="POST">
                    <div class="mb-3"><input type="text" name="full_name" class="form-control" placeholder="Full Name" required></div>
                    <div class="mb-3"><input type="email" name="email" class="form-control" placeholder="Email Address" required></div>
                    <div class="mb-3"><input type="text" name="phone" class="form-control" placeholder="Phone Number" required></div>
                    <div class="mb-3"><input type="password" name="password" class="form-control" placeholder="Password" required></div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2">Register</button>
                </form>
                <p class="text-center mt-3 small">Already have an account? <a href="login_client.php">Login</a></p>
            </div>
        </div>
    </div>
</div>
</body>
</html>