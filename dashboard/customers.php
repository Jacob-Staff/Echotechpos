<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

/** * 1. IDENTITY SETUP */
$active_branch_id = $_SESSION['branch_id'] ?? 0;
$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0; // Get Pharmacy ID for linkage
$branch_id_safe = mysqli_real_escape_string($conn, $active_branch_id);

$branch_name = "Pharmanova LSK"; 
$branch_query = "SELECT branch_name FROM branches WHERE id = '$branch_id_safe' LIMIT 1";
$branch_res = mysqli_query($conn, $branch_query);
if ($branch_res && mysqli_num_rows($branch_res) > 0) {
    $branch_data = mysqli_fetch_assoc($branch_res);
    $branch_name = $branch_data['branch_name'];
}

$success = "";
$error = "";

/** * 2. DATABASE OPERATIONS */

// ADD CUSTOMER (Manual Branch Entry)
if(isset($_POST['add_customer'])){
    $name     = mysqli_real_escape_string($conn, $_POST['name']);
    $phone    = mysqli_real_escape_string($conn, $_POST['phone']);
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $branch_id_int = (int)$active_branch_id; 
    $pharm_id_int = (int)$pharmacy_id;

    $insert_sql = "INSERT INTO customers (pharmacy_id, branch_id, name, phone, email, address) 
                   VALUES ($pharm_id_int, $branch_id_int, '$name', '$phone', '$email', '$location')";
    if(mysqli_query($conn, $insert_sql)){
        $success = "Customer registered successfully!";
    } else {
        $error = "Error: " . mysqli_error($conn);
    }
}

// EDIT CUSTOMER
if(isset($_POST['edit_customer'])){
    $id       = (int)$_POST['customer_id'];
    $name     = mysqli_real_escape_string($conn, $_POST['name']);
    $phone    = mysqli_real_escape_string($conn, $_POST['phone']);
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $location = mysqli_real_escape_string($conn, $_POST['location']);

    // Security check: Only update if it belongs to this branch
    $update_sql = "UPDATE customers SET name='$name', phone='$phone', email='$email', address='$location' 
                   WHERE id=$id AND branch_id = $active_branch_id";
    if(mysqli_query($conn, $update_sql)){
        $success = "Customer updated successfully!";
    }
}

// DELETE CUSTOMER
if(isset($_POST['delete_customer'])){
    $id = (int)$_POST['customer_id'];
    // Security check: Only delete if it belongs to this branch
    if(mysqli_query($conn, "DELETE FROM customers WHERE id=$id AND branch_id = $active_branch_id")){
        $success = "Customer deleted.";
    }
}

/** * 3. FETCH DATA (FILTERED BY BRANCH) */
// This logic ensures that universal clients who "Subscribe" to this branch appear here.
$sql = "SELECT * FROM customers WHERE branch_id = $active_branch_id ORDER BY id DESC";
$result = mysqli_query($conn, $sql);
$customers = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
}
$total_count = count($customers);
?>

<style>
    .page-wrapper { padding: 25px; background: #f8f9fa; min-height: 100vh; }
    .debug-bar { background: #212529; color: #00ffcc; padding: 8px; font-family: monospace; text-align: center; }
    .card { border-radius: 12px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
    .table-header { background: #198754; color: white; border-radius: 12px 12px 0 0; padding: 15px; }
    .btn-action { margin-right: 5px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold">Client Management</h3>
    <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus"></i> Add New Client
    </button>
</div>

<?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<div class="card">
    <div class="table-header"><h5 class="mb-0">Customer List - <?php echo $branch_name; ?></h5></div>
    <div class="table-responsive p-3">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if($total_count > 0): foreach($customers as $row): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td>
                        <div class="fw-bold"><?php echo $row['name']; ?></div>
                        <small class="text-muted"><?php echo $row['email']; ?></small>
                        <?php if(!empty($row['client_id'])): ?>
                            <span class="badge bg-info text-dark" style="font-size: 0.7rem;">Subscribed App User</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $row['phone']; ?></td>
                    <td><?php echo $row['address']; ?></td>
                    <td class="text-center">
                        <a href="mailto:<?php echo $row['email']; ?>" class="btn btn-sm btn-outline-info btn-action" title="Send Email">
                            <i class="fas fa-envelope"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-primary btn-action edit-btn" 
                            data-id="<?php echo $row['id']; ?>" 
                            data-name="<?php echo $row['name']; ?>"
                            data-phone="<?php echo $row['phone']; ?>"
                            data-email="<?php echo $row['email']; ?>"
                            data-location="<?php echo $row['address']; ?>"
                            data-bs-toggle="modal" data-bs-target="#editModal">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this customer?');">
                            <input type="hidden" name="customer_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="delete_customer" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center py-5">No records found for this branch.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header"><h5>Register Client</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="text" name="name" class="form-control mb-3" placeholder="Full Name" required>
                <input type="text" name="phone" class="form-control mb-3" placeholder="Phone Number" required>
                <input type="email" name="email" class="form-control mb-3" placeholder="Email Address">
                <input type="text" name="location" class="form-control mb-3" placeholder="Location/Address">
            </div>
            <div class="modal-footer"><button type="submit" name="add_customer" class="btn btn-success w-100">Save Customer</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header"><h5>Edit Client Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="customer_id" id="edit_id">
                <div class="mb-3"><label>Name</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                <div class="mb-3"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control" required></div>
                <div class="mb-3"><label>Email</label><input type="email" name="email" id="edit_email" class="form-control"></div>
                <div class="mb-3"><label>Location</label><input type="text" name="location" id="edit_location" class="form-control"></div>
            </div>
            <div class="modal-footer"><button type="submit" name="edit_customer" class="btn btn-primary w-100">Update Customer</button></div>
        </form>
    </div></div>
</div>

<script>
    $(document).ready(function(){
        $('.edit-btn').click(function(){
            $('#edit_id').val($(this).data('id'));
            $('#edit_name').val($(this).data('name'));
            $('#edit_phone').val($(this).data('phone'));
            $('#edit_email').val($(this).data('email'));
            $('#edit_location').val($(this).data('location'));
        });
    });
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>