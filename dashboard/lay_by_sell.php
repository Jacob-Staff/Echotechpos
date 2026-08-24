<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);
$user_id     = (int)($_SESSION['user_id'] ?? 0);

if (!$pharmacy_id || !$branch_id || !$user_id) {
    header("Location: ../login.php?error=session_expired");
    exit;
}

$pharmacy_name = "Pharmacy";
$branch_name = "Main Branch";

$stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $pharmacy_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $pharmacy_name = $r['name'];
    $stmt->close();
}

$stmt = $conn->prepare("SELECT branch_name FROM branches WHERE id = ? AND pharmacy_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("ii", $branch_id, $pharmacy_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($r = $res->fetch_assoc()) $branch_name = $r['branch_name'];
    $stmt->close();
}

$view_id = (int)($_GET['id'] ?? 0);

require_once "../includes/head.php";
?>

<style>
:root{
    --lb-blue:#1677ff;
    --lb-dark:#33475b;
    --lb-green:#198754;
    --lb-orange:#ff9800;
    --lb-red:#dc3545;
    --lb-bg:#f1f4f8;
    --lb-border:#e1e7ef;
}
.layby-page{min-height:calc(100vh - 70px);background:var(--lb-bg);padding:18px}
.layby-shell{max-width:1500px;margin:auto}
.lb-card{background:#fff;border:1px solid var(--lb-border);border-radius:12px;box-shadow:0 3px 12px rgba(25,45,70,.05)}
.lb-title{padding:16px 18px}
.lb-icon{width:48px;height:48px;border-radius:12px;background:#eaf3ff;color:var(--lb-blue);display:flex;align-items:center;justify-content:center;font-size:21px}
.lb-kpi{color:#fff;border:0;min-height:112px;position:relative;overflow:hidden}
.lb-kpi-blue{background:#4299cf}.lb-kpi-dark{background:#3e4f60}.lb-kpi-green{background:#198754}.lb-kpi-orange{background:linear-gradient(135deg,#ff9800,#ef6c00)}
.lb-kpi-label{font-size:11px;font-weight:800;text-transform:uppercase;opacity:.85}
.lb-kpi-value{font-size:25px;font-weight:800;margin-top:4px}
.lb-kpi-icon{width:42px;height:42px;border-radius:11px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center}
.lb-section-title{font-weight:800;color:#26364a}
.lb-form-label{font-size:11px;font-weight:800;color:#657487;text-transform:uppercase;margin-bottom:6px}
.form-control,.form-select{min-height:40px;border-color:#d7e0ea;border-radius:7px}
.product-results{position:absolute;left:0;right:0;top:100%;z-index:2000;background:#fff;border:1px solid #dbe3ed;border-radius:8px;box-shadow:0 8px 25px rgba(0,0,0,.12);max-height:300px;overflow:auto;display:none}
.product-result{padding:10px 12px;border-bottom:1px solid #eef1f5;cursor:pointer}
.product-result:hover{background:#eef6ff}
.cart-table th{font-size:11px;text-transform:uppercase;color:#69788b;background:#f8fafc}
.cart-table td{vertical-align:middle}
.lb-table th{font-size:11px;text-transform:uppercase;color:#68778a;background:#f8fafc;white-space:nowrap}
.lb-table td{vertical-align:middle}
.badge-pending{background:#fff3cd;color:#856404}
.badge-completed{background:#d1e7dd;color:#0f5132}
.badge-cancelled{background:#f8d7da;color:#842029}
.progress{height:9px;background:#e9eef4}.progress-bar{background:var(--lb-green)}
.view-panel{display:none}
.view-panel.active{display:block}
.list-panel.hidden{display:none}
.customer-avatar{width:50px;height:50px;border-radius:14px;background:#eaf3ff;color:var(--lb-blue);display:flex;align-items:center;justify-content:center;font-size:21px;font-weight:800}
.detail-stat{background:#f8fafc;border:1px solid #e5ebf2;border-radius:9px;padding:13px}
.detail-stat small{display:block;color:#748092;font-size:11px;text-transform:uppercase;font-weight:800}
.detail-stat strong{display:block;font-size:18px;margin-top:4px;color:#26364a}
.empty-state{padding:45px 20px;text-align:center;color:#7b8795}
.modal-content{border:0;border-radius:14px}
@media(max-width:768px){.layby-page{padding:10px}.lb-kpi-value{font-size:21px}}
@media print{
 #header,#aside,nav,footer,.no-print{display:none!important}
 .layby-page{padding:0;background:#fff}
 .lb-card{box-shadow:none;border:1px solid #ddd}
}
</style>

<div id="main-wrapper">
<?php
if (file_exists("../includes/header.php")) require_once "../includes/header.php";
if (file_exists("../includes/aside.php")) require_once "../includes/aside.php";
?>

<main class="page-wrapper layby-page">
<div class="layby-shell">

    <div class="lb-card lb-title mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="lb-icon"><i class="fas fa-file-signature"></i></div>
            <div>
                <h3 class="mb-1 fw-bold" id="page-heading">Lay-by Management</h3>
                <div class="small text-muted">
                    <strong><?= htmlspecialchars(strtoupper($pharmacy_name)) ?></strong>
                    <span class="mx-1">•</span><?= htmlspecialchars($branch_name) ?>
                </div>
            </div>
        </div>
        <div class="no-print">
            <button class="btn btn-primary fw-bold" id="new-layby-btn"><i class="fas fa-plus me-1"></i> New Lay-by</button>
            <button class="btn btn-outline-dark fw-bold ms-1" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button>
        </div>
    </div>

    <div id="list-panel" class="list-panel <?= $view_id ? 'hidden' : '' ?>">
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3"><div class="lb-card lb-kpi lb-kpi-blue p-3"><div class="d-flex justify-content-between"><div><div class="lb-kpi-label">Active Agreements</div><div class="lb-kpi-value" id="k-active">0</div></div><div class="lb-kpi-icon"><i class="fas fa-file-signature"></i></div></div></div></div>
            <div class="col-12 col-md-6 col-xl-3"><div class="lb-card lb-kpi lb-kpi-dark p-3"><div class="d-flex justify-content-between"><div><div class="lb-kpi-label">Outstanding</div><div class="lb-kpi-value" id="k-balance">K 0.00</div></div><div class="lb-kpi-icon"><i class="fas fa-wallet"></i></div></div></div></div>
            <div class="col-12 col-md-6 col-xl-3"><div class="lb-card lb-kpi lb-kpi-green p-3"><div class="d-flex justify-content-between"><div><div class="lb-kpi-label">Collected</div><div class="lb-kpi-value" id="k-paid">K 0.00</div></div><div class="lb-kpi-icon"><i class="fas fa-hand-holding-usd"></i></div></div></div></div>
            <div class="col-12 col-md-6 col-xl-3"><div class="lb-card lb-kpi lb-kpi-orange p-3"><div class="d-flex justify-content-between"><div><div class="lb-kpi-label">Fully Paid</div><div class="lb-kpi-value" id="k-completed">0</div></div><div class="lb-kpi-icon"><i class="fas fa-check-circle"></i></div></div></div></div>
        </div>

        <div class="lb-card p-3 mb-3 no-print">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="lb-form-label">Search</label>
                    <input type="text" id="list-search" class="form-control" placeholder="Customer name or phone...">
                </div>
                <div class="col-12 col-md-3">
                    <label class="lb-form-label">Status</label>
                    <select id="list-status" class="form-select">
                        <option value="Active">Active</option>
                        <option value="Completed">Fully Paid</option>
                        <option value="All">All Agreements</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 d-flex gap-2">
                    <button class="btn btn-primary fw-bold flex-fill" id="refresh-list"><i class="fas fa-sync-alt me-1"></i> Refresh</button>
                    <button class="btn btn-outline-danger fw-bold" id="clear-completed"><i class="fas fa-trash-alt me-1"></i> Clear Paid</button>
                </div>
            </div>
        </div>

        <div class="lb-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div><h5 class="lb-section-title mb-1">Lay-by Agreements</h5><small class="text-muted">Only records belonging to the current pharmacy and branch are shown.</small></div>
                <span class="badge bg-light text-primary border" id="record-count">0 records</span>
            </div>
            <div class="table-responsive">
                <table class="table lb-table align-middle mb-0">
                    <thead><tr><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                    <tbody id="layby-list"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="view-panel" class="view-panel <?= $view_id ? 'active' : '' ?>">
        <div class="mb-3 no-print">
            <button class="btn btn-light border fw-bold" id="back-list"><i class="fas fa-arrow-left me-1"></i> Back to Lay-bys</button>
        </div>
        <div id="view-content"></div>
    </div>

</div>
</main>

<?php if (file_exists("../includes/footer.php")) require_once "../includes/footer.php"; ?>
</div>

<!-- New Lay-by Modal -->
<div class="modal fade" id="newLaybyModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<form id="new-layby-form">
<div class="modal-header">
    <h5 class="modal-title fw-bold"><i class="fas fa-file-signature text-primary me-2"></i>New Lay-by Agreement</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="row g-3">
<div class="col-lg-8">
    <div class="lb-card p-3 h-100">
        <h6 class="lb-section-title mb-3">Select Products</h6>
        <div class="position-relative mb-3">
            <input type="text" id="product-search" class="form-control" placeholder="Search product name or barcode..." autocomplete="off">
            <div id="product-results" class="product-results"></div>
        </div>
        <div class="table-responsive">
            <table class="table cart-table align-middle">
                <thead><tr><th>Product</th><th>Price</th><th style="width:110px">Qty</th><th>Total</th><th></th></tr></thead>
                <tbody id="cart-body"><tr><td colspan="5" class="empty-state">No products added.</td></tr></tbody>
            </table>
        </div>
        <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-2">
            <button type="button" class="btn btn-outline-danger btn-sm" id="clear-cart"><i class="fas fa-times me-1"></i> Clear Cart</button>
            <h4 class="fw-bold mb-0">Total: <span class="text-primary" id="cart-total">K 0.00</span></h4>
        </div>
    </div>
</div>
<div class="col-lg-4">
    <div class="lb-card p-3 h-100">
        <h6 class="lb-section-title mb-3">Customer & Payment</h6>
        <div class="mb-3"><label class="lb-form-label">Customer Name</label><input type="text" id="customer-name" class="form-control" required></div>
        <div class="mb-3"><label class="lb-form-label">Phone Number</label><input type="text" id="customer-phone" class="form-control" required></div>
        <div class="mb-3"><label class="lb-form-label">Initial Deposit</label><div class="input-group"><span class="input-group-text">K</span><input type="number" id="initial-deposit" class="form-control" min="0" step="0.01" value="0"></div></div>
        <div class="mb-3"><label class="lb-form-label">Due Date</label><input type="date" id="due-date" class="form-control" required></div>
        <div class="detail-stat mb-3"><small>Total</small><strong id="modal-total">K 0.00</strong></div>
        <div class="detail-stat mb-3"><small>Balance Due</small><strong class="text-danger" id="modal-balance">K 0.00</strong></div>
        <div id="new-error" class="alert alert-danger d-none"></div>
        <button type="submit" class="btn btn-success w-100 fw-bold py-2" id="create-btn"><i class="fas fa-check me-1"></i> Create Agreement</button>
    </div>
</div>
</div>
</div>
</form>
</div>
</div>
</div>

<!-- Edit Lay-by Modal -->
<div class="modal fade" id="editLaybyModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form id="edit-form">
<div class="modal-header">
    <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i>Edit Lay-by</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" id="edit-id">
    <div class="mb-3"><label class="lb-form-label">Customer Name</label><input type="text" id="edit-name" class="form-control" required></div>
    <div class="mb-3"><label class="lb-form-label">Phone Number</label><input type="text" id="edit-phone" class="form-control" required></div>
    <div class="mb-3"><label class="lb-form-label">Due Date</label><input type="date" id="edit-due" class="form-control" required></div>
    <div id="edit-error" class="alert alert-danger d-none"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn btn-primary fw-bold" id="edit-btn">Save Changes</button>
</div>
</form>
</div>
</div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form id="payment-form">
<div class="modal-header"><h5 class="modal-title fw-bold"><i class="fas fa-cash-register text-success me-2"></i>Record Installment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" id="payment-layby-id">
<div class="text-center mb-3"><small class="text-muted">Remaining Balance</small><h2 class="fw-bold text-danger" id="payment-balance">K 0.00</h2></div>
<div class="mb-3"><label class="lb-form-label">Amount</label><div class="input-group"><span class="input-group-text">K</span><input type="number" id="payment-amount" class="form-control" min="0.01" step="0.01" required></div></div>
<div class="mb-3"><label class="lb-form-label">Payment Method</label><select id="payment-method" class="form-select"><option>Cash</option><option>Airtel Money</option><option>MTN Money</option><option>Bank Transfer</option><option>POS Card</option></select></div>
<div id="payment-error" class="alert alert-danger d-none"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success fw-bold" id="payment-btn">Confirm Payment</button></div>
</form>
</div>
</div>
</div>

<!-- Delete confirmation -->
<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-body text-center p-4">
<div class="rounded-circle bg-danger-subtle text-danger d-inline-flex p-3 mb-3"><i class="fas fa-trash-alt fa-lg"></i></div>
<h5 class="fw-bold">Delete this lay-by?</h5>
<p class="text-muted mb-1">The agreement and its payment history will be removed.</p>
<p class="small text-danger fw-bold">Reserved stock will be restored.</p>
<input type="hidden" id="delete-id">
<button class="btn btn-light border me-2" data-bs-dismiss="modal">Cancel</button>
<button class="btn btn-danger fw-bold" id="delete-confirm">Delete Agreement</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
'use strict';

const ACTION = 'actions/layby.php';
const initialViewId = <?= $view_id ?>;
let cart = [];
let newModal, paymentModal, deleteModal, editModal;

const $ = id => document.getElementById(id);
const money = n => 'K ' + Number(n || 0).toLocaleString('en-ZM',{minimumFractionDigits:2,maximumFractionDigits:2});
const esc = s => String(s ?? '').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));

async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    const res = await fetch(ACTION,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch(e) { throw new Error(text || 'Invalid server response.'); }
    if (!res.ok || json.status === 'error') throw new Error(json.message || 'Request failed.');
    return json;
}

function showList() {
    $('list-panel').classList.remove('hidden');
    $('view-panel').classList.remove('active');
    $('page-heading').textContent='Lay-by Management';
    history.replaceState({},'',location.pathname);
    loadList();
}

function showView(id) {
    $('list-panel').classList.add('hidden');
    $('view-panel').classList.add('active');
    $('page-heading').textContent='Lay-by Details';
    history.replaceState({},'',location.pathname+'?id='+id);
    loadView(id);
}

async function loadList() {
    const body=$('layby-list');
    body.innerHTML='<tr><td colspan="7" class="text-center py-5"><i class="fas fa-spinner fa-spin me-2"></i>Loading lay-bys...</td></tr>';
    try {
        const res=await api('list',{status:$('list-status').value,search:$('list-search').value.trim()});
        body.innerHTML='';
        $('k-active').textContent=res.stats.active.toLocaleString();
        $('k-balance').textContent=money(res.stats.balance);
        $('k-paid').textContent=money(res.stats.paid);
        $('k-completed').textContent=res.stats.completed.toLocaleString();
        $('record-count').textContent=res.records.length+' record'+(res.records.length===1?'':'s');
        if(!res.records.length){
            body.innerHTML='<tr><td colspan="7" class="empty-state">No lay-by agreements found.</td></tr>';
            return;
        }
        res.records.forEach(r=>{
            const status=(r.balance_due<=0||r.status==='Completed')?'Completed':r.status;
            const badge=status==='Completed'?'badge-completed':status==='Cancelled'?'badge-cancelled':'badge-pending';
            body.insertAdjacentHTML('beforeend',`
            <tr>
                <td><strong>${esc(r.customer_name)}</strong><br><small class="text-muted">${esc(r.customer_phone)}</small></td>
                <td>${money(r.total_amount)}</td>
                <td class="text-success fw-bold">${money(r.deposit)}</td>
                <td class="text-danger fw-bold">${money(r.balance_due)}</td>
                <td>${esc(r.due_date)}</td>
                <td><span class="badge ${badge}">${esc(status)}</span></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-sm btn-outline-primary view-btn" data-id="${r.id}"><i class="fas fa-eye"></i></button>
                    ${status!=='Completed'?`<button class="btn btn-sm btn-success pay-btn" data-id="${r.id}" data-balance="${r.balance_due}"><i class="fas fa-money-bill-wave"></i></button>`:''}
                    <button class="btn btn-sm btn-outline-danger delete-btn" data-id="${r.id}"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`);
        });
    } catch(e) {
        body.innerHTML=`<tr><td colspan="7" class="text-center text-danger py-5">${esc(e.message)}</td></tr>`;
    }
}

async function loadView(id) {
    $('view-content').innerHTML='<div class="lb-card p-5 text-center"><i class="fas fa-spinner fa-spin me-2"></i>Loading agreement...</div>';
    try {
        const res=await api('view',{id});
        const r=res.layby;
        const status=(Number(r.balance_due)<=0||r.status==='Completed')?'Completed':r.status;
        const pct=Number(r.total_amount)>0 ? Math.min(100,Math.max(0,(Number(r.total_amount)-Number(r.balance_due))/Number(r.total_amount)*100)):0;
        const badge=status==='Completed'?'badge-completed':status==='Cancelled'?'badge-cancelled':'badge-pending';
        let items='', payments='';
        res.items.forEach(i=>items+=`<tr><td>${esc(i.product_name)}</td><td>${money(i.price)}</td><td class="text-center">${i.qty}</td><td class="text-end fw-bold">${money(i.total)}</td></tr>`);
        res.payments.forEach(p=>payments+=`<tr><td>${esc(p.payment_date_formatted)}</td><td><span class="badge bg-secondary">${esc(p.method)}</span></td><td class="text-end text-success fw-bold">${money(p.payment_amount)}</td><td>${esc(p.notes||'')}</td></tr>`);
        $('view-content').innerHTML=`
        <div class="lb-card p-3 mb-3">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div class="d-flex gap-3 align-items-center"><div class="customer-avatar">${esc((r.customer_name||'?').charAt(0).toUpperCase())}</div><div><h4 class="fw-bold mb-1">${esc(r.customer_name)}</h4><div class="text-muted"><i class="fas fa-phone me-1"></i>${esc(r.customer_phone)}</div></div></div>
                <div class="text-md-end"><span class="badge ${badge} mb-2">${esc(status)}</span><div class="small text-muted">Due: <strong>${esc(r.due_date)}</strong></div></div>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="detail-stat"><small>Total Agreement</small><strong>${money(r.total_amount)}</strong></div></div>
            <div class="col-md-3"><div class="detail-stat"><small>Total Paid</small><strong class="text-success">${money(Number(r.total_amount)-Number(r.balance_due))}</strong></div></div>
            <div class="col-md-3"><div class="detail-stat"><small>Balance Due</small><strong class="text-danger">${money(r.balance_due)}</strong></div></div>
            <div class="col-md-3"><div class="detail-stat"><small>Created</small><strong>${esc(r.created_at_formatted)}</strong></div></div>
        </div>
        <div class="lb-card p-3 mb-3">
            <div class="d-flex justify-content-between mb-2"><strong>Payment Progress</strong><strong>${Math.round(pct)}%</strong></div>
            <div class="progress mb-3"><div class="progress-bar" style="width:${pct}%"></div></div>
            <div class="d-flex gap-2 no-print">
                ${Number(r.balance_due)>0?`<button class="btn btn-success pay-btn" data-id="${r.id}" data-balance="${r.balance_due}"><i class="fas fa-plus-circle me-1"></i> Record Installment</button>`:''}
                <button class="btn btn-outline-primary edit-btn" data-id="${r.id}" data-name="${esc(r.customer_name)}" data-phone="${esc(r.customer_phone)}" data-due="${esc(r.due_date)}"><i class="fas fa-edit me-1"></i> Edit</button>
                <button class="btn btn-outline-danger delete-btn" data-id="${r.id}"><i class="fas fa-trash me-1"></i> Delete</button>
                <button class="btn btn-outline-dark ms-auto" onclick="window.print()"><i class="fas fa-print me-1"></i> Print Statement</button>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-lg-6"><div class="lb-card p-3"><h5 class="lb-section-title mb-3">Reserved Items</h5><div class="table-responsive"><table class="table lb-table"><thead><tr><th>Product</th><th>Price</th><th>Qty</th><th class="text-end">Subtotal</th></tr></thead><tbody>${items||'<tr><td colspan="4" class="text-center text-muted">No items.</td></tr>'}</tbody></table></div></div></div>
            <div class="col-lg-6"><div class="lb-card p-3"><h5 class="lb-section-title mb-3">Installment History</h5><div class="table-responsive"><table class="table lb-table"><thead><tr><th>Date</th><th>Method</th><th class="text-end">Amount</th><th>Notes</th></tr></thead><tbody>${payments||'<tr><td colspan="4" class="text-center text-muted">No payments recorded.</td></tr>'}</tbody></table></div></div></div>
        </div>`;
    } catch(e) {
        $('view-content').innerHTML=`<div class="alert alert-danger">${esc(e.message)}</div>`;
    }
}

function renderCart(){
    const body=$('cart-body');
    let total=0;
    if(!cart.length){body.innerHTML='<tr><td colspan="5" class="empty-state">No products added.</td></tr>';}
    else{
        body.innerHTML=cart.map((i,index)=>{
            const sub=i.price*i.qty; total+=sub;
            return `<tr><td><strong>${esc(i.name)}</strong></td><td>${money(i.price)}</td><td><div class="input-group input-group-sm"><button type="button" class="btn btn-light border qty-minus" data-i="${index}">−</button><input class="form-control text-center qty-input" data-i="${index}" value="${i.qty}" min="1" type="number"><button type="button" class="btn btn-light border qty-plus" data-i="${index}">+</button></div></td><td class="fw-bold">${money(sub)}</td><td><button type="button" class="btn btn-sm btn-outline-danger remove-item" data-i="${index}"><i class="fas fa-times"></i></button></td></tr>`;
        }).join('');
    }
    $('cart-total').textContent=money(total);
    $('modal-total').textContent=money(total);
    updateBalance();
}
function cartTotal(){return cart.reduce((s,i)=>s+i.price*i.qty,0)}
function updateBalance(){
    const balance=cartTotal()-(parseFloat($('initial-deposit').value)||0);
    $('modal-balance').textContent=money(Math.max(0,balance));
}

async function searchProducts(){
    const q=$('product-search').value.trim();
    if(q.length<2){$('product-results').style.display='none';return}
    try{
        const res=await api('products',{query:q});
        const box=$('product-results');
        if(!res.products.length){box.innerHTML='<div class="p-3 text-muted">No products found.</div>';box.style.display='block';return}
        box.innerHTML=res.products.map(p=>`<div class="product-result" data-id="${p.id}" data-name="${esc(p.item_name)}" data-price="${p.price}"><strong>${esc(p.item_name)}</strong><br><small class="text-muted">${p.barcode?esc(p.barcode)+' • ':''}Stock: ${p.quantity} • ${money(p.price)}</small></div>`).join('');
        box.style.display='block';
    }catch(e){console.error(e)}
}

$('new-layby-btn').addEventListener('click',()=>{cart=[];renderCart();$('new-layby-form').reset();$('due-date').value='<?= date('Y-m-d',strtotime('+30 days')) ?>';newModal.show()});
$('back-list').addEventListener('click',showList);
$('refresh-list').addEventListener('click',loadList);
$('list-status').addEventListener('change',loadList);
let searchTimer;
$('list-search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(loadList,300)});
$('product-search').addEventListener('input',searchProducts);
$('product-search').addEventListener('focus',searchProducts);
$('initial-deposit').addEventListener('input',updateBalance);
$('clear-cart').addEventListener('click',()=>{cart=[];renderCart()});

document.addEventListener('click',e=>{
    const product=e.target.closest('.product-result');
    if(product){
        const id=Number(product.dataset.id), name=product.dataset.name, price=Number(product.dataset.price);
        const found=cart.find(x=>x.id===id);
        if(found) found.qty++;
        else cart.push({id,name,price,qty:1});
        renderCart();$('product-search').value='';$('product-results').style.display='none';return;
    }
    const plus=e.target.closest('.qty-plus'),minus=e.target.closest('.qty-minus'),input=e.target.closest('.qty-input'),remove=e.target.closest('.remove-item');
    if(plus){cart[Number(plus.dataset.i)].qty++;renderCart()}
    if(minus){const i=Number(minus.dataset.i);cart[i].qty=Math.max(1,cart[i].qty-1);renderCart()}
    if(input){cart[Number(input.dataset.i)].qty=Math.max(1,parseInt(input.value)||1);renderCart()}
    if(remove){cart.splice(Number(remove.dataset.i),1);renderCart()}
    const view=e.target.closest('.view-btn'); if(view) showView(Number(view.dataset.id));
    const pay=e.target.closest('.pay-btn'); if(pay){$('payment-layby-id').value=pay.dataset.id;$('payment-balance').textContent=money(pay.dataset.balance);$('payment-amount').value='';$('payment-amount').max=pay.dataset.balance;$('payment-error').classList.add('d-none');paymentModal.show()}
    const edit=e.target.closest('.edit-btn');
    if(edit){
        $('edit-id').value=edit.dataset.id;
        $('edit-name').value=edit.dataset.name;
        $('edit-phone').value=edit.dataset.phone;
        $('edit-due').value=edit.dataset.due;
        $('edit-error').classList.add('d-none');
        editModal.show();
    }
    const del=e.target.closest('.delete-btn'); if(del){$('delete-id').value=del.dataset.id;deleteModal.show()}
    if(!e.target.closest('#product-search')&&!e.target.closest('#product-results')) $('product-results').style.display='none';
});

document.addEventListener('change',e=>{
    const input=e.target.closest('.qty-input');
    if(input){
        const i=Number(input.dataset.i);
        if(cart[i]){cart[i].qty=Math.max(1,parseInt(input.value)||1);renderCart();}
    }
});

$('new-layby-form').addEventListener('submit',async e=>{
    e.preventDefault();
    const total=cartTotal(),deposit=parseFloat($('initial-deposit').value)||0;
    const err=$('new-error');err.classList.add('d-none');
    if(!cart.length){err.textContent='Please add at least one product.';err.classList.remove('d-none');return}
    if(deposit<0||deposit>total){err.textContent='Deposit cannot exceed the total amount.';err.classList.remove('d-none');return}
    const btn=$('create-btn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin me-1"></i> Creating...';
    try{
        const res=await api('create',{customer_name:$('customer-name').value.trim(),customer_phone:$('customer-phone').value.trim(),deposit,total_amount:total,due_date:$('due-date').value,cart:JSON.stringify(cart)});
        newModal.hide();showView(res.layby_id);
    }catch(x){err.textContent=x.message;err.classList.remove('d-none')}
    finally{btn.disabled=false;btn.innerHTML='<i class="fas fa-check me-1"></i> Create Agreement'}
});

$('payment-form').addEventListener('submit',async e=>{
    e.preventDefault();
    const amount=parseFloat($('payment-amount').value)||0,max=parseFloat($('payment-amount').max)||0;
    const err=$('payment-error');err.classList.add('d-none');
    if(amount<=0||amount>max){err.textContent='Enter a valid amount not exceeding the balance due.';err.classList.remove('d-none');return}
    const btn=$('payment-btn');btn.disabled=true;btn.textContent='Processing...';
    try{await api('pay',{layby_id:$('payment-layby-id').value,payment_amount:amount,method:$('payment-method').value});paymentModal.hide();loadView(Number($('payment-layby-id').value));}
    catch(x){err.textContent=x.message;err.classList.remove('d-none')}
    finally{btn.disabled=false;btn.textContent='Confirm Payment'}
});

$('edit-form').addEventListener('submit',async e=>{
    e.preventDefault();
    const btn=$('edit-btn'),err=$('edit-error');
    err.classList.add('d-none');
    btn.disabled=true;btn.textContent='Saving...';
    try{
        const id=Number($('edit-id').value);
        await api('update',{
            id,
            customer_name:$('edit-name').value.trim(),
            customer_phone:$('edit-phone').value.trim(),
            due_date:$('edit-due').value
        });
        editModal.hide();
        loadView(id);
    }catch(x){
        err.textContent=x.message;
        err.classList.remove('d-none');
    }finally{
        btn.disabled=false;
        btn.textContent='Save Changes';
    }
});

$('delete-confirm').addEventListener('click',async()=>{
    const btn=$('delete-confirm');btn.disabled=true;btn.textContent='Deleting...';
    try{const id=Number($('delete-id').value);await api('delete',{id});deleteModal.hide();showList()}
    catch(e){alert(e.message)}
    finally{btn.disabled=false;btn.textContent='Delete Agreement'}
});

$('clear-completed').addEventListener('click',async()=>{
    if($('list-status').value!=='Completed' && $('list-status').value!=='All'){alert('Select Fully Paid or All Agreements before clearing.');return}
    if(!confirm('Clear all fully paid lay-bys for this branch? This removes their lay-by history but does NOT restore stock because these agreements are already fully paid. Continue?')) return;
    try{await api('clear_completed');loadList()}catch(e){alert(e.message)}
});

newModal=new bootstrap.Modal($('newLaybyModal'));
editModal=new bootstrap.Modal($('editLaybyModal'));
paymentModal=new bootstrap.Modal($('paymentModal'));
deleteModal=new bootstrap.Modal($('deleteModal'));

if(initialViewId){loadView(initialViewId)}else{loadList()}
})();
</script>
</body>
</html>
