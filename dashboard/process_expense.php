<?php
session_start();
require_once '../includes/conn.php';
require_once '../includes/auth.php'; 

// RULE 1: Capture Multi-Tenant IDs
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

// Security Redirect if session is missing
if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses - Echo Prime Ltd</title>
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/a+.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="dist/css/style.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #00ffae; 
            --dark-bg: #121212;
            --card-bg: #1e1e1e;
            --input-bg: #2b2b2b;
            --text-light: #f1f1f1;
            --text-dark: #000;
        }
        body { background-color: var(--dark-bg); color: var(--text-light); font-family: 'Poppins', sans-serif; }
        .card { 
            background-color: var(--card-bg); 
            border-radius: 12px; 
            border: 1px solid #333; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.4); 
        }
        .form-control { 
            background-color: var(--input-bg); 
            color: var(--text-light); 
            border: 1px solid #444; 
        }
        .form-control:focus { background-color: #333; color: #fff; border-color: var(--primary-color); }
        .btn-success { background-color: var(--primary-color); border: none; color: var(--text-dark); font-weight: bold; }
        .page-wrapper { padding: 2rem; margin-left: 250px; }
        #expenses_table thead { background-color: #333; color: #ffffff; }
        #total_expenses { font-size: 1.3rem; font-weight: bold; color: var(--primary-color); margin-bottom: 15px; }
        .category-badge { background: #333; color: var(--primary-color); padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; }
    </style>
</head>
<body>
<div id="main-wrapper">

    <?php require "../includes/header.php"; ?>
    <?php require "../includes/aside.php"; ?>

    <div class="page-wrapper">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-4 mb-4">
                    <div class="card sticky-top" style="top: 80px;">
                        <div class="card-body">
                            <h4 class="card-title"><i class="fas fa-money-check-alt"></i> Record Expense</h4>
                            <hr style="border-color: #444;">
                            <form id="expense_form">
                                <div class="mb-3">
                                    <label class="form-label">Description</label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Amount (K)</label>
                                    <input type="number" step="0.01" class="form-control" name="amount" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Date</label>
                                    <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-control" name="category">
                                        <option value="General">General</option>
                                        <option value="Utilities">Utilities</option>
                                        <option value="Staff Welfare">Staff Welfare</option>
                                        <option value="Logistics">Logistics</option>
                                        <option value="Stock">Stock/Supplies</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-success w-100" id="submit_btn">
                                    <i class="fas fa-plus"></i> SAVE RECORD
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title"><i class="fas fa-list-alt"></i> History</h4>
                            <hr style="border-color: #444;">
                            <div id="total_expenses">
                                Total: K<span id="total_amount">0.00</span>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-dark table-hover" id="expenses_table">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th class="text-end">Amount</th>
                                            <th>Category</th>
                                            <th>Date</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="expenses_list">
                                        <tr><td colspan="5" class="text-center">Loading...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="msgModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content bg-dark border-secondary"><div class="modal-body text-center p-4"><h5 id="msgTitle"></h5><p id="msgBody" class="text-white"></p><button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button></div></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    
    function loadExpenses() {
        $.get('fetch_expenses.php', function(data) {
            $('#expenses_list').html(data);
            calculateTotal();
        });
    }

    function calculateTotal() {
        let total = 0;
        // Looking for the 'amt' class we defined in fetch_expenses.php
        $('.amt').each(function() {
            total += parseFloat($(this).data('amt')) || 0;
        });
        $('#total_amount').text(total.toLocaleString(undefined, {minimumFractionDigits: 2}));
    }

    loadExpenses();

    $('#expense_form').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#submit_btn');
        btn.prop('disabled', true).text('SAVING...');

        $.ajax({
            url: 'add_expense.php',
            method: 'POST',
            data: $(this).serialize(), // FIXED: Sends standard POST data
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    $('#expense_form')[0].reset();
                    loadExpenses();
                } else {
                    alert('Error: ' + res.message);
                }
            },
            error: function(xhr) {
                console.error(xhr.responseText);
                alert('Server Error. Check console (F12).');
            },
            complete: function() {
                btn.prop('disabled', false).html('<i class="fas fa-plus"></i> SAVE RECORD');
            }
        });
    });

    $(document).on('click', '.delete-expense', function() {
        const id = $(this).data('id');
        if(confirm('Are you sure?')) {
            $.post('delete_expense.php', { id: id }, function() {
                loadExpenses();
            });
        }
    });
});
</script>
</body>
</html>