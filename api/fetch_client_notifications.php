<?php
session_start();
require_once("../includes/conn.php");

// 1. Check if the user is logged in using the ID from session
if(!isset($_SESSION['client_id'])) {
    echo '<div class="text-center py-3">Please login to view notifications.</div>';
    exit;
}

$client_id = $_SESSION['client_id'];

// 2. Get the email for this client ID to match help_inquiries table
$client_query = $conn->prepare("SELECT email FROM clients WHERE id = ?");
$client_query->bind_param("i", $client_id);
$client_query->execute();
$client_data = $client_query->get_result()->fetch_assoc();
$client_email = $client_data['email'] ?? '';

if(empty($client_email)) {
    echo '<div class="text-center py-3">User details not found.</div>';
    exit;
}

// 3. MARK AS READ: Update inquiries so the badge count clears
// This assumes you ran the SQL: ALTER TABLE help_inquiries ADD COLUMN is_read_by_client TINYINT(1) DEFAULT 0;
$update_read = $conn->prepare("UPDATE help_inquiries SET is_read_by_client = 1 WHERE client_email = ? AND status = 'Resolved'");
$update_read->bind_param("s", $client_email);
$update_read->execute();

// 4. Fetch inquiries using client_email
$stmt = $conn->prepare("SELECT subject, message, admin_reply, created_at 
                        FROM help_inquiries 
                        WHERE client_email = ? 
                        AND admin_reply IS NOT NULL 
                        AND admin_reply != '' 
                        ORDER BY created_at DESC");
$stmt->bind_param("s", $client_email);
$stmt->execute();
$res = $stmt->get_result();

if($res->num_rows == 0) {
    echo '<div class="text-center py-4 text-muted">
            <i class="mdi mdi-bell-off-outline fs-1"></i><br>
            No responses from the pharmacy yet.
          </div>';
} else {
    while($row = $res->fetch_assoc()) {
        ?>
        <div class="border-bottom mb-3 pb-3">
            <div class="d-flex justify-content-between align-items-start">
                <span class="badge bg-success mb-2"><?php echo htmlspecialchars($row['subject']); ?></span>
                <small class="text-muted" style="font-size: 10px;">
                    <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                </small>
            </div>
            
            <div class="p-2 rounded mb-2" style="background: #f8f9fa; border-left: 3px solid #dee2e6; font-size: 13px;">
                <strong class="text-muted" style="font-size: 10px;">YOUR MESSAGE:</strong><br>
                <?php echo htmlspecialchars($row['message']); ?>
            </div>
            
            <div class="p-2 rounded" style="background: #eef9f6; border-left: 3px solid #00b386; font-size: 13px;">
                <strong class="text-success" style="font-size: 10px;">PHARMACY REPLY:</strong><br>
                <?php echo htmlspecialchars($row['admin_reply']); ?>
            </div>
        </div>
        <?php
    }
}
?>