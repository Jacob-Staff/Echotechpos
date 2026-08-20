<?php 
session_start();
ob_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['branch_id']) || !isset($_SESSION['pharmacy_id'])) {
    die("<div class='alert alert-danger m-5'>Access Denied. Please login.</div>");
}

if (!isset($_GET['id'])) {
    die("<div class='alert alert-warning m-5'>Invalid Lay-by ID provided.</div>");
}

$id = intval($_GET['id']);
$branch_id = (int)$_SESSION['branch_id'];
$pharmacy_id = (int)$_SESSION['pharmacy_id'];

/* FETCH LAYBY RECORD */
$stmt = $conn->prepare("SELECT * FROM laybys WHERE id = ? AND branch_id = ? AND pharmacy_id = ?");
$stmt->bind_param("iii", $id, $branch_id, $pharmacy_id);
$stmt->execute();
$layby = $stmt->get_result()->fetch_assoc();

if (!$layby) {
    die("<div class='alert alert-danger m-5'>Agreement not found or permission denied.</div>");
}

/* FETCH ITEMS */
$item_stmt = $conn->prepare("SELECT * FROM layby_items WHERE layby_id = ?");
$item_stmt->bind_param("i", $id);
$item_stmt->execute();
$items = $item_stmt->get_result();

/* FETCH PAYMENTS */
$pay_stmt = $conn->prepare("SELECT * FROM layby_payments WHERE layby_id = ? ORDER BY payment_date DESC");
$pay_stmt->bind_param("i", $id);
$pay_stmt->execute();
$payments = $pay_stmt->get_result();

/* PROGRESS CALCULATIONS */
$total_val = floatval($layby['total_amount']);
$paid_val = $total_val - floatval($layby['balance_due']);
$percentage = ($total_val > 0) ? min(100, round(($paid_val / $total_val) * 100)) : 0;

require_once "../includes/head.php";
?>

<style>
.view-wrapper {
    background-color: #f8fafc !important;
    min-height: calc(100vh - 70px);
    padding: 1rem;
}
@media (min-width: 768px) {
    .view-wrapper { padding: 1.5rem; }
}

.light-card {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.03);
}

.progress-bar-custom {
    background-color: #10b981;
}
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper view-wrapper">
        <div class="container-fluid p-0">
            
            <!-- Action Bar Header -->
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-0"><i class="fas fa-file-invoice text-primary me-2"></i>Lay-by Details</h3>
                    <small class="text-muted">Agreement #<?= $id ?></small>
                </div>
                <div class="d-flex gap-2">
                    <a href="lay_by_sell.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
                    <button onclick="window.print()" class="btn btn-dark btn-sm"><i class="fas fa-print me-1"></i> Print Statement</button>
                </div>
            </div>

            <div class="row g-3 g-lg-4">
                <!-- Customer & Summary -->
                <div class="col-12 col-lg-4">
                    <div class="light-card p-3 p-md-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-dark mb-0">Customer Info</h5>
                            <span class="badge <?= $layby['status'] == 'Completed' ? 'bg-success' : 'bg-warning text-dark' ?> rounded-pill px-3 py-1">
                                <?= strtoupper($layby['status']) ?>
                            </span>
                        </div>

                        <h4 class="text-primary fw-bold mb-1"><?= htmlspecialchars($layby['customer_name']) ?></h4>
                        <p class="text-muted small mb-3"><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($layby['customer_phone']) ?></p>

                        <hr class="my-3">

                        <h6 class="fw-bold text-dark mb-2">Payment Progress</h6>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Paid: K<?= number_format($paid_val, 2) ?></span>
                            <span class="fw-bold text-dark"><?= $percentage ?>%</span>
                        </div>
                        <div class="progress mb-3" style="height: 8px;">
                            <div class="progress-bar progress-bar-custom" style="width: <?= $percentage ?>%"></div>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Total Amount:</span>
                            <span class="fw-bold">K<?= number_format($total_val, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-secondary">Balance Due:</span>
                            <span class="fw-bold text-danger fs-5">K<?= number_format($layby['balance_due'], 2) ?></span>
                        </div>

                        <?php if($layby['balance_due'] > 0): ?>
                            <button class="btn btn-success w-100 py-2 shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                <i class="fas fa-plus-circle me-1"></i> Record Payment
                            </button>
                        <?php else: ?>
                            <div class="alert alert-success text-center py-2 mb-0 fw-bold"><i class="fas fa-check-circle me-1"></i> Fully Settled</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Products & History -->
                <div class="col-12 col-lg-8">
                    <!-- Reserved Items -->
                    <div class="light-card p-3 p-md-4 mb-4">
                        <h5 class="fw-bold text-dark mb-3"><i class="fas fa-boxes me-2 text-primary"></i>Reserved Items</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr class="bg-light">
                                        <th>Item Name</th>
                                        <th class="text-center">Price</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($i = $items->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($i['product_name']) ?></td>
                                        <td class="text-center">K<?= number_format($i['price'], 2) ?></td>
                                        <td class="text-center"><?= $i['qty'] ?></td>
                                        <td class="text-end fw-bold">K<?= number_format($i['total'], 2) ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div class="light-card p-3 p-md-4">
                        <h5 class="fw-bold text-dark mb-3"><i class="fas fa-history me-2 text-primary"></i>Payment History</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr class="bg-light">
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($payments->num_rows > 0): ?>
                                        <?php while($p = $payments->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['method']) ?></span></td>
                                            <td class="text-end text-success fw-bold">K<?= number_format($p['payment_amount'], 2) ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-3">No payment records found.</td></tr>
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

<!-- Modal Payment -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="payForm">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-cash-register me-2 text-success"></i>Post Installment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="layby_id" value="<?= $id ?>">
                    
                    <div class="text-center p-3 mb-3 bg-light rounded">
                        <small class="text-muted text-uppercase d-block mb-1">Remaining Balance</small>
                        <h3 class="text-danger fw-bold mb-0">K<?= number_format($layby['balance_due'], 2) ?></h3>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Amount to Pay (K)</label>
                        <input type="number" name="amount" id="pay_amount" class="form-control" 
                               step="0.01" max="<?= $layby['balance_due'] ?>" placeholder="0.00" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small">Payment Method</label>
                        <select name="method" class="form-select">
                            <option value="Cash">Cash</option>
                            <option value="Airtel Money">Airtel Money</option>
                            <option value="MTN Money">MTN Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="POS Card">Point of Sale (Card)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="submitBtn" class="btn btn-success fw-bold px-4">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$("#payForm").on('submit', function(e){
    e.preventDefault();
    
    const btn = $("#submitBtn");
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Processing...');

    $.ajax({
        url: "actions/add_payment.php",
        type: "POST",
        data: $(this).serialize(),
        dataType: 'json',
        success: function(res){
            if(res.status === "success"){
                window.location.reload();
            } else {
                alert("Error: " + (res.message || 'Payment failed'));
                btn.prop('disabled', false).text('Confirm Payment');
            }
        },
        error: function(){
            alert("Network/Server error. Please try again.");
            btn.prop('disabled', false).text('Confirm Payment');
        }
    });
});
</script>
</body>
</html>
