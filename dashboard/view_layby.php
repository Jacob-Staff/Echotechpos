<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger text-center mt-3'>Session expired. Please log in again.</div>");
}

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$user_id     = (int)$_SESSION['user_id'];

// Get Lay-by ID from URL query string
$layby_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($layby_id <= 0) {
    die("<div class='alert alert-warning text-center mt-5'>Invalid Lay-by ID provided. <a href='lay_by_sell.php' class='alert-link'>Go back</a></div>");
}

// Fetch Lay-by Details
$layby_stmt = mysqli_prepare($conn, "SELECT * FROM laybys WHERE id = ? AND pharmacy_id = ? AND branch_id = ? LIMIT 1");
mysqli_stmt_bind_param($layby_stmt, "iii", $layby_id, $pharmacy_id, $branch_id);
mysqli_stmt_execute($layby_stmt);
$layby_res = mysqli_stmt_get_result($layby_stmt);
$layby = mysqli_fetch_assoc($layby_res);

if (!$layby) {
    die("<div class='alert alert-danger text-center mt-5'>Lay-by record not found. <a href='lay_by_sell.php' class='alert-link'>Go back</a></div>");
}

// Fetch Branding Info
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $pharmacy_id, $branch_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

// Fetch Purchased Items
$items_stmt = mysqli_prepare($conn, "SELECT * FROM layby_items WHERE layby_id = ?");
mysqli_stmt_bind_param($items_stmt, "i", $layby_id);
mysqli_stmt_execute($items_stmt);
$items_res = mysqli_stmt_get_result($items_stmt);

// Fetch Payment History
$payments_stmt = mysqli_prepare($conn, "SELECT p.*, u.username FROM layby_payments p LEFT JOIN users u ON p.user_id = u.id WHERE p.layby_id = ? ORDER BY p.id DESC");
mysqli_stmt_bind_param($payments_stmt, "i", $layby_id);
mysqli_stmt_execute($payments_stmt);
$payments_res = mysqli_stmt_get_result($payments_stmt);

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Correct Page Head Include
require_once "../includes/head.php";
?>

<style>
.layby-view-wrapper {
    background-color: #f4f6f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1rem;
    color: #212529;
}

@media (min-width: 768px) {
    .layby-view-wrapper { padding: 1.5rem; }
}

.card-custom {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
    margin-bottom: 1.5rem;
}

