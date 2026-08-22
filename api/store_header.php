<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../includes/conn.php";

// Fetch available active branches
$branches_query = $conn->query("SELECT id, name FROM branches ORDER BY name ASC");
$all_branches = [];
if ($branches_query) {
    while ($row = $branches_query->fetch_assoc()) {
        $all_branches[] = $row;
    }
}

// Active branch resolution
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : (isset($_SESSION['current_branch_id']) ? $_SESSION['current_branch_id'] : 10);
$_SESSION['current_branch_id'] = $branch_id;

// Fetch current branch name
$pharmacy_name = "Echo Pharmacy";
$stmt = $conn->prepare("SELECT name FROM branches WHERE id = ?");
if ($stmt) {
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $pharmacy_name = $row['name'];
    }
    $stmt->close();
}

// Calculate total cart badge items for CURRENT active branch
$cart_badge_count = 0;
if (isset($_SESSION['carts'][$branch_id])) {
    foreach ($_SESSION['carts'][$branch_id] as $c_item) {
        $cart_badge_count += $c_item['qty'];
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #003339;">
  <div class="container">
    <a class="navbar-brand fw-bold" href="../online_store.php?bid=<?php echo $branch_id; ?>">
      <?php echo htmlspecialchars($pharmacy_name); ?>
    </a>

    <!-- Branch Switcher Dropdown -->
    <div class="dropdown me-3">
      <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" id="branchDropdown" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="mdi mdi-store-outline me-1"></i> Switch Branch
      </button>
      <ul class="dropdown-menu" aria-labelledby="branchDropdown">
        <?php foreach ($all_branches as $b): ?>
          <li>
            <a class="dropdown-item <?php echo ($b['id'] == $branch_id) ? 'active fw-bold' : ''; ?>" 
               href="../online_store.php?bid=<?php echo $b['id']; ?>">
              <?php echo htmlspecialchars($b['name']); ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- Cart Link -->
    <a href="view_cart.php?bid=<?php echo $branch_id; ?>" class="btn btn-success rounded-pill position-relative ms-auto">
      <i class="mdi mdi-cart-outline fs-5"></i> Cart
      <span id="cart-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
        <?php echo $cart_badge_count; ?>
      </span>
    </a>
  </div>
</nav>
