<?php
session_start();
require_once("../../includes/conn.php"); 

$pharmacy_id = $_SESSION['pharmacy_id'] ?? 0;
$branch_id   = $_SESSION['branch_id'] ?? 0;

// This line in fetch_help_messages.php was failing before the SQL fix
$stmt = $conn->prepare("
    SELECT id, client_name, client_email, subject, message, created_at, status, admin_reply 
    FROM help_inquiries 
    WHERE pharmacy_id = ? AND branch_id = ? 
    ORDER BY status DESC, created_at DESC
");
$stmt->bind_param("ii", $pharmacy_id, $branch_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    echo '<div class="text-center p-4 text-muted">No client inquiries found.</div>';
    exit;
}

echo '<div class="list-group list-group-flush">';
while ($row = $res->fetch_assoc()) {
    $id = $row['id'];
    $is_pending = ($row['status'] === 'Pending');
    $status_class = $is_pending ? 'bg-warning text-dark' : 'bg-success';
    
    echo '<div class="list-group-item border-bottom py-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <h6 class="fw-bold mb-0">'.htmlspecialchars($row['client_name']).'</h6>
                    <small class="text-primary">'.htmlspecialchars($row['client_email']).'</small>
                </div>
                <span class="badge '.$status_class.'">'.htmlspecialchars($row['status']).'</span>
            </div>
            
            <div class="bg-light p-2 rounded mb-2" style="font-size: 0.9rem;">
                <strong class="d-block mb-1">Subject: '.htmlspecialchars($row['subject']).'</strong>
                '.nl2br(htmlspecialchars($row['message'])).'
            </div>';

    if (!$is_pending && !empty($row['admin_reply'])) {
        echo '<div class="ms-4 p-2 border-start border-4 border-success bg-white mb-2" style="font-size: 0.85rem;">
                <b class="text-success small">Your Response:</b><br>
                '.nl2br(htmlspecialchars($row['admin_reply'])).'
              </div>';
    }

    if ($is_pending) {
        echo '<div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary" onclick="$(\'#replyForm'.$id.'\').toggle()">
                    <i class="fas fa-reply me-1"></i> Reply
                </button>
                <button class="btn btn-sm btn-outline-success" onclick="resolveInquiry('.$id.')">
                    Mark Resolved
                </button>
              </div>
              
              <div id="replyForm'.$id.'" style="display:none;" class="mt-3 p-3 border rounded bg-white">
                <label class="form-label small fw-bold">Message to Client:</label>
                <textarea id="replyText'.$id.'" class="form-control mb-2" rows="3" placeholder="Type your response..."></textarea>
                <button class="btn btn-success btn-sm" onclick="sendReply('.$id.')">Send Response</button>
                <button class="btn btn-link btn-sm text-muted" onclick="$(\'#replyForm'.$id.'\').hide()">Cancel</button>
              </div>';
    }
    
    echo '<div class="text-muted mt-2" style="font-size:10px;">Received: '.date('d M Y, H:i', strtotime($row['created_at'])).'</div>
    </div>';
}
echo '</div>';
?>