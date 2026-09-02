<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/conn.php';

require_admin();

$user_role = current_role();
$user_display_name = current_user();
$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);

if ($pharmacy_id <= 0) {
    header("Location: ../index.php?error=session_expired");
    exit();
}

/* ---------- Admin-managed dashboard visual ---------- */
function dashboard_media_path(): string
{
    return dirname(__DIR__) . '/uploads/admin/';
}

function dashboard_media_current(): ?string
{
    $dir = dashboard_media_path();
    $files = glob($dir . 'admin_dashboard.*') ?: [];
    $allowed = ['jpg','jpeg','png','webp','gif','mp4','webm'];

    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true) && is_file($file)) {
            return $file;
        }
    }

    return null;
}

function dashboard_media_extension_for_mime(string $mime): ?string
{
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'video/mp4'  => 'mp4',
        'video/webm' => 'webm',
    ][$mime] ?? null;
}

function dashboard_media_delete_existing(): void
{
    $dir = dashboard_media_path();
    $files = glob($dir . 'admin_dashboard.*') ?: [];
    $allowed = ['jpg','jpeg','png','webp','gif','mp4','webm'];

    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true) && is_file($file)) {
            @unlink($file);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_media_action'])) {
    $mediaAction = (string)$_POST['dashboard_media_action'];

    try {
        if ($mediaAction === 'upload') {
            if (
                !isset($_FILES['dashboard_media']) ||
                !is_array($_FILES['dashboard_media']) ||
                (int)($_FILES['dashboard_media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            ) {
                throw new RuntimeException('Please choose a dashboard image or animation file.');
            }

            $upload = $_FILES['dashboard_media'];
            $maxBytes = 25 * 1024 * 1024;

            if ((int)$upload['size'] <= 0 || (int)$upload['size'] > $maxBytes) {
                throw new RuntimeException('Dashboard media must be larger than 0 bytes and no more than 25 MB.');
            }

            $tmp = (string)$upload['tmp_name'];
            if (!is_uploaded_file($tmp)) {
                throw new RuntimeException('The uploaded dashboard media could not be verified.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmp);
            $extension = dashboard_media_extension_for_mime($mime);

            if ($extension === null) {
                throw new RuntimeException('Unsupported file type. Use JPG, PNG, WebP, GIF, MP4 or WebM.');
            }

            $uploadDir = dashboard_media_path();
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('The dashboard upload directory could not be created.');
            }

            dashboard_media_delete_existing();

            $destination = $uploadDir . 'admin_dashboard.' . $extension;
            if (!move_uploaded_file($tmp, $destination)) {
                throw new RuntimeException('The dashboard media could not be saved.');
            }

            header('Location: admin_dashboard.php?media=updated');
            exit();
        }

        if ($mediaAction === 'remove') {
            dashboard_media_delete_existing();
            header('Location: admin_dashboard.php?media=removed');
            exit();
        }

        throw new RuntimeException('Unknown dashboard media action.');
    } catch (Throwable $e) {
        $mediaError = urlencode($e->getMessage());
        header('Location: admin_dashboard.php?media_error=' . $mediaError);
        exit();
    }
}

$dashboard_media_file = dashboard_media_current();
$dashboard_media_url = $dashboard_media_file
    ? '../uploads/admin/' . rawurlencode(basename($dashboard_media_file))
    : '';

function esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ---------- Pharmacy ---------- */
$pharmacy_name = 'EchoTech POS';
if ($stmt = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1")) {
    $stmt->bind_param("i", $pharmacy_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    if ($r && !empty($r['name'])) $pharmacy_name = $r['name'];
    $stmt->close();
}

/* ---------- Helpers ---------- */
function scalar_query($conn, $sql, $types = '', $params = [], $default = 0) {
    $stmt = @$conn->prepare($sql);
    if (!$stmt) return $default;
    if ($types !== '') @$stmt->bind_param($types, ...$params);
    if (!@$stmt->execute()) { $stmt->close(); return $default; }
    $row = @$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $default;
    $value = array_values($row)[0] ?? $default;
    return is_numeric($value) ? $value : ($value ?: $default);
}

function rows_query($conn, $sql, $types = '', $params = []) {
    $stmt = @$conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') @$stmt->bind_param($types, ...$params);
    if (!@$stmt->execute()) { $stmt->close(); return []; }
    $result = @$stmt->get_result();
    $rows = [];
    if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function table_exists($conn, $table) {
    $table = $conn->real_escape_string($table);
    $r = @$conn->query("SHOW TABLES LIKE '{$table}'");
    return $r && $r->num_rows > 0;
}

function column_exists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $r = @$conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $r && $r->num_rows > 0;
}

function first_existing_column($conn, $table, $candidates) {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) return $c;
    }
    return null;
}

/* ---------- Core sales ---------- */
$total_sales = (float)scalar_query(
    $conn,
    "SELECT COALESCE(SUM(COALESCE(NULLIF(total_amount,0), total)),0)
     FROM sales
     WHERE pharmacy_id = ?",
    "i", [$pharmacy_id], 0
);

$total_orders = (int)scalar_query(
    $conn,
    "SELECT COUNT(id) FROM sales WHERE pharmacy_id = ?",
    "i", [$pharmacy_id], 0
);

$branch_count = (int)scalar_query(
    $conn,
    "SELECT COUNT(id) FROM branches WHERE pharmacy_id = ? AND is_active = 1",
    "i", [$pharmacy_id], 0
);

$avg = $total_orders > 0 ? $total_sales / $total_orders : 0;

/* ---------- Branch revenue ---------- */
$b_names = [];
$b_revenues = [];
$branch_rows = rows_query($conn,
    "SELECT b.branch_name,
            COALESCE(SUM(COALESCE(NULLIF(s.total_amount,0), s.total)),0) AS revenue
     FROM branches b
     LEFT JOIN sales s
       ON s.branch_id=b.id
      AND s.pharmacy_id=?
     WHERE b.pharmacy_id=? AND b.is_active=1
     GROUP BY b.id,b.branch_name
     ORDER BY revenue DESC",
    "ii", [$pharmacy_id,$pharmacy_id]
);
foreach ($branch_rows as $r) {
    $b_names[] = $r['branch_name'];
    $b_revenues[] = (float)$r['revenue'];
}

/* ---------- Payment mix ---------- */
$p_labels = [];
$p_counts = [];
if (column_exists($conn,'sales','payment_method')) {
    $payment_rows = rows_query($conn,
        "SELECT COALESCE(NULLIF(TRIM(payment_method),''),'Other') AS method, COUNT(*) AS total
         FROM sales WHERE pharmacy_id=? GROUP BY method ORDER BY total DESC",
        "i", [$pharmacy_id]
    );
    foreach ($payment_rows as $r) {
        $p_labels[] = $r['method'];
        $p_counts[] = (int)$r['total'];
    }
}
if (!$p_labels) { $p_labels=['No data']; $p_counts=[1]; }

/* ---------- Date column discovery ---------- */
$sales_date_col = first_existing_column($conn,'sales',['sale_date','created_at','transaction_date','date','created']);
$trend_labels=[]; $trend_revenue=[]; $trend_transactions=[];

if ($sales_date_col) {
    $sql = "SELECT DATE(`$sales_date_col`) d,
                   COALESCE(SUM(COALESCE(NULLIF(total_amount,0), total)),0) revenue,
                   COUNT(*) transactions
            FROM sales
            WHERE pharmacy_id=?
            GROUP BY DATE(`$sales_date_col`)
            ORDER BY d DESC
            LIMIT 14";
    $trend_rows = array_reverse(rows_query($conn,$sql,"i",[$pharmacy_id]));
    foreach ($trend_rows as $r) {
        $trend_labels[] = date('d M',strtotime($r['d']));
        $trend_revenue[] = (float)$r['revenue'];
        $trend_transactions[] = (int)$r['transactions'];
    }
}

/* fallback so charts are always valid */
if (!$trend_labels) {
    $trend_labels=['Today'];
    $trend_revenue=[(float)$total_sales];
    $trend_transactions=[(int)$total_orders];
}

/* ---------- Inventory / categories ---------- */
$stock_total=0; $category_total=0; $category_labels=[]; $category_data=[];
$low_stock_count=0; $expired_count=0;

if (table_exists($conn,'store_items')) {
    $stock_total=(int)scalar_query($conn,
        "SELECT COUNT(*) FROM store_items WHERE pharmacy_id=?",
        "i",[$pharmacy_id],0
    );

    if (column_exists($conn,'store_items','category')) {
        $cat_rows=rows_query($conn,
            "SELECT COALESCE(NULLIF(TRIM(category),''),'Uncategorised') category, COUNT(*) total
             FROM store_items WHERE pharmacy_id=? GROUP BY category ORDER BY total DESC LIMIT 8",
            "i",[$pharmacy_id]
        );
        foreach($cat_rows as $r){
            $category_labels[]=$r['category'];
            $category_data[]=(int)$r['total'];
        }
        $category_total=count($category_labels);
    }

    if (column_exists($conn,'store_items','quantity')) {
        $low_stock_count=(int)scalar_query($conn,
            "SELECT COUNT(*) FROM store_items WHERE pharmacy_id=? AND quantity<=3",
            "i",[$pharmacy_id],0
        );
    }

    if (column_exists($conn,'store_items','expiry_date')) {
        $expired_count=(int)scalar_query($conn,
            "SELECT COUNT(*) FROM store_items WHERE pharmacy_id=? AND expiry_date IS NOT NULL AND expiry_date < CURDATE()",
            "i",[$pharmacy_id],0
        );
    }
}

/* Inventory status doughnut */
$stock_labels=['Healthy','Low Stock','Expired'];
$healthy=max($stock_total-$low_stock_count-$expired_count,0);
$stock_data=[$healthy,$low_stock_count,$expired_count];

/* ---------- Top products ---------- */
/*
 * The supplied database stores sale line-items in sales_items:
 *   sales_items.sale_id -> sales.id
 *   sales_items.product_id -> store_items.id
 *   sales_items.quantity
 *   sales_items.unit_price
 *
 * The sales table itself does NOT contain product_id or quantity, so the
 * dashboard must use this relationship for product rankings.
 */
$top_products = [];

if (
    table_exists($conn, 'sales_items') &&
    table_exists($conn, 'store_items') &&
    column_exists($conn, 'sales_items', 'sale_id') &&
    column_exists($conn, 'sales_items', 'product_id') &&
    column_exists($conn, 'sales_items', 'quantity') &&
    column_exists($conn, 'sales_items', 'unit_price') &&
    column_exists($conn, 'store_items', 'id') &&
    column_exists($conn, 'store_items', 'item_name')
) {
    $top_products = rows_query(
        $conn,
        "SELECT
            si.product_id,
            p.item_name AS name,
            COALESCE(SUM(si.quantity),0) AS qty,
            COALESCE(SUM(
                si.quantity * COALESCE(si.unit_price, p.price, 0)
            ),0) AS revenue
         FROM sales_items si
         INNER JOIN sales s
            ON s.id=si.sale_id
           AND s.pharmacy_id=?
         INNER JOIN store_items p
            ON p.id=si.product_id
           AND p.pharmacy_id=?
         WHERE si.pharmacy_id=?
         GROUP BY si.product_id,p.item_name
         ORDER BY revenue DESC, qty DESC
         LIMIT 5",
        "iii",
        [$pharmacy_id,$pharmacy_id,$pharmacy_id]
    );
}

$admin_page_title = 'Executive Overview';
$branch_count = (int)$branch_count;
$total_orders = (int)$total_orders;
$current_admin_page = 'admin_dashboard.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Control Center | <?php echo htmlspecialchars($pharmacy_name); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<style>
:root{
    --bg:#f4f6f9;
    --surface:#ffffff;
    --surface-soft:#f8fafc;
    --charcoal:#343a40;
    --charcoal-2:#2f3640;
    --charcoal-3:#3d4652;
    --text:#1d252d;
    --muted:#6d7782;
    --border:#dfe4e9;
    --blue:#1d4ed8;
    --blue-soft:#eaf2ff;
    --cyan:#19a9d2;
    --green:#159a68;
    --green-soft:#e8f7f0;
    --yellow:#e7a72e;
    --yellow-soft:#fff6df;
    --red:#d94d61;
    --red-soft:#fff0f2;
    --purple:#7658e8;
    --sidebar:250px;
    --radius:12px;
    --surface-border:#d9dee5;
    --panel-white:#ffffff;
    --panel-soft:#f5f7fa;
    --primary-deep:#1d4ed8;
    --shadow:0 4px 18px rgba(31,40,49,.07);
}
*{box-sizing:border-box}
html,body{
    margin:0;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,Arial,sans-serif;
    font-size:14px;
}
body{overflow-x:hidden}
button,input{font:inherit}
button{cursor:pointer}
a{text-decoration:none;color:inherit}

.app{min-height:100vh;background:var(--bg)}

.legacy-sidebar{
    position:fixed;left:0;top:0;bottom:0;width:var(--sidebar);
    background:var(--charcoal);
    border-right:1px solid #27303a;
    z-index:1000;padding:18px 14px 115px;
    overflow:auto;
}
.brand{
    height:54px;display:flex;align-items:center;gap:12px;
    padding:0 9px;margin-bottom:18px;color:#fff;
}
.brand-mark{
    width:38px;height:38px;border-radius:10px;
    background:var(--blue);display:grid;place-items:center;
}
.brand-mark i{font-size:17px;color:#fff}
.brand strong{display:block;font-size:15px;font-weight:800;letter-spacing:.2px}
.brand small{
    display:block;color:#aeb8c2;font-size:9px;
    text-transform:uppercase;letter-spacing:1.2px;margin-top:3px
}
.side-caption{
    font-size:10px;text-transform:uppercase;letter-spacing:1.2px;
    color:#8f9ba7;font-weight:800;padding:12px 11px 7px
}
.nav{display:flex;flex-direction:column;gap:3px}
.nav a{
    min-height:42px;border-radius:8px;color:#bdc6cf;
    display:flex;align-items:center;gap:11px;padding:0 12px;
    font-size:13px;font-weight:600;border:1px solid transparent
}
.nav a i{width:18px;text-align:center;color:#8996a3;font-size:13px}
.nav a:hover{background:#303a46;color:#fff}
.nav a.active{
    background:#263f70;border-color:#315aa0;color:#fff;
    box-shadow:inset 3px 0 var(--blue)
}
.nav a.active i{color:#70a0ff}
.nav-badge{
    margin-left:auto;background:#3e4a59;color:#e8edf4;
    border-radius:12px;padding:3px 7px;font-size:10px
}
.nav a.danger{color:#f17a8b}.nav a.danger i{color:#f17a8b}
.separator{height:1px;background:#3a444e;margin:14px 8px}

.sidebar-mini{
    margin:14px 7px 0;background:#252d36;border:1px solid #3b4652;
    border-radius:9px;padding:12px;
}
.mini-title{font-size:11px;font-weight:800;color:#edf1f5;margin-bottom:9px}
.mini-line{display:flex;justify-content:space-between;color:#a3adb8;font-size:10px;margin:7px 0}
.mini-line b{color:#f0f3f6}
.mini-progress{height:4px;background:#394552;border-radius:4px;overflow:hidden}
.mini-progress span{display:block;height:100%;border-radius:4px;background:var(--blue)}

.side-user{
    position:absolute;left:14px;right:14px;bottom:13px;
    border-top:1px solid #3a444e;padding-top:11px;background:var(--charcoal)
}
.user{
    display:flex;align-items:center;gap:9px;padding:9px;
    background:#252d36;border:1px solid #3b4652;border-radius:9px
}
.avatar{
    width:32px;height:32px;border-radius:50%;background:#465363;
    display:grid;place-items:center;font-size:12px;font-weight:800;color:#fff
}
.user-copy{min-width:0;flex:1}
.user-copy b{display:block;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#fff}
.user-copy span{display:block;color:#9ba7b3;font-size:9px;margin-top:3px}
.user i{color:#84909d;font-size:11px}
.side-user .nav-link{
    display:flex;align-items:center;gap:9px;color:#f17a8b;
    font-size:11px;font-weight:700;padding:9px
}

.main{margin-left:var(--sidebar);min-height:100vh}
.legacy-topbar{
    height:64px;border-bottom:1px solid var(--border);
    background:#ffffff;display:flex;align-items:center;
    justify-content:space-between;padding:0 28px;
    position:sticky;top:0;z-index:900;box-shadow:0 1px 7px rgba(0,0,0,.03)
}
.top-left{display:flex;align-items:center;gap:12px}
.mobile-btn{
    display:none;width:36px;height:36px;border:1px solid var(--border);
    border-radius:8px;background:#fff;color:var(--charcoal)
}
.crumb{font-size:12px;color:var(--muted)}
.crumb b{color:var(--text);font-size:14px}
.top-right{display:flex;align-items:center;gap:8px}
.search-mini{
    width:230px;height:37px;background:var(--panel-white);border:1px solid var(--border);
    border-radius:8px;color:var(--text);font-size:12px;padding:0 12px;outline:none
}
.search-mini:focus{border-color:#8bb0ff;box-shadow:0 0 0 3px var(--blue-soft)}
.top-icon{
    width:37px;height:37px;background:var(--panel-white);border:1px solid var(--border);
    border-radius:8px;color:#65717d;display:grid;place-items:center
}
.top-icon:hover{color:var(--blue);border-color:#a9c0ec}
.branch{
    height:37px;padding:0 11px;border:1px solid var(--border);
    background:#fff;border-radius:8px;color:#65717d;font-size:11px;
    display:flex;align-items:center;gap:7px
}
.branch i{color:var(--blue)}

.content{max-width:1600px;margin:auto;padding:26px 28px 40px}
.page-head{
    display:flex;justify-content:space-between;align-items:flex-end;
    margin-bottom:17px
}
.page-head h1{font-size:27px;margin:0;font-weight:800;letter-spacing:-.6px;color:var(--charcoal)}
.page-head p{margin:6px 0 0;color:var(--muted);font-size:12px;line-height:1.5}
.head-actions{display:flex;gap:8px}
.btn{
    height:38px;padding:0 13px;border-radius:8px;border:1px solid var(--border);
    background:#fff;color:#4e5a66;font-size:12px;font-weight:700
}
.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.btn:hover{box-shadow:0 3px 10px rgba(30,50,80,.1)}

.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-bottom:14px}
.kpi{
    position:relative;overflow:hidden;background:var(--panel-white);border:1px solid var(--border);
    border-radius:var(--radius);padding:17px;min-height:116px;box-shadow:var(--shadow)
}
.kpi:after{
    content:"";position:absolute;width:90px;height:90px;border-radius:50%;
    right:-34px;bottom:-43px;background:rgba(36,107,254,.055)
}
.kpi-head{
    display:flex;align-items:center;justify-content:space-between;
    color:#727d88;font-size:10px;text-transform:uppercase;
    letter-spacing:.8px;font-weight:800
}
.kpi-icon{
    width:34px;height:34px;border-radius:8px;background:var(--blue-soft);
    display:grid;place-items:center;color:var(--blue);font-size:13px
}
.kpi-value{font-size:25px;font-weight:800;margin-top:9px;letter-spacing:-.5px;color:var(--charcoal)}
.kpi-sub{font-size:10px;color:var(--muted);margin-top:4px}
.kpi.green .kpi-icon{color:var(--green);background:var(--green-soft)}
.kpi.yellow .kpi-icon{color:var(--yellow);background:var(--yellow-soft)}
.kpi.purple .kpi-icon{color:var(--purple);background:#f0edff}

.core{display:grid;grid-template-columns:minmax(0,1.55fr) 330px;gap:14px}
.reference-panel,.donut-card,.chart-panel,.small-card,.list-panel{
    background:var(--panel-white);border:1px solid var(--border);border-radius:var(--radius);
    box-shadow:var(--shadow)
}
.reference-panel{overflow:hidden}
.panel-top{
    min-height:54px;padding:0 17px;display:flex;align-items:center;
    justify-content:space-between;background:var(--panel-white);border-bottom:1px solid var(--border)
}
.panel-top b{font-size:13px;color:var(--charcoal)}
.panel-top span{font-size:10px;color:var(--muted)}
.hero{display:grid;grid-template-columns:40% 60%;min-height:320px}
.hero-info{padding:23px 21px;background:#fff}
.eyebrow{
    font-size:10px;text-transform:uppercase;letter-spacing:1.3px;
    color:var(--blue);font-weight:900
}
.hero-info h2{
    font-size:28px;line-height:1.08;margin:9px 0 9px;
    font-weight:850;color:var(--charcoal)
}
.hero-info p{font-size:12px;line-height:1.65;color:#697581;max-width:440px;margin:0}
.hero-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-top:20px}
.hero-stat{background:#f7f9fb;border:1px solid #e4e8ed;border-radius:8px;padding:11px}
.hero-stat b{display:block;font-size:16px;color:var(--charcoal)}
.hero-stat span{display:block;color:#7a8590;font-size:9px;margin-top:3px}

.image-box{
    position:relative;min-height:320px;background:#edf1f5;
    overflow:hidden;border-left:1px solid var(--border)
}
.image-box img{width:100%;height:100%;min-height:320px;object-fit:cover;display:block}
.image-empty{
    height:100%;min-height:320px;display:grid;place-items:center;
    background:linear-gradient(135deg,#f7f9fb,#e9edf1)
}
.image-empty-inner{text-align:center;color:#87919c}
.image-empty i{font-size:38px;display:block;margin-bottom:11px}
.image-empty b{display:block;color:#5d6873;font-size:12px}
.image-empty span{display:block;font-size:10px;margin-top:5px;line-height:1.5}
.image-label{
    position:absolute;left:15px;bottom:14px;padding:9px 11px;
    background:rgba(32,40,49,.9);color:#fff;border-radius:7px
}
.image-label b{display:block;font-size:11px}.image-label span{display:block;color:#c1cad3;font-size:9px;margin-top:3px}

.side-analytics{display:flex;flex-direction:column;gap:14px}
.donut-card{padding:15px 16px;min-height:218px}
.donut-head{display:flex;justify-content:space-between;align-items:center}
.donut-head b{font-size:13px;color:var(--charcoal)}
.donut-head span{font-size:10px;color:var(--muted)}
.donut-wrap{height:140px;position:relative;display:grid;place-items:center;margin-top:3px}
.donut-wrap canvas{max-width:140px!important;max-height:140px!important}
.donut-center{position:absolute;text-align:center;pointer-events:none}
.donut-center b{display:block;font-size:20px;color:var(--charcoal)}
.donut-center span{display:block;font-size:9px;color:var(--muted);margin-top:3px}
.legend{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:5px}
.legend-item{font-size:9px;color:#687480;display:flex;align-items:center;gap:6px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--blue);display:inline-block}
.dot.cyan{background:var(--cyan)}.dot.green{background:var(--green)}
.dot.yellow{background:var(--yellow)}.dot.purple{background:var(--purple)}

.four{display:grid;grid-template-columns:repeat(4,1fr);gap:13px;margin-top:14px}
.small-card{padding:14px;min-height:175px}
.small-head{display:flex;justify-content:space-between;align-items:center}
.small-head b{font-size:12px;color:var(--charcoal)}
.small-head i{font-size:13px;color:var(--blue)}
.small-chart{height:92px;margin-top:8px}
.small-foot{display:flex;justify-content:space-between;color:#75808b;font-size:9px;margin-top:7px}
.small-foot b{color:var(--charcoal);font-size:10px}

.lower{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(300px,.75fr);gap:14px;margin-top:14px}
.chart-panel{overflow:hidden}
.chart-body{height:340px;padding:15px 17px 17px}
.activity{padding:5px 17px 12px}
.activity-row{display:flex;align-items:center;gap:11px;padding:14px 0;border-bottom:1px solid #edf0f3}
.activity-row:last-child{border-bottom:0}
.activity-icon{
    width:35px;height:35px;border-radius:8px;background:var(--blue-soft);
    display:grid;place-items:center;color:var(--blue);font-size:12px
}
.activity-copy{flex:1;min-width:0}
.activity-copy b{display:block;font-size:11px;color:var(--charcoal)}
.activity-copy span{display:block;color:#75808c;font-size:9px;margin-top:3px}
.activity-value{font-size:11px;font-weight:800;color:var(--charcoal)}

.bottom-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
.list-panel{overflow:hidden}
.list{padding:5px 17px 12px}
.list-row{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid #edf0f3}
.list-row:last-child{border-bottom:0}
.rank{
    width:28px;height:28px;border-radius:7px;background:#f0f3f6;
    display:grid;place-items:center;font-size:10px;color:#64717d;font-weight:800
}
.list-copy{flex:1;min-width:0}
.list-copy b{display:block;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--charcoal)}
.list-copy span{display:block;font-size:9px;color:#75808c;margin-top:3px}
.list-value{text-align:right}
.list-value b{display:block;font-size:10px;color:var(--charcoal)}
.list-value span{display:block;color:#75808c;font-size:8px;margin-top:3px}
.status-row{
    display:flex;align-items:center;justify-content:space-between;
    padding:11px 0;border-bottom:1px solid #edf0f3;font-size:10px;color:#687480
}
.status-row:last-child{border-bottom:0}
.status-left{display:flex;align-items:center;gap:8px}
.status-dot{width:8px;height:8px;border-radius:50%;background:var(--green)}
.status-dot.warn{background:var(--yellow)}.status-dot.red{background:var(--red)}

@media(max-width:1200px){
    .core{grid-template-columns:1fr}
    .side-analytics{display:grid;grid-template-columns:1fr 1fr}
    .four{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:900px){
    :root{--sidebar:0px}
    .sidebar{
        width:250px;transform:translateX(-100%);transition:.22s;
        box-shadow:15px 0 35px rgba(0,0,0,.22)
    }
    .sidebar.open{transform:translateX(0)}
    .mobile-btn{display:grid;place-items:center}
    .search-mini{display:none}
    .content{padding:20px 16px 32px}
    .topbar{padding:0 16px}
    .kpis{grid-template-columns:repeat(2,1fr)}
    .hero{grid-template-columns:1fr}
    .image-box{border-left:0;border-top:1px solid var(--border)}
    .lower,.bottom-grid{grid-template-columns:1fr}
}
@media(max-width:560px){
    .kpis,.four,.side-analytics{grid-template-columns:1fr}
    .page-head{flex-direction:column;align-items:flex-start;gap:12px}
    .head-actions{width:100%}.head-actions .btn{flex:1}
    .page-head h1{font-size:23px}
    .hero-info h2{font-size:23px}
    .hero-stats{grid-template-columns:1fr 1fr}
    .branch{display:none}
    .top-right{gap:5px}
}
.dashboard-media{
    width:100%;
    height:100%;
    min-height:320px;
    object-fit:cover;
    display:block;
    background:#e9edf1;
}
.image-box{
    position:relative;
    isolation:isolate;
}
.dashboard-media-tools{
    position:absolute;
    top:12px;
    right:12px;
    z-index:6;
    display:flex;
    gap:7px;
    align-items:center;
    opacity:0;
    visibility:hidden;
    pointer-events:none;
    transform:translateY(-4px);
    transition:opacity .18s ease,transform .18s ease,visibility .18s ease;
}
.image-box:hover .dashboard-media-tools,
.image-box:focus-within .dashboard-media-tools{
    opacity:1;
    visibility:visible;
    pointer-events:auto;
    transform:translateY(0);
}
.dashboard-media-tools form{margin:0}
.dashboard-media-upload{display:block}
.media-action-btn{
    width:34px;
    height:34px;
    padding:0;
    border:1px solid rgba(255,255,255,.82);
    border-radius:8px;
    background:rgba(32,40,49,.90);
    color:#fff;
    display:grid;
    place-items:center;
    font-size:11px;
    cursor:pointer;
    box-shadow:0 4px 14px rgba(10,20,30,.18);
    backdrop-filter:blur(6px);
}
.media-action-btn:hover{background:rgba(32,40,49,.99)}
.media-action-btn.danger{background:rgba(158,48,64,.92)}
.media-action-btn.danger:hover{background:rgba(158,48,64,.99)}
.media-action-btn input{display:none}
.media-action-btn span{display:none}
.dashboard-media-notice{
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    border-radius:9px;
    margin-bottom:12px;
    font-size:11px;
    font-weight:700;
    border:1px solid;
}
.dashboard-media-notice.success{
    color:#18764f;
    background:var(--green-soft);
    border-color:#bfe7d4;
}
.dashboard-media-notice.error{
    color:#b23d4e;
    background:var(--red-soft);
    border-color:#efc6cc;
}

/* ---------- Mobile layout hardening ---------- */
@media(max-width:900px){
    .main{
        width:100%;
        max-width:100%;
        margin-left:0;
        min-width:0;
    }
    .content{
        width:100%;
        max-width:none;
        min-width:0;
        padding:18px 14px 30px;
    }
    .admin-header{
        width:100%;
        max-width:100%;
        min-width:0;
    }
    .core{
        grid-template-columns:1fr;
        min-width:0;
    }
    .hero{
        grid-template-columns:1fr;
        min-height:0;
    }
    .hero-info{
        padding:20px 17px;
    }
    .hero-info p{max-width:none}
    .image-box{
        min-height:280px;
        border-left:0;
        border-top:1px solid var(--border);
    }
    .dashboard-media,.image-empty{min-height:280px}
    .side-analytics{
        display:grid;
        grid-template-columns:1fr 1fr;
        min-width:0;
    }
    .four{
        grid-template-columns:1fr 1fr;
        min-width:0;
    }
    .lower,.bottom-grid{
        grid-template-columns:1fr;
        min-width:0;
    }
    .chart-panel,.reference-panel,.donut-card,.small-card,.list-panel,.kpi{
        min-width:0;
        max-width:100%;
    }
}
@media(max-width:600px){
    .content{padding:12px 10px 24px}
    .page-head{
        flex-direction:column;
        align-items:flex-start;
        gap:10px;
    }
    .page-head h1{font-size:22px;line-height:1.15}
    .page-head p{font-size:11px}
    .head-actions{
        width:100%;
        flex-wrap:wrap;
    }
    .head-actions .btn{
        flex:1 1 140px;
        min-width:0;
    }
    .kpis{
        grid-template-columns:1fr 1fr;
        gap:9px;
    }
    .kpi{
        min-height:104px;
        padding:12px;
    }
    .kpi-value{
        font-size:20px;
        white-space:nowrap;
    }
    .kpi-sub{line-height:1.35}
    .hero-info h2{font-size:22px}
    .hero-stats{grid-template-columns:1fr 1fr}
    .side-analytics,.four{grid-template-columns:1fr}
    .chart-body{
        height:270px;
        padding:11px;
    }
    .small-card{min-height:155px}
    .image-box{min-height:245px}
    .dashboard-media,.image-empty{min-height:245px}
    .image-label{
        left:9px;
        bottom:9px;
        padding:8px 9px;
    }
    .image-label b{font-size:10px}
    .image-label span{font-size:8px}
    .dashboard-media-tools{
        top:9px;
        right:9px;
    }
}
@media(max-width:430px){
    .kpis{grid-template-columns:1fr}
    .hero-stats{grid-template-columns:1fr 1fr}
    .panel-top{
        padding:0 12px;
        gap:7px;
    }
    .panel-top b{font-size:12px}
    .panel-top span{font-size:8px}
    .activity{
        padding-left:12px;
        padding-right:12px;
    }
    .activity-row{gap:8px}
    .activity-value{font-size:10px}
    .list{
        padding-left:12px;
        padding-right:12px;
    }
}

/* Touch devices have no hover; focus-within still reveals the icon controls
   after the control receives focus, while desktop remains hover-only. */
@media (hover:none){
    .image-box:focus-within .dashboard-media-tools{
        opacity:1;
        visibility:visible;
        pointer-events:auto;
        transform:translateY(0);
    }
}

@media print{
    .dashboard-media-tools,
    .dashboard-media-notice{display:none!important}
}

@media print{
    .sidebar,.topbar,.head-actions{display:none!important}
    .main{margin-left:0}
    .content{padding:0}
    body,.app{background:#fff}
    .reference-panel,.donut-card,.chart-panel,.small-card,.list-panel,.kpi{box-shadow:none}
}
</style>
</head>

<body>
<div class="app">
<?php require_once __DIR__ . '/actions/admin_aside.php'; ?>



<main class="main">
<?php require_once __DIR__ . '/actions/admin_header.php'; ?>


<section class="content">

    <?php if (isset($_GET['media']) && $_GET['media'] === 'updated'): ?>
        <div class="dashboard-media-notice success"><i class="fas fa-circle-check"></i> Dashboard visual updated successfully.</div>
    <?php elseif (isset($_GET['media']) && $_GET['media'] === 'removed'): ?>
        <div class="dashboard-media-notice success"><i class="fas fa-circle-check"></i> Dashboard visual removed.</div>
    <?php elseif (isset($_GET['media_error'])): ?>
        <div class="dashboard-media-notice error"><i class="fas fa-circle-exclamation"></i><?php echo esc((string)$_GET['media_error']); ?></div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:var(--panel-white);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow)">
        <a href="admin_dashboard.php" style="font-size:12px;font-weight:800;color:var(--blue)"><i class="fas fa-house"></i> Overview</a>
        <a href="sales_report.php" style="font-size:12px;color:#66727e"><i class="fas fa-chart-line"></i> Sales</a>
        <a href="today_transactions.php" style="font-size:12px;color:#66727e"><i class="fas fa-receipt"></i> Transactions</a>
        <a href="online_orders.php" style="font-size:12px;color:#66727e"><i class="fas fa-bag-shopping"></i> Online Orders</a>
        <a href="pharmacy_stock.php" style="font-size:12px;color:#66727e"><i class="fas fa-boxes-stacked"></i> Inventory</a>
        <a href="customers.php" style="font-size:12px;color:#66727e"><i class="fas fa-users"></i> Customers</a>
        <a href="suppliers.php" style="font-size:12px;color:#66727e"><i class="fas fa-truck"></i> Suppliers</a>
    </div>

    <div class="page-head">
        <div>
            <h1>Business Intelligence</h1>
            <p>Centralized sales, customer, inventory and branch performance for <?php echo htmlspecialchars($pharmacy_name); ?>.</p>
        </div>
        <div class="head-actions">
            <button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print / Export</button>
            <button class="btn primary" onclick="location.reload()"><i class="fas fa-arrows-rotate"></i> Sync Data</button>
        </div>
    </div>

    <div class="kpis">
        <div class="kpi">
            <div class="kpi-head"><span>Total Revenue</span><span class="kpi-icon"><i class="fas fa-coins"></i></span></div>
            <div class="kpi-value">K<?php echo number_format((float)$total_sales,2); ?></div>
            <div class="kpi-sub">All recorded sales</div>
        </div>
        <div class="kpi green">
            <div class="kpi-head"><span>Transactions</span><span class="kpi-icon"><i class="fas fa-receipt"></i></span></div>
            <div class="kpi-value"><?php echo number_format((int)$total_orders); ?></div>
            <div class="kpi-sub">Completed POS sales</div>
        </div>
        <div class="kpi yellow">
            <div class="kpi-head"><span>Average Ticket</span><span class="kpi-icon"><i class="fas fa-calculator"></i></span></div>
            <div class="kpi-value">K<?php echo number_format((float)$avg,2); ?></div>
            <div class="kpi-sub">Revenue per transaction</div>
        </div>
        <div class="kpi purple">
            <div class="kpi-head"><span>Active Branches</span><span class="kpi-icon"><i class="fas fa-store"></i></span></div>
            <div class="kpi-value"><?php echo (int)$branch_count; ?></div>
            <div class="kpi-sub">Operational locations</div>
        </div>
    </div>

    <div class="core">

        <section class="reference-panel">
            <div class="panel-top">
                <b>Central POS Intelligence</b>
                <span><?php echo date('d M Y'); ?> Â· Live database view</span>
            </div>

            <div class="hero">
                <div class="hero-info">
                    <div class="eyebrow">Executive overview</div>
                    <h2><?php echo htmlspecialchars($pharmacy_name); ?></h2>
                    <p>
                        A single control surface for monitoring the pharmacy network,
                        sales performance, transaction activity, branches and payment mix.
                    </p>

                    <div class="hero-stats">
                        <div class="hero-stat">
                            <b>K<?php echo number_format((float)$total_sales,0); ?></b>
                            <span>Revenue</span>
                        </div>
                        <div class="hero-stat">
                            <b><?php echo number_format((int)$total_orders); ?></b>
                            <span>Transactions</span>
                        </div>
                        <div class="hero-stat">
                            <b><?php echo (int)$branch_count; ?></b>
                            <span>Branches</span>
                        </div>
                        <div class="hero-stat">
                            <b>K<?php echo number_format((float)$avg,0); ?></b>
                            <span>Avg. ticket</span>
                        </div>
                    </div>
                </div>

                <div class="image-box">
                    <?php if ($dashboard_media_file): ?>
                        <?php $dashboard_ext = strtolower(pathinfo($dashboard_media_file, PATHINFO_EXTENSION)); ?>
                        <?php if (in_array($dashboard_ext, ['mp4','webm'], true)): ?>
                            <video class="dashboard-media" autoplay muted loop playsinline preload="metadata">
                                <source src="<?php echo esc($dashboard_media_url); ?>" type="<?php echo $dashboard_ext === 'mp4' ? 'video/mp4' : 'video/webm'; ?>">
                            </video>
                        <?php else: ?>
                            <img class="dashboard-media" src="<?php echo esc($dashboard_media_url); ?>" alt="EchoTech POS dashboard visual">
                        <?php endif; ?>

                        <div class="image-label">
                            <b>POS Command View</b>
                            <span>Admin-managed dashboard visual</span>
                        </div>
                    <?php else: ?>
                        <div class="image-empty">
                            <div class="image-empty-inner">
                                <i class="fas fa-image"></i>
                                <b>ADMIN VISUAL AREA</b>
                                <span>Upload a photo, GIF or looping video<br>to personalize this dashboard area.</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($user_role === 'Admin'): ?>
                        <div class="dashboard-media-tools">
                            <form method="post" enctype="multipart/form-data" class="dashboard-media-upload">
                                <input type="hidden" name="dashboard_media_action" value="upload">
                                <label class="media-action-btn" title="Change dashboard photo or animation">
                                    <i class="fas fa-image"></i>
                                    <span><?php echo $dashboard_media_file ? 'Change Media' : 'Add Media'; ?></span>
                                    <input
                                        type="file"
                                        name="dashboard_media"
                                        accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm"
                                        onchange="this.form.submit()"
                                    >
                                </label>
                            </form>

                            <?php if ($dashboard_media_file): ?>
                                <form method="post" onsubmit="return confirm('Remove the dashboard visual?');">
                                    <input type="hidden" name="dashboard_media_action" value="remove">
                                    <button type="submit" class="media-action-btn danger">
                                        <i class="fas fa-trash"></i>
                                        <span>Remove</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <aside class="side-analytics">

            <div class="donut-card">
                <div class="donut-head"><b>Payment Mix</b><span>Transactions</span></div>
                <div class="donut-wrap">
                    <canvas id="paymentChart"></canvas>
                    <div class="donut-center"><b><?php echo number_format((int)$total_orders); ?></b><span>Sales</span></div>
                </div>
                <div class="legend" id="paymentLegend"></div>
            </div>

            <div class="donut-card">
                <div class="donut-head"><b>Branch Share</b><span>Revenue</span></div>
                <div class="donut-wrap">
                    <canvas id="branchDonut"></canvas>
                    <div class="donut-center"><b><?php echo (int)$branch_count; ?></b><span>Branches</span></div>
                </div>
                <div class="legend" id="branchLegend"></div>
            </div>

        </aside>
    </div>

    <div class="four">

        <div class="small-card">
            <div class="small-head"><b>Revenue Trend</b><i class="fas fa-chart-line"></i></div>
            <div class="small-chart"><canvas id="sparkRevenue"></canvas></div>
            <div class="small-foot"><span>Sales movement</span><b>K<?php echo number_format((float)$total_sales,0); ?></b></div>
        </div>

        <div class="small-card">
            <div class="small-head"><b>Transaction Trend</b><i class="fas fa-arrow-trend-up"></i></div>
            <div class="small-chart"><canvas id="sparkTransactions"></canvas></div>
            <div class="small-foot"><span>Recorded sales</span><b><?php echo number_format((int)$total_orders); ?></b></div>
        </div>

        <div class="small-card">
            <div class="small-head"><b>Category Mix</b><i class="fas fa-layer-group"></i></div>
            <div class="small-chart"><canvas id="categoryChart"></canvas></div>
            <div class="small-foot"><span>Inventory categories</span><b><?php echo number_format((int)$category_total); ?></b></div>
        </div>

        <div class="small-card">
            <div class="small-head"><b>Inventory Health</b><i class="fas fa-boxes-stacked"></i></div>
            <div class="small-chart"><canvas id="stockChart"></canvas></div>
            <div class="small-foot"><span>Available items</span><b><?php echo number_format((int)$stock_total); ?></b></div>
        </div>

    </div>

    <div class="lower">

        <section class="chart-panel">
            <div class="panel-top">
                <div>
                    <b>Sales Performance</b>
                    <span style="display:block;margin-top:3px">Revenue trend across the pharmacy network</span>
                </div>
                <span>Weekly view</span>
            </div>
            <div class="chart-body"><canvas id="salesChart"></canvas></div>
        </section>

        <section class="chart-panel">
            <div class="panel-top">
                <div>
                    <b>System Activity</b>
                    <span style="display:block;margin-top:3px">Operational intelligence</span>
                </div>
                <span>LIVE</span>
            </div>
            <div class="activity">
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="activity-copy"><b>Revenue consolidated</b><span>All active branches</span></div>
                    <div class="activity-value">K<?php echo number_format((float)$total_sales,0); ?></div>
                </div>
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-cart-shopping"></i></div>
                    <div class="activity-copy"><b>Transactions recorded</b><span>POS sales in database</span></div>
                    <div class="activity-value"><?php echo number_format((int)$total_orders); ?></div>
                </div>
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-store"></i></div>
                    <div class="activity-copy"><b>Active locations</b><span>Operational branches</span></div>
                    <div class="activity-value"><?php echo (int)$branch_count; ?></div>
                </div>
                <div class="activity-row">
                    <div class="activity-icon"><i class="fas fa-database"></i></div>
                    <div class="activity-copy"><b>Database connection</b><span>EchoTech POS data source</span></div>
                    <div class="activity-value" style="color:var(--green)">ONLINE</div>
                </div>
            </div>
        </section>
    </div>

    <div class="bottom-grid">

        <section class="list-panel">
            <div class="panel-top">
                <div><b>Top Products</b><span style="display:block;margin-top:3px">Highest sales value</span></div>
                <span>TOP 5</span>
            </div>
            <div class="list">
                <?php if (!empty($top_products)): ?>
                    <?php foreach ($top_products as $i => $product): ?>
                    <div class="list-row">
                        <div class="rank"><?php echo $i + 1; ?></div>
                        <div class="list-copy">
                            <b><?php echo htmlspecialchars($product['name']); ?></b>
                            <span><?php echo number_format((int)$product['qty']); ?> units sold</span>
                        </div>
                        <div class="list-value">
                            <b>K<?php echo number_format((float)$product['revenue'],2); ?></b>
                            <span>revenue</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="list-row"><div class="list-copy"><b>No product sales data</b><span>Sales product details will appear here.</span></div></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="list-panel">
            <div class="panel-top">
                <div><b>Network Status</b><span style="display:block;margin-top:3px">Core POS services</span></div>
                <span>HEALTH</span>
            </div>
            <div class="list" style="padding-top:7px">
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Database</div><b style="color:var(--green)">Operational</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Sales processing</div><b style="color:var(--green)">Operational</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Inventory</div><b style="color:var(--green)">Operational</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot"></span>Branch network</div><b style="color:var(--green)"><?php echo (int)$branch_count; ?> Active</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot warn"></span>Review required</div><b style="color:var(--yellow)"><?php echo number_format((int)$low_stock_count); ?> low stock</b></div>
                <div class="status-row"><div class="status-left"><span class="status-dot red"></span>Expired products</div><b style="color:var(--red)"><?php echo number_format((int)$expired_count); ?></b></div>
            </div>
        </section>

    </div>

</section>
</main>
</div>

<script>
const paymentLabels = <?php echo json_encode($p_labels, JSON_UNESCAPED_UNICODE); ?>;
const paymentData = <?php echo json_encode($p_counts); ?>;
const branchLabels = <?php echo json_encode($b_names, JSON_UNESCAPED_UNICODE); ?>;
const branchData = <?php echo json_encode($b_revenues); ?>;
const trendLabels = <?php echo json_encode($trend_labels, JSON_UNESCAPED_UNICODE); ?>;
const trendRevenue = <?php echo json_encode($trend_revenue); ?>;
const trendTransactions = <?php echo json_encode($trend_transactions); ?>;
const categoryLabels = <?php echo json_encode($category_labels, JSON_UNESCAPED_UNICODE); ?>;
const categoryData = <?php echo json_encode($category_data); ?>;
const stockLabels = <?php echo json_encode($stock_labels, JSON_UNESCAPED_UNICODE); ?>;
const stockData = <?php echo json_encode($stock_data); ?>;

Chart.defaults.font.family='Inter,Arial,sans-serif';
Chart.defaults.color='#728095';
Chart.defaults.animation.duration=650;

const palette=['#377dff','#22c7ef','#31cf98','#f5bd45','#9a82ff','#ed617b','#7b8da8'];

function donut(id,labels,data,legendId){
    const el=document.getElementById(id); if(!el)return;
    const safeLabels=labels.length?labels:['No data'];
    const safeData=data.length?data:[1];
    new Chart(el,{
        type:'doughnut',
        data:{labels:safeLabels,datasets:[{
            data:safeData,
            backgroundColor:safeLabels.map((_,i)=>palette[i%palette.length]),
            borderWidth:0,
            spacing:2
        }]},
        options:{responsive:true,maintainAspectRatio:false,cutout:'73%',
            plugins:{legend:{display:false},tooltip:{backgroundColor:'#090e16',borderColor:'#2a3647',borderWidth:1}}}
    });
    const box=document.getElementById(legendId);
    if(box){
        box.innerHTML=safeLabels.slice(0,6).map((l,i)=>
            `<div class="legend-item"><span class="dot" style="background:${palette[i%palette.length]}"></span>${String(l).replace(/</g,'&lt;')}</div>`
        ).join('');
    }
}

donut('paymentChart',paymentLabels,paymentData,'paymentLegend');
donut('branchDonut',branchLabels,branchData,'branchLegend');

function spark(id,data){
    const el=document.getElementById(id); if(!el)return;
    const values=data.length?data:[0];
    new Chart(el,{type:'line',
        data:{labels:values.map((_,i)=>i+1),datasets:[{data:values,borderColor:'#377dff',backgroundColor:'rgba(55,125,255,.08)',fill:true,tension:.42,pointRadius:0,borderWidth:2}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}},
            scales:{x:{display:false},y:{display:false}},elements:{line:{capBezierPoints:true}}}
    });
}
spark('sparkRevenue',trendRevenue);
spark('sparkTransactions',trendTransactions);

const cat=document.getElementById('categoryChart');
if(cat){
    new Chart(cat,{type:'bar',data:{labels:categoryLabels.length?categoryLabels:['No data'],datasets:[{data:categoryData.length?categoryData:[0],backgroundColor:'#31cf98',borderRadius:4,maxBarThickness:16}]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:true}},scales:{x:{display:false},y:{display:false}}}});
}

const stock=document.getElementById('stockChart');
if(stock){
    new Chart(stock,{type:'doughnut',data:{labels:stockLabels,datasets:[{data:stockData,backgroundColor:['#31cf98','#f5bd45','#ed617b'],borderWidth:0}]},
    options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{display:false}}}});
}

const sales=document.getElementById('salesChart');
if(sales){
    new Chart(sales,{type:'line',data:{
        labels:trendLabels.length?trendLabels:['No data'],
        datasets:[
            {label:'Revenue',data:trendRevenue.length?trendRevenue:[0],borderColor:'#377dff',backgroundColor:'rgba(55,125,255,.09)',fill:true,tension:.4,pointRadius:3,pointHoverRadius:5,borderWidth:2},
            {label:'Transactions',data:trendTransactions.length?trendTransactions:[0],borderColor:'#f5bd45',backgroundColor:'transparent',tension:.4,pointRadius:2,pointHoverRadius:4,borderWidth:2,yAxisID:'y1'}
        ]},
        options:{responsive:true,maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{labels:{color:'#8996a8',font:{size:8},boxWidth:8,boxHeight:8}},tooltip:{backgroundColor:'#090e16',borderColor:'#2a3647',borderWidth:1}},
            scales:{
                x:{grid:{display:false},ticks:{color:'#69778a',font:{size:7}},border:{display:false}},
                y:{beginAtZero:true,grid:{color:'rgba(115,128,148,.12)'},ticks:{color:'#69778a',font:{size:7},callback:v=>'K'+v},border:{display:false}},
                y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{color:'#69778a',font:{size:7}},border:{display:false}}
            }
        }
    });
}
});
</script>
</body>
</html>
