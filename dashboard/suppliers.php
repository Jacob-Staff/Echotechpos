<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../includes/conn.php";
require_once "../includes/auth.php";

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id   = (int)($_SESSION['branch_id'] ?? 0);

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php?error=session_expired");
    exit();
}

// Supplier filters
$sort_option = $_GET['sort'] ?? 'name_asc';
$search = trim((string)($_GET['search'] ?? ''));

$allowed_sorts = [
    'name_asc'  => 'name ASC',
    'name_desc' => 'name DESC',
    'newest'    => 'id DESC',
    'oldest'    => 'id ASC'
];

if (!isset($allowed_sorts[$sort_option])) {
    $sort_option = 'name_asc';
}

$order_clause = 'ORDER BY ' . $allowed_sorts[$sort_option];

$where_clause = "pharmacy_id = ? AND branch_id = ?";
$params = [$pharmacy_id, $branch_id];
$types = "ii";

if ($search !== '') {
    $where_clause .= " AND (
        name LIKE ?
        OR phone LIKE ?
        OR email LIKE ?
        OR address LIKE ?
    )";

    $search_like = '%' . $search . '%';

    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ssss";
}

// Fetch suppliers securely for this pharmacy + branch.
$vnd_stmt = $conn->prepare(
    "SELECT id, name, phone, email, address
     FROM suppliers
     WHERE {$where_clause}
     {$order_clause}"
);

if (!$vnd_stmt) {
    die("Unable to load suppliers.");
}

$vnd_stmt->bind_param($types, ...$params);
$vnd_stmt->execute();
$VND_DATA = $vnd_stmt->get_result();
$total_vendors = $VND_DATA->num_rows;

require_once "../includes/head.php";
?>

