<?php
session_start();
require "includes/conn.php";
$msg = "";

// Capture preset branch ID if passed in URL
$preset_bid = isset($_GET['bid']) ? intval($_GET['bid']) : (isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : 0);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $pass  = $_POST['password'];
    $bid   = intval($_POST['branch_id']);

    $res = $conn->query("SELECT * FROM clients WHERE email = '$email'");
    if ($res && $res->num_rows > 0) {
        $user = $res->fetch_assoc();
        if (password_verify($pass, $user['password'])) {
            $_SESSION['client_id']   = $user['id'];
            $_SESSION['client_name'] = $user['full_name'];
            $_SESSION['branch_id']   = $bid;

            // Redirect to the online store for the selected branch
            header("Location: online_store.php?bid=" . $bid);
            exit();
        } else { 
            $msg = "Invalid password!"; 
        }
    } else { 
        $msg = "User not found!"; 
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login | Client Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow p-4 border-0 rounded-4">
                <h4 class="fw-bold mb-3 text-center">Welcome Back</h4>
                <?php if($msg) echo "<div class='alert alert-danger'>$msg</div>"; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="Email" required>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                    </div>
                    
                    <hr>
                    <label class="small fw-bold mb-2 text-muted text-uppercase">Select Store to Browse</label>
                    
                    <div class="mb-3">
                        <select id="pharmacy_select" class="form-select mb-2" required>
                            <option value="">-- Select Pharmacy Group --</option>
                            <?php 
                            $ph = $conn->query("SELECT * FROM pharmacies");
                            if($ph) {
                                while($p = $ph->fetch_assoc()) {
                                    echo "<option value='{$p['id']}'>{$p['name']}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <select name="branch_id" id="branch_select" class="form-select" required disabled>
                            <option value="">-- Select Branch --</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-dark w-100 py-2 fw-bold">Login & Start Shopping</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    var presetBranchId = <?php echo $preset_bid; ?>;

    // Dynamic Branch loading based on Pharmacy selection
    $('#pharmacy_select').on('change', function() {
        var pid = $(this).val();
        if(pid) {
            $.ajax({
                url: 'api/get_branches.php',
                method: 'GET',
                data: { pharmacy_id: pid },
                success: function(html) {
                    $('#branch_select').html(html).prop('disabled', false);
                    if(presetBranchId > 0) {
                        $('#branch_select').val(presetBranchId);
                    }
                }
            });
        } else {
            $('#branch_select').prop('disabled', true).html('<option>-- Select Branch --</option>');
        }
    });
});
</script>
</body>
</html>
