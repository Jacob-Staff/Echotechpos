<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Handle AJAX POST requests (Add / Delete / Clear) directly in this file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];

    // 1. ADD EXPENSE
    if ($action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $amount   = (float)($_POST['amount'] ?? 0);
        $category = trim($_POST['category'] ?? 'General');
        $ex_date  = trim($_POST['date'] ?? date('Y-m-d'));

        if (!empty($name) && $amount > 0 && !empty($ex_date)) {
            $stmt = $conn->prepare("INSERT INTO expenses (pharmacy_id, branch_id, name, amount, expense_date, category, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("iisdssi", $pharmacy_id, $branch_id, $name, $amount, $ex_date, $category, $user_id);

            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Expense saved successfully.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']);
        }
        exit;
    }

    // 2. DELETE EXPENSE
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
            $stmt->bind_param("iii", $id, $pharmacy_id, $branch_id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => $stmt->error]);
            }
            $stmt->close();
        }
        exit;
    }

    // 3. CLEAR EXPENSES (Month / Year)
    if ($action === 'clear') {
        $type = $_POST['type'] ?? '';
        if ($type === 'month') {
            $current_month = date('Y-m');
            $stmt = $conn->prepare("DELETE FROM expenses WHERE pharmacy_id = ? AND branch_id = ? AND DATE_FORMAT(expense_date, '%Y-%m') = ?");
            $stmt->bind_param("iis", $pharmacy_id, $branch_id, $current_month);
        } else if ($type === 'year') {
            $current_year = date('Y');
            $stmt = $conn->prepare("DELETE FROM expenses WHERE pharmacy_id = ? AND branch_id = ? AND DATE_FORMAT(expense_date, '%Y') = ?");
            $stmt->bind_param("iis", $pharmacy_id, $branch_id, $current_year);
        }

        if (isset($stmt) && $stmt->execute()) {
            echo json_encode(['status' => 'success']);
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to clear records.']);
        }
        exit;
    }
}

$categories = ['General', 'Utilities', 'Staff Welfare', 'Logistics', 'Stock/Supplies', 'Other'];

// Include header layout elements
require_once "../includes/head.php";
?>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper" style="background-color: #f4f6f9; min-height: calc(100vh - 70px); padding: 1.25rem;">
        <div class="container-fluid p-0">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Expense Management</h3>
                    <span class="text-secondary small">Track, record, and manage operational branch expenses</span>
                </div>
            </div>

            <div class="row g-4">
                <!-- Record Form -->
                <div class="col-12 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white py-3 border-bottom">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Record Expense</h5>
                        </div>
                        <div class="card-body p-3">
                            <form id="expenseForm">
                                <input type="hidden" name="action" value="add">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Description *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Lunch, Transport, Utilities" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Amount (K) *</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Category *</label>
                                    <select name="category" class="form-select">
                                        <?php foreach($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted mb-1">Expense Date *</label>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <button type="submit" id="submitBtn" class="btn btn-primary fw-bold w-100 py-2 fs-6">
                                    <i class="fas fa-save me-1"></i> SAVE EXPENSE
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Expense Log Table -->
                <div class="col-12 col-lg-8">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2 text-primary"></i>Expense Log</h5>
                                <span class="text-muted small">Total: <b class="text-primary fs-6">K<span id="total_display">0.00</span></b></span>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-danger dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-trash-alt me-1"></i> CLEAR RECORDS
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item clear-btn text-warning" href="#" data-type="month"><i class="fas fa-calendar-alt me-2"></i> This Month</a></li>
                                    <li><a class="dropdown-item clear-btn text-danger" href="#" data-type="year"><i class="fas fa-calendar-times me-2"></i> This Year</a></li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr class="small text-uppercase">
                                            <th>Description</th>
                                            <th>Category</th>
                                            <th>Date</th>
                                            <th class="text-end">Amount</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="expenses_list">
                                        <tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Loading expenses...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {

    // Correct Total Calculation
    function calculateTotal() {
        let total = 0;
        $('.amt').each(function() {
            let val = parseFloat($(this).attr('data-amt'));
            if (!isNaN(val)) total += val;
        });
        $('#total_display').text(total.toFixed(2));
    }

    // Load table rows via AJAX & recalculate sum AFTER content renders
    function loadExpenses() {
        $.get('actions/fetch_expenses.php', function(data) {
            $('#expenses_list').html(data);
            calculateTotal();
        });
    }

    loadExpenses();

    // Submit Form (Add Expense)
    $('#expenseForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#submitBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> SAVING...');

        $.post('expenses.php', $(this).serialize(), function(res) {
            if (res.status === 'success') {
                $('#expenseForm')[0].reset();
                loadExpenses();
            } else {
                alert('Error: ' + res.message);
            }
        }, 'json').always(function() {
            btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> SAVE EXPENSE');
        });
    });

    // Delete Single Record
    $(document).on('click', '.delete-expense', function() {
        const id = $(this).data('id');
        if (confirm('Delete this record?')) {
            $.post('expenses.php', { action: 'delete', id: id }, function(res) {
                if (res.status === 'success') {
                    loadExpenses();
                } else {
                    alert('Error deleting record.');
                }
            }, 'json');
        }
    });

    // Clear Monthly / Yearly Records
    $(document).on('click', '.clear-btn', function(e) {
        e.preventDefault();
        const type = $(this).data('type');
        if (confirm('Are you sure you want to clear ' + type + ' records?')) {
            $.post('expenses.php', { action: 'clear', type: type }, function(res) {
                if (res.status === 'success') {
                    loadExpenses();
                } else {
                    alert('Error clearing records.');
                }
            }, 'json');
        }
    });
});
</script>
</body>
</html>
