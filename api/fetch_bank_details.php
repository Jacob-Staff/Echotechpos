<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Connection File Fallback
if (file_exists(__DIR__ . "/../includes/conn.php")) {
    require_once __DIR__ . "/../includes/conn.php";
} elseif (file_exists(__DIR__ . "/includes/conn.php")) {
    require_once __DIR__ . "/includes/conn.php";
} elseif (file_exists("includes/conn.php")) {
    require_once "includes/conn.php";
}

$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : ($_SESSION['current_branch_id'] ?? 0);
$client_id = $_SESSION['client_id'] ?? 0;

// 2. Fetch Client Payment Info
$client_pay_info = "Not Set";
if ($client_id > 0 && isset($conn)) {
    if ($c_stmt = $conn->prepare("SELECT payment_info FROM clients WHERE id = ?")) {
        $c_stmt->bind_param("i", $client_id);
        $c_stmt->execute();
        $res = $c_stmt->get_result()->fetch_assoc();
        if (!empty($res['payment_info'])) {
            $client_pay_info = $res['payment_info'];
        }
        $c_stmt->close();
    }
}

// 3. Fetch Branch Details & Parse Responsively
$bank_details = ['bank' => 'N/A', 'bcode' => 'N/A', 'acc_name' => 'N/A', 'acc_no' => 'N/A'];
$momo_details = ['mtn' => 'N/A', 'airtel' => 'N/A'];

if ($branch_id > 0 && isset($conn)) {
    if ($b_stmt = $conn->prepare("SELECT bank_details, mobile_money_details FROM branches WHERE id = ?")) {
        $b_stmt->bind_param("i", $branch_id);
        $b_stmt->execute();
        $b_res = $b_stmt->get_result()->fetch_assoc();

        if ($b_res) {
            // Safe parse bank string
            if (!empty($b_res['bank_details'])) {
                $parts = explode('|', $b_res['bank_details']);
                foreach ($parts as $p) {
                    $pair = explode(':', trim($p), 2);
                    if (count($pair) === 2) {
                        $key = strtolower(trim($pair[0]));
                        $val = trim($pair[1]);
                        if ($key === 'bank') $bank_details['bank'] = $val;
                        if ($key === 'bcode') $bank_details['bcode'] = $val;
                        if ($key === 'acc' || $key === 'acc_name') $bank_details['acc_name'] = $val;
                        if ($key === 'no' || $key === 'acc_no') $bank_details['acc_no'] = $val;
                    }
                }
            }
            // Safe parse momo string
            if (!empty($b_res['mobile_money_details'])) {
                $mParts = explode('|', $b_res['mobile_money_details']);
                foreach ($mParts as $mp) {
                    $pair = explode(':', trim($mp), 2);
                    if (count($pair) === 2) {
                        $key = strtolower(trim($pair[0]));
                        $val = trim($pair[1]);
                        if ($key === 'mtn') $momo_details['mtn'] = $val;
                        if ($key === 'airtel') $momo_details['airtel'] = $val;
                    }
                }
            }
        }
        $b_stmt->close();
    }
}
?>

