<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

if (!isset($_SESSION['pharmacy_id']) || !isset($_SESSION['branch_id'])) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];

$categories = ['General', 'Utilities', 'Staff Welfare', 'Logistics', 'Stock/Supplies', 'Other'];

require_once "../includes/head.php";
?>

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
.expenses-page-wrapper {
    background-color: #f4f6f9 !important;
    min-height: calc(100vh - 70px);
    padding: 1.25rem;
    color: #212529;
}

.card-custom {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    margin-bottom: 1.5rem;
}

.card-custom-header {
    background-color: #f8fafc;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
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
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper expenses-page-wrapper">
        <div class="container-fluid p-0">

            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4 gap-2">
                <div>
                    <h3 class="fw-bold text-dark mb-0">Expense Management</h3>
                    <span class="text-secondary small">Track, record, and manage operational branch expenses</span>
                </div>
            </div>

            <div class="row g-4">
                <!-- Record Expense Form -->
                <div class="col-12 col-lg-4">
                    <div class="card card-custom">
                        <div class="card-custom-header">
                            <h5 class="fw-bold text-dark mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Record Expense</h5>
                        </div>
                        <div class="card-body p-3">
                            <form id="expenseForm">
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
                    <div class="card card-custom">
                        <div class="card-custom-header d-flex justify-content-between align-items-center">
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
                                <table class="table table-custom align-middle">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th>Category</th>
                                            <th>Date</th>
                                            <th class="text-end">Amount</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="expenses_list">
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                <i class="fas fa-spinner fa-spin me-2"></i> Loading expenses...
                                            </td>
                                        </tr>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {

    function calculateTotal() {
        let total = 0;
        $('.amt').each(function() {
            let val = parseFloat($(this).data('amt'));
            if (!isNaN(val)) total += val;
        });
        $('#total_display').text(total.toFixed(2));
    }

    function loadExpenses() {
        $.get('actions/fetch_expenses.php', function(data) {
            $('#expenses_list').html(data);
            calculateTotal(); // Calculated AFTER HTML is rendered
        }).fail(function() {
            $('#expenses_list').html('<tr><td colspan="5" class="text-center text-danger py-4">Failed to load expense logs.</td></tr>');
        });
    }

    loadExpenses();

    $('#expenseForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#submitBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> SAVING...');

        $.post('actions/add_expense.php', $(this).serialize(), function(res) {
            if(res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: res.message || 'Expense added successfully.',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000
                });
                $('#expenseForm')[0].reset();
                loadExpenses();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: res.message || 'Failed to save expense.'
                });
            }
        }, 'json').fail(function(xhr) {
            Swal.fire({
                icon: 'error',
                title: 'Server Error',
                text: 'Response Error: ' + xhr.statusText
            });
        }).always(function() {
            btn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> SAVE EXPENSE');
        });
    });

    $(document).on('click', '.delete-expense', function() {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Delete Record?',
            text: 'Are you sure you want to delete this expense?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('actions/delete_expense.php', { id: id }, function(res) {
                    if (res.status === 'success') {
                        loadExpenses();
                    } else {
                        Swal.fire('Error', res.message || 'Failed to delete record.', 'error');
                    }
                }, 'json');
            }
        });
    });

    $(document).on('click', '.clear-btn', function(e) {
        e.preventDefault();
        const type = $(this).data('type');
        
        Swal.fire({
            title: 'Clear ' + (type === 'month' ? 'This Month\'s' : 'This Year\'s') + ' Records?',
            text: 'This action will permanently remove all matching expense records.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, clear all!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('actions/clear_expenses.php', { type: type }, function(res) {
                    if(res.status === 'success') {
                        loadExpenses();
                        Swal.fire('Cleared!', 'Records cleared successfully.', 'success');
                    } else {
                        Swal.fire('Error', res.message || 'Failed to clear records.', 'error');
                    }
                }, 'json');
            }
        });
    });
});
</script>
</body>
</html>
