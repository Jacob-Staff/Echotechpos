<?php
session_start();
require_once("../includes/conn.php");

$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 0;
$client_id = $_SESSION['client_id'] ?? 0;

// 1. Get Client's Active Payment Method
$client_pay_info = "Not Set";
if ($client_id > 0) {
    $c_stmt = $conn->prepare("SELECT payment_info FROM clients WHERE id = ?");
    $c_stmt->bind_param("i", $client_id);
    $c_stmt->execute();
    $res = $c_stmt->get_result()->fetch_assoc();
    $client_pay_info = !empty($res['payment_info']) ? $res['payment_info'] : "No payment info saved in profile.";
}

// 2. Get Specific Branch Payment Details
$bank_details = ['bank' => 'N/A', 'bcode' => 'N/A', 'acc_name' => 'N/A', 'acc_no' => 'N/A'];
$momo_details = ['mtn' => 'N/A', 'airtel' => 'N/A'];

if ($branch_id > 0) {
    $b_stmt = $conn->prepare("SELECT bank_details, mobile_money_details FROM branches WHERE id = ?");
    $b_stmt->bind_param("i", $branch_id);
    $b_stmt->execute();
    $b_res = $b_stmt->get_result()->fetch_assoc();

    if ($b_res) {
        if (!empty($b_res['bank_details'])) {
            $parts = explode(' | ', $b_res['bank_details']);
            foreach ($parts as $p) {
                if (strpos($p, 'Bank:') !== false) $bank_details['bank'] = trim(explode(':', $p)[1]);
                if (strpos($p, 'BCode:') !== false) $bank_details['bcode'] = trim(explode(':', $p)[1]);
                if (strpos($p, 'Acc:') !== false) $bank_details['acc_name'] = trim(explode(':', $p)[1]);
                if (strpos($p, 'No:') !== false) $bank_details['acc_no'] = trim(explode(':', $p)[1]);
            }
        }
        if (!empty($b_res['mobile_money_details'])) {
            $mParts = explode(' | ', $b_res['mobile_money_details']);
            foreach ($mParts as $mp) {
                if (strpos($mp, 'MTN:') !== false) $momo_details['mtn'] = trim(explode(':', $mp)[1]);
                if (strpos($mp, 'Airtel:') !== false) $momo_details['airtel'] = trim(explode(':', $mp)[1]);
            }
        }
    }
}
?>

<style>
    .payment-container { background: #ffffff; color: #333; padding: 20px; border-radius: 12px; border: 1px solid #e0e0e0; }
    .info-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 10px; padding: 15px; margin-bottom: 15px; }
    .detail-row { display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid #eeeeee; padding-bottom: 5px; }
    .detail-row:last-child { border-bottom: none; }
    
    /* Theme Accents */
    .label-text { color: #6c757d; font-weight: 700; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .value-text { color: #212529; font-weight: 600; font-size: 0.85rem; }
    .text-custom-cyan { color: #00acc1; } /* Clean Cyan for Light Theme */
    
    .section-title { font-size: 0.85rem; font-weight: 800; margin-bottom: 12px; display: flex; align-items: center; color: #495057; }
</style>

<div class="payment-container">
    <div class="mb-4">
        <label class="label-text mb-2 d-block">Your Active Payment Method</label>
        <div class="info-box border-start border-4 border-success bg-success-subtle">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle text-success fs-4 me-3"></i>
                <div>
                    <span class="value-text d-block text-success"><?php echo htmlspecialchars($client_pay_info); ?></span>
                    <small class="text-muted" style="font-size: 0.7rem;">Verified payment source for this order</small>
                </div>
            </div>
        </div>
    </div>

    <hr class="my-4" style="opacity: 0.1;">

    <div class="section-title">
        <i class="fas fa-university me-2 text-custom-cyan"></i> BANK TRANSFER DETAILS
    </div>
    
    <div class="info-box shadow-sm">
        <div class="detail-row">
            <span class="label-text">Bank Name</span>
            <span class="value-text"><?php echo htmlspecialchars($bank_details['bank']); ?></span>
        </div>
        <div class="detail-row">
            <span class="label-text">Branch Code</span>
            <span class="value-text fw-bold text-custom-cyan"><?php echo htmlspecialchars($bank_details['bcode']); ?></span>
        </div>
        <div class="detail-row">
            <span class="label-text">Account Name</span>
            <span class="value-text"><?php echo htmlspecialchars($bank_details['acc_name']); ?></span>
        </div>
        <div class="detail-row">
            <span class="label-text">Account Number</span>
            <span class="value-text fw-bold" style="letter-spacing: 1px; color: #000;"><?php echo htmlspecialchars($bank_details['acc_no']); ?></span>
        </div>
    </div>

    <div class="section-title">
        <i class="fas fa-mobile-alt me-2 text-warning"></i> MOBILE MONEY (ZAMBIA)
    </div>

    <div class="row g-2">
        <div class="col-6">
            <div class="info-box text-center p-2 shadow-sm border-top border-warning border-3">
                <span class="label-text d-block mb-1">MTN Money</span>
                <span class="value-text"><?php echo htmlspecialchars($momo_details['mtn']); ?></span>
            </div>
        </div>
        <div class="col-6">
            <div class="info-box text-center p-2 shadow-sm border-top border-danger border-3">
                <span class="label-text d-block mb-1">Airtel Money</span>
                <span class="value-text"><?php echo htmlspecialchars($momo_details['airtel']); ?></span>
            </div>
        </div>
    </div>

    <div class="mt-3 p-3 rounded text-center border-0 bg-warning-subtle">
        <small class="text-dark">
            <i class="fas fa-cloud-upload-alt me-1 text-warning"></i> 
            <strong>Action Required:</strong> Please upload a clear screenshot of your <strong>Proof of Payment</strong> once the transfer is complete.
        </small>
    </div>
</div>