<style>
    .payment-modal-container { background: #ffffff; color: #333; padding: 20px; border-radius: 12px; }
    .info-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 10px; padding: 15px; margin-bottom: 15px; }
    .detail-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; border-bottom: 1px solid #eeeeee; padding-bottom: 6px; }
    .detail-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    
    .label-text { color: #6c757d; font-weight: 700; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .value-text { color: #212529; font-weight: 600; font-size: 0.85rem; }
    .text-echo-teal { color: var(--echo-teal, #003339); }
    
    .section-title { font-size: 0.82rem; font-weight: 800; margin-bottom: 12px; display: flex; align-items: center; color: #495057; text-transform: uppercase; letter-spacing: 0.5px; }
    .copy-btn { cursor: pointer; border: none; background: transparent; padding: 0 4px; color: #00b386; transition: color 0.2s; }
    .copy-btn:hover { color: #003339; }
</style>

<div class="payment-modal-container">
    <!-- Active Payment Method -->
    <div class="mb-4">
        <label class="label-text mb-2 d-block">Your Active Payment Method</label>
        <div class="info-box border-start border-4 border-success bg-success-subtle mb-0">
            <div class="d-flex align-items-center">
                <i class="mdi mdi-check-circle text-success fs-3 me-3"></i>
                <div>
                    <span class="value-text d-block text-success-emphasis fw-bold"><?php echo htmlspecialchars($client_pay_info); ?></span>
                    <small class="text-muted d-block" style="font-size: 0.7rem;">Verified payment source for active profile</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Details -->
    <div class="section-title">
        <i class="mdi mdi-bank me-2 text-echo-teal fs-5"></i> Bank Transfer Details
    </div>
    
    <div class="info-box shadow-sm">
        <div class="detail-row">
            <span class="label-text">Bank Name</span>
            <span class="value-text"><?php echo htmlspecialchars($bank_details['bank']); ?></span>
        </div>
        <div class="detail-row">
            <span class="label-text">Branch Code</span>
            <span class="value-text fw-bold text-echo-teal"><?php echo htmlspecialchars($bank_details['bcode']); ?></span>
        </div>
        <div class="detail-row">
            <span class="label-text">Account Name</span>
            <span class="value-text"><?php echo htmlspecialchars($bank_details['acc_name']); ?></span>
        </div>
        <div class="detail-row">
            <span class="label-text">Account Number</span>
            <div class="d-flex align-items-center gap-1">
                <span class="value-text fw-bold" style="letter-spacing: 0.5px;"><?php echo htmlspecialchars($bank_details['acc_no']); ?></span>
                <?php if($bank_details['acc_no'] !== 'N/A'): ?>
                    <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($bank_details['acc_no']); ?>', this)" title="Copy Account Number">
                        <i class="mdi mdi-content-copy"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mobile Money Details -->
    <div class="section-title">
        <i class="mdi mdi-cellphone-text me-2 text-warning fs-5"></i> Mobile Money (Zambia)
    </div>

    <div class="row g-2">
        <div class="col-6">
            <div class="info-box text-center p-2 shadow-sm border-top border-warning border-3 mb-0">
                <span class="label-text d-block mb-1">MTN MoMo</span>
                <div class="d-flex justify-content-center align-items-center gap-1">
                    <span class="value-text"><?php echo htmlspecialchars($momo_details['mtn']); ?></span>
                    <?php if($momo_details['mtn'] !== 'N/A'): ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($momo_details['mtn']); ?>', this)">
                            <i class="mdi mdi-content-copy"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="info-box text-center p-2 shadow-sm border-top border-danger border-3 mb-0">
                <span class="label-text d-block mb-1">Airtel Money</span>
                <div class="d-flex justify-content-center align-items-center gap-1">
                    <span class="value-text"><?php echo htmlspecialchars($momo_details['airtel']); ?></span>
                    <?php if($momo_details['airtel'] !== 'N/A'): ?>
                        <button class="copy-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($momo_details['airtel']); ?>', this)">
                            <i class="mdi mdi-content-copy"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Box -->
    <div class="mt-3 p-3 rounded text-center border-0 bg-warning-subtle">
        <small class="text-dark d-block">
            <i class="mdi mdi-cloud-upload-outline me-1 text-warning-emphasis"></i> 
            <strong>Action Required:</strong> Please upload a screenshot of your <strong>Proof of Payment</strong> once the transfer is completed.
        </small>
    </div>
</div>

<script>
function copyToClipboard(text, btn) {
    if (!text || text === 'N/A') return;
    navigator.clipboard.writeText(text).then(function() {
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = 'mdi mdi-check text-success';
            setTimeout(() => { icon.className = 'mdi mdi-content-copy'; }, 2000);
        }
    });
}
</script>
