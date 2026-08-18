<div class="card shadow">
    <div class="card-header bg-dark text-white">
        <h4 class="mb-0">New Layby Agreement</h4>
    </div>
    <div class="card-body">
        <form id="laybyForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label>Phone Number</label>
                    <input type="text" name="customer_phone" class="form-control" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>Total Amount (K)</label>
                    <input type="number" id="total" name="total_amount" class="form-control" step="0.01" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>Initial Deposit (K)</label>
                    <input type="number" id="deposit" name="deposit" class="form-control" step="0.01" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>Due Date</label>
                    <input type="date" name="due_date" class="form-control" required>
                </div>
            </div>
            <div class="alert alert-info">
                Remaining Balance: <b>K <span id="balance">0.00</span></b>
            </div>
            <button type="submit" class="btn btn-success px-5">Confirm Layby</button>
        </form>
    </div>
</div>

<script>
document.getElementById('deposit').addEventListener('input', function() {
    let total = document.getElementById('total').value || 0;
    let deposit = this.value || 0;
    document.getElementById('balance').innerText = (total - deposit).toFixed(2);
});

// AJAX Submission
$("#laybyForm").submit(function(e) {
    e.preventDefault();
    $.post("actions/save_layby.php", $(this).serialize(), function(resp) {
        if(resp.trim() == "success") {
            alert("Layby successfully recorded!");
            location.href = "view_laybys.php";
        } else {
            alert("Error: " + resp);
        }
    });
});
</script>