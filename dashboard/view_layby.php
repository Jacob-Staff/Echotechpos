<?php 
session_start();
ob_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

/* SECURITY */
if (!isset($_SESSION['branch_id'])) {
    die("<div class='alert alert-danger m-5'>Access Denied. Please login.</div>");
}

if (!isset($_GET['id'])) {
    die("<div class='alert alert-warning m-5'>Invalid Lay-by ID provided.</div>");
}

$id = intval($_GET['id']);
$branch_id = $_SESSION['branch_id'];

/* FETCH LAYBY WITH BRANCH PROTECTION */
$stmt = $conn->prepare("SELECT * FROM laybys WHERE id = ? AND branch_id = ?");
$stmt->bind_param("ii", $id, $branch_id);
$stmt->execute();
$layby = $stmt->get_result()->fetch_assoc();

if (!$layby) {
    die("<div class='alert alert-danger m-5'>Agreement not found or access denied.</div>");
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

/* CALCULATE PERCENTAGE FOR PROGRESS BAR */
$total_val = floatval($layby['total_amount']);
$paid_val = $total_val - floatval($layby['balance_due']);
$percentage = ($total_val > 0) ? ($paid_val / $total_val) * 100 : 0;
?>

<style>
    :root { --neon-green: #00ffae; --panel-bg: #1e1e1e; --dark-bg: #121212; --cyan-info: #00d2ff; }
    .page-wrapper { background-color: floralwhite !important; margin-left: 250px !important; margin-top: 64px !important; min-height: 100vh; padding: 20px !important; }
    .card { background-color: var(--panel-bg); border: 1px solid #333; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
    .text-cyan { color: var(--cyan-info); }
    .text-green { color: var(--neon-green); }
    .table { color: #e0e0e0; }
    .table thead { background: #252525; color: var(--cyan-info); border-bottom: 2px solid #333; }
    .progress { background-color: #333; height: 10px; border-radius: 5px; }
    .progress-bar { background-color: var(--neon-green); box-shadow: 0 0 10px var(--neon-green); }
    .status-badge { font-size: 0.85rem; padding: 6px 12px; border-radius: 20px; }
    
    @media print { 
        .no-print, .sidebar, .topbar { display: none !important; } 
        .page-wrapper { margin: 0 !important; padding: 0 !important; background: white !important; }
        .card { border: none !important; box-shadow: none !important; background: white !important; color: black !important; }
        .text-cyan, .text-green, .table, .table thead { color: black !important; }
    }
</style>

<div class="row g-4">
    <div class="col-12 no-print">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="text-dark fw-bold"><i class="fas fa-file-signature me-2"></i>Lay-by Management</h3>
            <div>
                <a href="lay_by_sell.php" class="btn btn-outline-secondary btn-sm me-2"><i class="fas fa-list"></i> View All</a>
                <button onclick="window.print()" class="btn btn-dark btn-sm"><i class="fas fa-print"></i> Print Statement</button>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <h5 class="text-cyan mb-0">Customer Details</h5>
                <span class="badge status-badge <?= $layby['status'] == 'Completed' ? 'bg-success' : 'bg-warning text-dark' ?>">
                    <?= strtoupper($layby['status']) ?>
                </span>
            </div>
            
            <p class="mb-1 text-white fw-bold" style="font-size: 1.1rem;"><?= htmlspecialchars($layby['customer_name']) ?></p>
            <p class="text-muted mb-3"><i class="fas fa-phone-alt me-2"></i><?= htmlspecialchars($layby['customer_phone']) ?></p>
            
            <hr class="border-secondary">
            
            <h5 class="text-cyan mb-3">Payment Progress</h5>
            <div class="d-flex justify-content-between text-white mb-1 small">
                <span>Total Paid: K<?= number_format($paid_val, 2) ?></span>
                <span><?= round($percentage) ?>%</span>
            </div>
            <div class="progress mb-4">
                <div class="progress-bar" role="progressbar" style="width: <?= $percentage ?>%"></div>
            </div>

            <div class="d-flex justify-content-between text-white mb-2">
                <span>Total Agreement:</span>
                <span class="fw-bold">K<?= number_format($total_val, 2) ?></span>
            </div>
            <div class="d-flex justify-content-between text-danger mb-3">
                <span class="fw-bold">Balance Due:</span>
                <span class="fw-bold h5">K<?= number_format($layby['balance_due'], 2) ?></span>
            </div>

            <?php if($layby['balance_due'] > 0): ?>
                <button class="btn btn-success w-100 py-2 no-print shadow-sm" data-bs-toggle="modal" data-bs-target="#paymentModal">
                    <i class="fas fa-plus-circle me-2"></i>Record New Installment
                </button>
            <?php else: ?>
                <div class="alert alert-success text-center py-2"><i class="fas fa-check-circle me-2"></i>Fully Paid</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card p-3 mb-4">
            <h5 class="text-cyan mb-3"><i class="fas fa-shopping-basket me-2"></i>Reserved Items</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th class="text-center">Unit Price</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($i = $items->fetch_assoc()): ?>
                        <tr>
                            <td class="text-black"><?= htmlspecialchars($i['product_name']) ?></td>
                            <td class="text-center">K<?= number_format($i['price'], 2) ?></td>
                            <td class="text-center"><?= $i['qty'] ?></td>
                            <td class="text-end text-black">K<?= number_format($i['total'], 2) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card p-3">
            <h5 class="text-green mb-3"><i class="fas fa-history me-2"></i>Installment History</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($payments->num_rows > 0): ?>
                            <?php while($p = $payments->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('d M Y, h:i A', strtotime($p['payment_date'])) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($p['method']) ?></span></td>
                                <td class="text-end text-green fw-bold">K<?= number_format($p['payment_amount'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No installments recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary shadow-lg">
            <form id="payForm">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-green"><i class="fas fa-cash-register me-2"></i>Post Installment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="layby_id" value="<?= $id ?>">
                    
                    <div class="mb-4 text-center">
                        <small class="text-muted d-block mb-1 uppercase">Remaining Balance</small>
                        <h2 class="text-danger">K<?= number_format($layby['balance_due'], 2) ?></h2>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Amount to Pay</label>
                        <div class="input-group">
                            <span class="input-group-text bg-secondary border-secondary text-white">K</span>
                            <input type="number" name="amount" id="pay_amount" class="form-control bg-dark text-white border-secondary" 
                                   step="0.01" max="<?= $layby['balance_due'] ?>" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="method" class="form-select bg-dark text-white border-secondary">
                            <option value="Cash">Cash</option>
                            <option value="Airtel Money">Airtel Money</option>
                            <option value="MTN Money">MTN Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="POS Card">Point of Sale (Card)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="submitBtn" class="btn btn-success px-4">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$("#payForm").on('submit', function(e){
    e.preventDefault();
    
    const btn = $("#submitBtn");
    const amount = parseFloat($("#pay_amount").val());
    const max = parseFloat("<?= $layby['balance_due'] ?>");

    if(amount > max) {
        alert("Amount cannot exceed the balance due.");
        return;
    }

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Processing...');

    $.ajax({
        url: "actions/add_payment.php",
        type: "POST",
        data: $(this).serialize(),
        success: function(res){
            if(res.trim() === "success"){
                window.location.reload();
            } else {
                alert("Server Response: " + res);
                btn.prop('disabled', false).text('Confirm Payment');
            }
        },
        error: function(){
            alert("Network error. Please try again.");
            btn.prop('disabled', false).text('Confirm Payment');
        }
    });
});
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php";
?>