.card-custom-header {
    background-color: #f8fafc;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}

.table-custom {
    color: #334155;
    margin-bottom: 0;
}

.table-custom thead th {
    background-color: #f1f5f9;
    color: #0f172a;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px;
}

.table-custom tbody td {
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    padding: 12px;
    font-size: 0.9rem;
}
</style>

<div id="main-wrapper">
    <?php 
    // Correct Header & Aside Includes
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper layby-view-wrapper">
        <div class="container-fluid p-0">

            <!-- Top Action Header -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Lay-by Agreement #<?php echo e($layby['id']); ?></h3>
                    <span class="text-secondary small">
                        <i class="fas fa-store me-1"></i> Branch: <b class="text-dark"><?php echo e($display_bran); ?></b> | <b class="text-dark"><?php echo e($display_pharm); ?></b>
                    </span>
                </div>
                <div>
                    <a href="lay_by_sell.php" class="btn btn-outline-secondary btn-sm px-3 fw-bold">
                        <i class="fas fa-arrow-left me-1"></i> Back to Lay-bys
                    </a>
                </div>
            </div>

            <div class="row g-4">
                <!-- Customer & Summary Details -->
                <div class="col-12 col-lg-4">
                    <div class="card card-custom h-100">
                        <div class="card-custom-header">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-user-tag me-2 text-primary"></i>Customer Details</h5>
                        </div>
                        <div class="card-body p-3">
                            <p class="mb-2"><strong>Customer Name:</strong> <span class="text-dark"><?php echo e($layby['customer_name']); ?></span></p>
                            <p class="mb-2"><strong>Phone Number:</strong> <span class="text-dark"><?php echo e($layby['customer_phone']); ?></span></p>
                            <p class="mb-2"><strong>Created Date:</strong> <span class="text-dark"><?php echo date('d M Y, H:i', strtotime($layby['created_at'])); ?></span></p>
                            <p class="mb-3"><strong>Due Date:</strong> <span class="text-danger fw-bold"><?php echo date('d M Y', strtotime($layby['due_date'])); ?></span></p>
                            <hr>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Amount:</span>
                                <strong class="text-dark">K<?php echo number_format($layby['total_amount'], 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Paid:</span>
                                <strong class="text-success">K<?php echo number_format($layby['deposit'], 2); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Balance Due:</span>
                                <strong class="text-danger fs-5">K<?php echo number_format($layby['balance_due'], 2); ?></strong>
                            </div>
                            <div class="mb-3">
                                <span>Status:</span>
                                <?php if ($layby['balance_due'] <= 0): ?>
                                    <span class="badge bg-success px-3 py-2 ms-2">Completed</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark px-3 py-2 ms-2"><?php echo e($layby['status']); ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Record Payment Button / Form -->
                            <?php if ($layby['balance_due'] > 0): ?>
                                <hr>
                                <h6 class="fw-bold text-dark mb-2"><i class="fas fa-money-bill-wave me-1 text-success"></i>Record Installment</h6>
                                <form id="payment_form">
                                    <input type="hidden" name="layby_id" value="<?php echo $layby['id']; ?>">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold text-muted mb-1">Payment Amount (K)</label>
                                        <input type="number" name="payment_amount" class="form-control" step="0.01" max="<?php echo $layby['balance_due']; ?>" required placeholder="0.00">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-muted mb-1">Payment Method</label>
                                        <select name="method" class="form-select">
                                            <option value="Cash">Cash</option>
                                            <option value="Mobile Money">Mobile Money</option>
                                            <option value="Bank Card">Bank Card</option>
                                        </select>
                                    </div>
                                    <button type="submit" id="pay_btn" class="btn btn-success fw-bold w-100 py-2">
                                        <i class="fas fa-check-circle me-1"></i> Submit Payment
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Purchased Items & Payment Log -->
                <div class="col-12 col-lg-8">
                    <!-- Reserved Items -->
                    <div class="card card-custom">
                        <div class="card-custom-header">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-boxes me-2 text-primary"></i>Reserved Items</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-custom align-middle">
                                    <thead>
                                        <tr>
                                            <th>Product Name</th>
                                            <th class="text-center">Price</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end pe-3">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($items_res) > 0): ?>
                                            <?php while ($item = mysqli_fetch_assoc($items_res)): ?>
                                                <tr>
                                                    <td class="fw-bold text-dark"><?php echo e($item['product_name']); ?></td>
                                                    <td class="text-center">K<?php echo number_format($item['price'], 2); ?></td>
                                                    <td class="text-center"><?php echo $item['qty']; ?></td>
                                                    <td class="text-end pe-3 fw-bold text-success">K<?php echo number_format($item['total'], 2); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center py-3 text-muted">No item records attached.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Log -->
                    <div class="card card-custom">
                        <div class="card-custom-header">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-history me-2 text-primary"></i>Payment History</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-custom align-middle text-center">
                                    <thead>
                                        <tr>
                                            <th class="text-start ps-3">Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Logged By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (mysqli_num_rows($payments_res) > 0): ?>
                                            <?php while ($pay = mysqli_fetch_assoc($payments_res)): ?>
                                                <tr>
                                                    <td class="text-start ps-3"><?php echo date('d M Y, H:i', strtotime($pay['payment_date'])); ?></td>
                                                    <td class="fw-bold text-success">K<?php echo number_format($pay['payment_amount'], 2); ?></td>
                                                    <td><span class="badge bg-light text-dark border"><?php echo e($pay['method']); ?></span></td>
                                                    <td><?php echo e($pay['username'] ?? 'Staff'); ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center py-3 text-muted">No payments recorded yet.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php 
    if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
    ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$('#payment_form').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#pay_btn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Processing...');

    $.post('actions/record_layby_payment.php', $(this).serialize(), function(res) {
        if (res.status === 'success') {
            alert("Payment recorded successfully!");
            location.reload();
        } else {
            alert("Error: " + (res.message || "Could not record payment."));
            btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Submit Payment');
        }
    }, 'json').fail(function() {
        alert("Server error occurred while saving payment.");
        btn.prop('disabled', false).html('<i class="fas fa-check-circle me-1"></i> Submit Payment');
    });
});
</script>
</body>
</html>
