<?php  
session_start();
ob_start();
require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null; 
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

$categories = ['General', 'Utilities', 'Staff Welfare', 'Logistics', 'Stock/Supplies', 'Other'];
?>

<div class="container-fluid pt-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0" style="background: #1a1a1a; color: #fff; border-radius: 15px;">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-3" style="color: #00ffae;">Record Expense</h4>
                    <form id="expenseForm">
                        <div class="mb-3">
                            <label class="small text-uppercase" style="color: #00ffae;">Description</label>
                            <input type="text" name="name" class="form-control bg-dark border-secondary text-white" required>
                        </div>
                        <div class="mb-3">
                            <label class="small text-uppercase" style="color: #00ffae;">Amount (K)</label>
                            <input type="number" step="0.01" name="amount" class="form-control bg-dark border-secondary text-white" required>
                        </div>
                        <div class="mb-3">
                            <label class="small text-uppercase" style="color: #00ffae;">Category</label>
                            <select name="category" class="form-select bg-dark border-secondary text-white">
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?= $cat ?>"><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="small text-uppercase" style="color: #00ffae;">Expense Date</label>
                            <input type="date" name="date" class="form-control bg-dark border-secondary text-white" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <button type="submit" id="submitBtn" class="btn w-100 fw-bold" style="background: #00ffae; color: #000;">
                            SAVE EXPENSE
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0" style="border-radius: 15px;">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold">Expense Log</h5>
                        <h6 class="mb-0 text-primary small">Total: K<span id="total_display">0.00</span></h6>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-danger dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                            CLEAR RECORDS
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item clear-btn" href="#" data-type="month">This Month</a></li>
                            <li><a class="dropdown-item clear-btn text-danger" href="#" data-type="year">This Year</a></li>
                        </ul>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="expenses_list"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    function loadExpenses() {
        $.get('actions/fetch_expenses.php', function(data) {
            $('#expenses_list').html(data);
            calculateTotal();
        });
    }

    function calculateTotal() {
        let total = 0;
        $('.amt').each(function() {
            let val = parseFloat($(this).data('amt'));
            if (!isNaN(val)) total += val;
        });
        $('#total_display').text(total.toFixed(2));
    }

    loadExpenses();

    $('#expenseForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#submitBtn');
        btn.prop('disabled', true).text('SAVING...');

        $.post('actions/add_expense.php', $(this).serialize(), function(res) {
            if(res.status === 'success') {
                $('#expenseForm')[0].reset();
                loadExpenses();
            } else {
                alert('Error: ' + res.message);
            }
        }, 'json').fail(function(xhr) {
            console.error(xhr.responseText);
            alert('Server Error. Check console.');
        }).always(function() {
            btn.prop('disabled', false).text('SAVE EXPENSE');
        });
    });

    $(document).on('click', '.delete-expense', function() {
        if(confirm('Delete this record?')) {
            $.post('actions/delete_expense.php', { id: $(this).data('id') }, function() {
                loadExpenses();
            });
        }
    });

    $(document).on('click', '.clear-btn', function(e) {
        e.preventDefault();
        const type = $(this).data('type');
        if (confirm('Clear ' + type + ' records?')) {
            $.post('actions/clear_expenses.php', { type: type }, function(res) {
                if(res.trim() === 'success') loadExpenses();
            });
        }
    });
});
</script>

<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>