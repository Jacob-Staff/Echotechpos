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

$p_id = (int)$_SESSION['pharmacy_id'];
$b_id = (int)$_SESSION['branch_id'];

$success = "";
$error = "";

function customer_ajax_response(string $status, string $message, array $extra = []): void {
    if (isset($_POST['ajax_customer']) && $_POST['ajax_customer'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'status' => $status,
            'message' => $message
        ], $extra));
        exit;
    }
}

/** 1. DATABASE OPERATIONS **/

// ADD CUSTOMER
if (isset($_POST['add_customer'])) {
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if (!empty($name)) {
        $stmt = mysqli_prepare($conn, "INSERT INTO customers (pharmacy_id, branch_id, name, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iissss", $p_id, $b_id, $name, $phone, $email, $location);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Customer registered successfully!";
            customer_ajax_response("success", $success);
        } else {
            $error = "Error adding customer: " . mysqli_error($conn);
            customer_ajax_response("error", $error);
        }
        mysqli_stmt_close($stmt);
    } else {
        $error = "Customer name is required.";
        customer_ajax_response("error", $error);
    }
}

// EDIT CUSTOMER
if (isset($_POST['edit_customer'])) {
    $id       = (int)($_POST['customer_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($id > 0 && !empty($name)) {
        $stmt = mysqli_prepare($conn, "UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
        mysqli_stmt_bind_param($stmt, "ssssiii", $name, $phone, $email, $location, $id, $p_id, $b_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Customer updated successfully!";
            customer_ajax_response("success", $success);
        } else {
            $error = "Error updating customer: " . mysqli_error($conn);
            customer_ajax_response("error", $error);
        }
        mysqli_stmt_close($stmt);
    } else {
        $error = "Invalid customer details.";
        customer_ajax_response("error", $error);
    }
}

// DELETE CUSTOMER
if (isset($_POST['delete_customer'])) {
    $id = (int)($_POST['customer_id'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM customers WHERE id = ? AND pharmacy_id = ? AND branch_id = ?");
        mysqli_stmt_bind_param($stmt, "iii", $id, $p_id, $b_id);
        if (mysqli_stmt_execute($stmt)) {
            $success = "Customer removed from branch records.";
            customer_ajax_response("success", $success, ["customer_id" => $id]);
        } else {
            $error = "Error deleting customer: " . mysqli_error($conn);
            customer_ajax_response("error", $error);
        }
        mysqli_stmt_close($stmt);
    }
}

/** 2. FETCH BRANDING & CUSTOMERS **/
$info_stmt = mysqli_prepare($conn, "SELECT p.name, b.branch_name FROM pharmacies p JOIN branches b ON b.pharmacy_id = p.id WHERE p.id = ? AND b.id = ? LIMIT 1");
mysqli_stmt_bind_param($info_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($info_stmt);
$info_res = mysqli_stmt_get_result($info_stmt);
$info = mysqli_fetch_assoc($info_res);

$display_pharm = $info['name'] ?? 'PHARMANOVA';
$display_bran  = $info['branch_name'] ?? 'Main Branch';

$cust_stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE pharmacy_id = ? AND branch_id = ? ORDER BY id DESC");
mysqli_stmt_bind_param($cust_stmt, "ii", $p_id, $b_id);
mysqli_stmt_execute($cust_stmt);
$result = mysqli_stmt_get_result($cust_stmt);

$customers = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
}
$total_count = count($customers);

require_once "../includes/head.php";
?>

<style>
.report-wrapper {
    background-color: #ffffff !important;
    min-height: calc(100vh - 70px);
    padding: 1.5rem;
    color: #212529;
}

.header-section {
    background: #ffffff;
    padding: 1.25rem 1.5rem;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    border-left: 5px solid #198754;
    margin-bottom: 1.5rem;
}

.report-table-container {
    background-color: #ffffff;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    overflow: hidden;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table thead th {
    background: #198754;
    padding: 14px;
    text-align: left;
    font-size: 12px;
    color: #ffffff !important;
    text-transform: uppercase;
    border-bottom: 2px solid #146c43;
}

.report-table tbody td {
    padding: 14px;
    color: #212529;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
    vertical-align: middle;
}

.report-table tbody tr:hover {
    background: #f8f9fa;
}

.form-control-search {
    border: 1px solid #ced4da;
    border-radius: 6px;
    padding: 8px 15px;
}

.form-control-search:focus {
    border-color: #198754;
    box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
}

/* =========================================================
   CUSTOMER PAGE - MODERN POS UI
========================================================= */

.custom-toast-container {
    position: fixed;
    top: 82px;
    right: 20px;
    z-index: 3000;
    width: min(390px, calc(100vw - 40px));
    pointer-events: none;
}
.custom-toast {
    pointer-events: auto;
    background: #fff;
    border: 1px solid #dee2e6;
    border-left: 4px solid #198754;
    border-radius: 14px;
    box-shadow: 0 18px 45px rgba(15,23,42,.15);
    padding: 14px 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 10px;
    animation: customerToastIn .22s ease both;
}
.custom-toast.error { border-left-color: #dc3545; }
.custom-toast.info { border-left-color: #0d6efd; }
.custom-toast-icon {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex: 0 0 36px; background: #eaf7f0; color: #198754;
}
.custom-toast.error .custom-toast-icon { background:#fff0f1; color:#dc3545; }
.custom-toast.info .custom-toast-icon { background:#eef5ff; color:#0d6efd; }
.custom-toast-content { flex:1; min-width:0; }
.custom-toast-title { font-weight:700; color:#212529; font-size:.92rem; }
.custom-toast-message { color:#6c757d; font-size:.84rem; line-height:1.45; margin-top:2px; }
.custom-toast-close {
    border:0; background:transparent; color:#adb5bd; cursor:pointer; padding:2px 4px;
}
.custom-toast-close:hover { color:#495057; }

.custom-confirm-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,.48);
    backdrop-filter: blur(2px); z-index: 3050; display:none;
    align-items:center; justify-content:center; padding:20px;
}
.custom-confirm-backdrop.show { display:flex; }
.custom-confirm {
    width:min(440px,100%); background:#fff; border-radius:18px;
    box-shadow:0 25px 70px rgba(15,23,42,.25); overflow:hidden;
    animation:customerConfirmIn .2s ease both;
}
.custom-confirm-header { padding:20px 22px 8px; display:flex; gap:13px; align-items:center; }
.custom-confirm-icon {
    width:44px; height:44px; border-radius:50%; background:#fff0f1; color:#dc3545;
    display:flex; align-items:center; justify-content:center;
}
.custom-confirm-body { padding:4px 22px 20px; color:#6c757d; line-height:1.55; }
.custom-confirm-footer { padding:14px 22px; background:#f8f9fa; display:flex; justify-content:flex-end; gap:8px; }

@keyframes customerToastIn {
    from { opacity:0; transform:translateY(-8px) translateX(8px); }
    to { opacity:1; transform:translateY(0) translateX(0); }
}
@keyframes customerConfirmIn {
    from { opacity:0; transform:scale(.96) translateY(8px); }
    to { opacity:1; transform:scale(1) translateY(0); }
}
@media(max-width:767.98px) {
    .report-wrapper { padding:1rem .75rem; }
    .header-section { padding:1rem; flex-direction:column; align-items:stretch !important; }
    .header-section .btn { width:100%; margin-top:12px; }
    .custom-toast-container { top:70px; right:12px; width:calc(100vw - 24px); }
}

@media print {
    .no-print { display: none !important; }
    .report-wrapper { padding: 0 !important; }
}
</style>

<div id="main-wrapper">

    <div id="customToastContainer" class="custom-toast-container no-print" aria-live="polite"></div>

    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper report-wrapper">
        <div class="container-fluid p-0">
            
            <div class="header-section d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold text-dark mb-0"><?php echo strtoupper(htmlspecialchars($display_pharm)); ?></h3>
                    <span class="text-muted small">Branch: <b><?php echo htmlspecialchars($display_bran); ?></b> | Total Clients: <b class="text-success"><?php echo $total_count; ?></b></span>
                </div>
                <div class="no-print">
                    <button class="btn btn-success btn-sm px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus me-1"></i> Add New Client
                    </button>
                </div>
            </div>

            <?php if ($success): ?> 
                <div class="alert alert-success border-0 shadow-sm mb-3"><?php echo htmlspecialchars($success); ?></div> 
            <?php endif; ?>
            <?php if ($error): ?> 
                <div class="alert alert-danger border-0 shadow-sm mb-3"><?php echo htmlspecialchars($error); ?></div> 
            <?php endif; ?>

            <div class="card mb-3 border shadow-sm no-print">
                <div class="card-body p-2">
                    <input type="text" class="form-control form-control-search" id="search" placeholder="Search client name, phone, email, or address...">
                </div>
            </div>

            <div class="report-table-container">
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="60">#</th>
                                <th>Client Name</th>
                                <th>Contact Number</th>
                                <th>Address / Location</th>
                                <th class="text-center no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="customer-body">
                            <?php if ($total_count > 0): ?>
                                <?php $i = 1; foreach ($customers as $row): ?>
                                    <tr id="customer-row-<?php echo (int)$row['id']; ?>">
                                        <td class="ps-3 text-muted fw-bold"><?php echo $i++; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></div>
                                            <?php if (!empty($row['email'])): ?>
                                                <div class="text-muted small"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($row['email']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['client_id'])): ?>
                                                <span class="badge bg-success text-white mt-1" style="font-size:0.7rem;font-weight:700;">
        <i class="fas fa-check-circle me-1"></i>Subscribed App User
    </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($row['phone']); ?>" class="text-decoration-none text-dark fw-bold">
                                                    <i class="fas fa-phone-alt me-1 text-success"></i><?php echo htmlspecialchars($row['phone']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['address'] ?: 'N/A'); ?></td>
                                        <td class="text-center no-print">
                                            <?php if (!empty($row['email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($row['email']); ?>" class="btn btn-sm btn-outline-info me-1" title="Send Email">
                                                    <i class="fas fa-envelope"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-outline-primary edit-btn me-1" 
                                                data-id="<?php echo $row['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>"
                                                data-phone="<?php echo htmlspecialchars($row['phone'] ?? '', ENT_QUOTES); ?>"
                                                data-email="<?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES); ?>"
                                                data-location="<?php echo htmlspecialchars($row['address'] ?? '', ENT_QUOTES); ?>"
                                                data-bs-toggle="modal" data-bs-target="#editModal" title="Edit Client">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger delete-btn"
                                                data-id="<?php echo (int)$row['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>"
                                                title="Delete Client">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">No client records found for this branch.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title text-white"><i class="fas fa-user-plus me-2"></i>Register New Client</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., John Doe" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g., +260970000000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control" placeholder="e.g., client@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Address / Location</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g., Lusaka, Zambia">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_customer" class="btn btn-success px-4">Save Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="post">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title text-white"><i class="fas fa-user-edit me-2"></i>Edit Client Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="customer_id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Phone Number</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Address / Location</label>
                        <input type="text" name="location" id="edit_location" class="form-control">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_customer" class="btn btn-primary px-4">Update Client</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php 
if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; 
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function(){

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function showToast(type, title, message, duration = 4200) {
        const icons = {success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle'};
        const id = 'customer-toast-' + Date.now() + Math.floor(Math.random()*1000);

        $('#customToastContainer').append(`
            <div class="custom-toast ${type}" id="${id}">
                <div class="custom-toast-icon"><i class="fas ${icons[type] || icons.info}"></i></div>
                <div class="custom-toast-content">
                    <div class="custom-toast-title">${escapeHtml(title)}</div>
                    <div class="custom-toast-message">${escapeHtml(message)}</div>
                </div>
                <button type="button" class="custom-toast-close"><i class="fas fa-times"></i></button>
            </div>
        `);

        const $toast = $('#' + id);
        $toast.find('.custom-toast-close').on('click', function(){
            $toast.fadeOut(160, function(){ $(this).remove(); });
        });
        setTimeout(function(){
            $toast.fadeOut(200, function(){ $(this).remove(); });
        }, duration);
    }

    /* Populate edit modal */
    $(document).on('click', '.edit-btn', function(){
        const $btn = $(this);
        $('#edit_id').val($btn.data('id'));
        $('#edit_name').val($btn.data('name'));
        $('#edit_phone').val($btn.data('phone'));
        $('#edit_email').val($btn.data('email'));
        $('#edit_location').val($btn.data('location'));
    });

    /* Add customer without full-page POST */
    $('#addModal form').on('submit', function(e){
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[name="add_customer"]');
        const original = $btn.html();

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');

        $.ajax({
            url:'customers.php', type:'POST',
            data:$form.serialize()+'&ajax_customer=1', dataType:'json'
        }).done(function(res){
            if(res.status === 'success'){
                bootstrap.Modal.getOrCreateInstance(document.getElementById('addModal')).hide();
                $form[0].reset();
                showToast('success','Customer Added',res.message);
                setTimeout(()=>window.location.reload(),650);
            } else {
                showToast('error','Unable to Add Customer',res.message);
            }
        }).fail(function(){
            showToast('error','Connection Error','The customer could not be saved. Please try again.');
        }).always(function(){
            $btn.prop('disabled',false).html(original);
        });
    });

    /* Edit customer without full-page POST */
    $('#editModal form').on('submit', function(e){
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[name="edit_customer"]');
        const original = $btn.html();

        $btn.prop('disabled',true).html('<i class="fas fa-spinner fa-spin me-1"></i> Updating...');

        $.ajax({
            url:'customers.php', type:'POST',
            data:$form.serialize()+'&ajax_customer=1', dataType:'json'
        }).done(function(res){
            if(res.status === 'success'){
                bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).hide();
                showToast('success','Customer Updated',res.message);
                setTimeout(()=>window.location.reload(),650);
            } else {
                showToast('error','Update Failed',res.message);
            }
        }).fail(function(){
            showToast('error','Connection Error','The customer could not be updated. Please try again.');
        }).always(function(){
            $btn.prop('disabled',false).html(original);
        });
    });

    /* Custom delete confirmation */
    let pendingDeleteId = null;

    $(document).on('click','.delete-btn',function(){
        pendingDeleteId = $(this).data('id');
        $('#deleteCustomerName').text($(this).data('name') || 'this customer');
        $('#deleteConfirm').addClass('show');
    });

    function closeDeleteConfirm(){
        pendingDeleteId = null;
        $('#deleteConfirm').removeClass('show');
    }

    $('#cancelDeleteBtn').on('click',closeDeleteConfirm);
    $('#deleteConfirm').on('click',function(e){
        if(e.target === this) closeDeleteConfirm();
    });
    $(document).on('keydown',function(e){
        if(e.key === 'Escape' && $('#deleteConfirm').hasClass('show')) closeDeleteConfirm();
    });

    $('#confirmDeleteBtn').on('click',function(){
        if(!pendingDeleteId) return;

        const id = pendingDeleteId;
        const $btn = $(this);
        const original = $btn.html();

        $btn.prop('disabled',true).html('<i class="fas fa-spinner fa-spin me-1"></i> Deleting...');

        $.ajax({
            url:'customers.php', type:'POST',
            data:{delete_customer:1,customer_id:id,ajax_customer:1},
            dataType:'json'
        }).done(function(res){
            closeDeleteConfirm();

            if(res.status === 'success'){
                const $row = $('#customer-row-'+id);
                $row.fadeOut(280,function(){
                    $(this).remove();
                    if($('#customer-body tr[id^="customer-row-"]').length === 0){
                        $('#customer-body').html(`
                            <tr><td colspan="5" class="text-center py-5 text-muted">
                                No client records found for this branch.
                            </td></tr>
                        `);
                    }
                });

                const $count = $('.header-section .text-success').first();
                const count = parseInt($count.text(),10);
                if(!isNaN(count)) $count.text(Math.max(0,count-1));

                showToast('success','Customer Deleted',res.message);
            } else {
                showToast('error','Delete Failed',res.message);
            }
        }).fail(function(){
            closeDeleteConfirm();
            showToast('error','Connection Error','The customer could not be deleted. Please try again.');
        }).always(function(){
            $btn.prop('disabled',false).html(original);
        });
    });

    /* Live search - updates immediately */
    $('#search').on('input',function(){
        const value = $(this).val().toLowerCase().trim();
        $('#customer-body tr[id^="customer-row-"]').each(function(){
            $(this).toggle($(this).text().toLowerCase().indexOf(value) !== -1);
        });
    });

});
</script>
</script>
</body>
</html>