<style>
    .search-box { border-radius: 20px; padding-left: 40px; padding-right: 42px; background: #f8f9fa; border: 1px solid #e9ecef; }
    .search-icon { position: absolute; left: 15px; top: 10px; color: #adb5bd; }
    .vendor-card-icon { width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(30, 41, 59, 0.05); color: #1e293b; font-size: 1.2rem; }
    .table thead th { font-weight: 700; color: #495057; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
    .action-btn { transition: all 0.2s; border-radius: 6px; }
    .action-btn:hover { transform: translateY(-1px); }
    .supplier-search-wrap { min-width: 280px; }
    .supplier-table thead th { white-space: nowrap; }
    .supplier-row-hidden { display: none !important; }
    .supplier-empty-row td { padding: 3rem 1rem !important; }
    .supplier-count { transition: all .2s ease; }
    .supplier-loading { opacity: .55; pointer-events: none; }

    @media (max-width: 767.98px) {
        .supplier-search-wrap { min-width: 100%; }
        .supplier-controls { width: 100%; }
        .supplier-controls > * { width: 100%; }
    }
</style>

<div id="main-wrapper">
    <?php 
    if (file_exists("../includes/header.php")) require_once "../includes/header.php"; 
    if (file_exists("../includes/aside.php")) require_once "../includes/aside.php"; 
    ?>

    <div class="page-wrapper">
        <div class="page-breadcrumb">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="page-title">Supplier Management</h4>
                    <div class="d-flex align-items-center">
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                <li class="breadcrumb-item active">Suppliers</li>
                            </ol>
                        </nav>
                    </div>
                </div>
                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <button class="btn btn-primary shadow-sm px-4 fw-bold" onclick="$('#regForm').slideToggle()">
                        <i class="mdi mdi-plus-circle me-1"></i> Register New Vendor
                    </button>
                </div>
            </div>
        </div>

        <div class="container-fluid">
            <!-- New Supplier Form (Hidden by default) -->
            <div class="card shadow-sm border-0 mb-4" id="regForm" style="display:none; border-left: 4px solid #28a745 !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <h5 class="fw-bold"><i class="mdi mdi-clipboard-edit-outline me-2 text-success"></i>New Supplier Details</h5>
                        <button type="button" class="btn-close" onclick="$('#regForm').slideUp()"></button>
                    </div>
                    <form id="addSupplierForm">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="small text-muted fw-bold">Company Name</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. Pharmanova LSK" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted fw-bold">Mobile / Phone</label>
                                <input type="text" name="phone" class="form-control" placeholder="09xxxxxxx" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted fw-bold">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="vendor@domain.com">
                            </div>
                            <div class="col-md-9">
                                <label class="small text-muted fw-bold">Business Address</label>
                                <input type="text" name="address" class="form-control" placeholder="Street, Building, City">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-success w-100 fw-bold">Save Record</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Supplier Directory -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-md-flex align-items-center justify-content-between mb-4">
                        <div>
                            <h4 class="card-title mb-1">Vendor Directory</h4>
                            <h6 class="card-subtitle text-muted">
    Showing
    <span id="supplierCount" class="badge bg-light-info text-info fw-bold supplier-count"><?php echo $total_vendors; ?></span>
    registered suppliers
</h6>
                        </div>

                        <!-- Live Search & Sort Controls -->
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-3 mt-md-0 supplier-controls">
                            <div class="position-relative supplier-search-wrap">
                                <i class="mdi mdi-magnify search-icon"></i>
                                <input
                                    type="search"
                                    id="vendorSearch"
                                    class="form-control search-box"
                                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                                    placeholder="Search name, phone, email or address..."
                                    autocomplete="off"
                                >
                                <?php if ($search !== ''): ?>
                                    <button
                                        type="button"
                                        id="clearSupplierSearch"
                                        class="btn btn-sm btn-link text-secondary position-absolute"
                                        style="right:8px; top:4px; z-index:3;"
                                        title="Clear search"
                                    >
                                        <i class="mdi mdi-close-circle-outline"></i>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <select
                                id="supplierSort"
                                class="form-select form-select-sm fw-bold border-secondary-subtle"
                                style="min-width: 190px;"
                            >
                                <option value="name_asc" <?= $sort_option === 'name_asc' ? 'selected' : '' ?>>Name (A to Z)</option>
                                <option value="name_desc" <?= $sort_option === 'name_desc' ? 'selected' : '' ?>>Name (Z to A)</option>
                                <option value="newest" <?= $sort_option === 'newest' ? 'selected' : '' ?>>Newest First</option>
                                <option value="oldest" <?= $sort_option === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle supplier-table" id="supplierTable">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">No.</th>
                                    <th>Supplier Info</th>
                                    <th>Contact Details</th>
                                    <th>Location</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="vendorTableBody">
                                <?php
                                $n = 1;
                                if ($total_vendors > 0) {
                                    while ($row = $VND_DATA->fetch_assoc()) {
                                        ?>
                                        <tr class="supplier-row">
                                            <td class="ps-4 text-muted small"><?php echo str_pad($n++, 2, "0", STR_PAD_LEFT); ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="vendor-card-icon me-3">
                                                        <i class="mdi mdi-truck-check-outline"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($row['name']); ?></h6>
                                                        <small class="text-muted">Vendor ID: <?php echo $row['id']; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small fw-bold"><i class="mdi mdi-phone-outline me-1"></i><?php echo htmlspecialchars($row['phone']); ?></div>
                                                <div class="small text-muted"><i class="mdi mdi-email-outline me-1"></i><?php echo htmlspecialchars($row['email'] ?: 'No email'); ?></div>
                                            </td>
                                            <td>
                                                <div class="small text-muted">
                                                    <i class="mdi mdi-map-marker-radius-outline me-1 text-danger"></i>
                                                    <?php echo htmlspecialchars($row['address'] ?: 'Address not provided'); ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-danger del-btn action-btn" data-id="<?php echo $row['id']; ?>" title="Delete Vendor">
                                                    <i class="mdi mdi-delete-sweep"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center p-5 text-muted'>No vendors found for this branch.</td></tr>";
                                }
                                ?>
                                <tr id="supplierNoResults" class="supplier-empty-row" style="display:none;">
                                    <td colspan="5" class="text-center text-muted">
                                        <i class="mdi mdi-magnify-close mdi-36px d-block mb-2"></i>
                                        No suppliers match your search.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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
$(document).ready(function () {
    'use strict';

    const searchInput = $('#vendorSearch');
    const sortSelect = $('#supplierSort');
    const tableRows = $('#supplierTableBody .supplier-row');
    const noResults = $('#supplierNoResults');
    const countBadge = $('#supplierCount');

    function updateLiveSearch() {
        const value = searchInput.val().trim().toLowerCase();
        let visible = 0;

        tableRows.each(function () {
            const rowText = $(this).text().toLowerCase();
            const matches = !value || rowText.indexOf(value) !== -1;

            $(this).toggleClass('supplier-row-hidden', !matches);

            if (matches) {
                visible++;
            }
        });

        countBadge.text(visible);
        noResults.toggle(visible === 0);
    }

    // Search is immediate â€” no Apply button.
    searchInput.on('input', updateLiveSearch);

    searchInput.on('keydown', function (event) {
        if (event.key === 'Escape') {
            searchInput.val('');
            updateLiveSearch();
        }
    });

    $('#clearSupplierSearch').on('click', function () {
        searchInput.val('');
        updateLiveSearch();
        searchInput.trigger('focus');
    });

    // Sorting changes immediately.
    sortSelect.on('change', function () {
        const params = new URLSearchParams(window.location.search);
        const sort = $(this).val();
        const search = searchInput.val().trim();

        if (sort && sort !== 'name_asc') {
            params.set('sort', sort);
        } else {
            params.delete('sort');
        }

        if (search) {
            params.set('search', search);
        } else {
            params.delete('search');
        }

        window.location.href = 'suppliers.php' + (
            params.toString() ? '?' + params.toString() : ''
        );
    });

    // Add supplier
    $('#addSupplierForm').on('submit', function (e) {
        e.preventDefault();

        const form = this;
        const submitButton = $(form).find('button[type="submit"]');
        const originalText = submitButton.html();

        submitButton.prop('disabled', true)
            .html('<i class="mdi mdi-loading mdi-spin me-1"></i> Saving...');

        $.ajax({
            url: '../includes/add_supplier_inc.php',
            type: 'POST',
            data: $(form).serialize()
        })
        .done(function (res) {
            const response = String(res).trim();

            if (response === 'success' || response === '1' || response === 'true') {
                location.reload();
                return;
            }

            // Preserve compatibility with an existing include that may return
            // a success page/message instead of the literal "success".
            if (!response || response.toLowerCase().indexOf('error') === -1) {
                location.reload();
                return;
            }

            alert(response);
        })
        .fail(function () {
            alert('Unable to save the supplier. Please try again.');
        })
        .always(function () {
            submitButton.prop('disabled', false).html(originalText);
        });
    });

    // Delete supplier
    $(document).on('click', '.del-btn', function () {
        const button = $(this);
        const sid = button.data('id');

        if (!sid) {
            return;
        }

        if (!confirm(
            'Warning: This action cannot be undone.\n\n' +
            'Delete this supplier?'
        )) {
            return;
        }

        const originalHtml = button.html();

        button.prop('disabled', true)
            .html('<i class="mdi mdi-loading mdi-spin"></i>');

        $.ajax({
            url: '../includes/delete_supplier_inc.php',
            type: 'POST',
            data: { id: sid }
        })
        .done(function (res) {
            if (String(res).trim() === 'success') {
                location.reload();
            } else {
                alert('Delete failed. Refresh and try again.');
                button.prop('disabled', false).html(originalHtml);
            }
        })
        .fail(function () {
            alert('Delete failed. Please try again.');
            button.prop('disabled', false).html(originalHtml);
        });
    });

    // Run once so a search value restored from the URL is reflected immediately.
    updateLiveSearch();
});
</script>

</body>
</html>
