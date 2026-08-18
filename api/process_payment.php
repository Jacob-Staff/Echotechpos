<?php
session_start();
// Go up one level to reach the includes folder
require "../includes/conn.php";

/**
 * ECHO PRIME MULTI-TENANCY PAYMENT ROUTER
 * Handles Database logging and redirects to Payment/WhatsApp channels.
 */

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Capture POST Data
    $branch_id = isset($_POST['branch']) ? intval($_POST['branch']) : 10;
    $total_amount = isset($_POST['total']) ? floatval($_POST['total']) : 0.00;
    $payment_method = isset($_POST['payment_type']) ? $_POST['payment_type'] : 'whatsapp';
    $momo_number = isset($_POST['momo_number']) ? $_POST['momo_number'] : '';

    // 2. Security Check: Ensure Cart is not empty
    if (empty($_SESSION['cart'])) {
        header("Location: view_cart.php?bid=$branch_id&error=empty_cart");
        exit();
    }

    // 3. Verify Branch and Get Pharmacy Name/Phone
    $stmt = $conn->prepare("SELECT b.*, p.name AS pharmacy_name FROM branches b JOIN pharmacies p ON b.pharmacy_id = p.id WHERE b.id = ?");
    $stmt->bind_param("i", $branch_id);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc();

    if (!$branch) {
        die("System Error: Invalid Branch Selection.");
    }

    // --- SAVE ORDER TO DATABASE ---
    // Generate a Unique Reference (e.g., EP-10-1709123456)
    $order_ref = "EP-" . $branch_id . "-" . time(); 

    // A. Save the main order record
    $stmt_order = $conn->prepare("INSERT INTO orders (order_ref, branch_id, total_amount, payment_method, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt_order->bind_param("sids", $order_ref, $branch_id, $total_amount, $payment_method);
    $stmt_order->execute();
    $order_db_id = $conn->insert_id; // Get the ID for the next step

    // B. Save each specific item from the session cart
    foreach ($_SESSION['cart'] as $item_id => $details) {
        $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, item_name, price, quantity) VALUES (?, ?, ?, ?)");
        $stmt_items->bind_param("isdi", $order_db_id, $details['name'], $details['price'], $details['qty']);
        $stmt_items->execute();
    }
    // --- END SAVE TO DATABASE ---

    // 4. ROUTING LOGIC
    switch ($payment_method) {
        case 'momo':
            /* FUTURE INTEGRATION: Call Flutterwave/Airtel/MTN API here using $momo_number 
            */
            header("Location: ../payment_status.php?status=pending&ref=$order_ref&bid=$branch_id");
            break;

        case 'card':
            /* FUTURE INTEGRATION: Redirect to Secure Credit Card Gateway 
            */
            header("Location: ../payment_status.php?status=awaiting_card&ref=$order_ref&bid=$branch_id");
            break;

        case 'whatsapp':
            // Build the professional WhatsApp order list
            $msg = "*NEW ORDER: " . $branch['pharmacy_name'] . "*%0A";
            $msg .= "*Ref:* " . $order_ref . "%0A";
            $msg .= "--------------------------%0A";
            
            foreach ($_SESSION['cart'] as $item) {
                $msg .= "• " . $item['name'] . " x" . $item['qty'] . " (K" . number_format($item['price'] * $item['qty'], 2) . ")%0A";
            }
            
            $msg .= "--------------------------%0A";
            $msg .= "*Total Payable: K" . number_format($total_amount, 2) . "*%0A%0A";
            $msg .= "Please confirm availability for delivery.";

            // Redirect to the specific branch phone number
            $wa_url = "https://wa.me/" . preg_replace('/[^0-9]/', '', $branch['phone']) . "?text=" . $msg;
            header("Location: " . $wa_url);
            break;

        default:
            // Since view_cart.php is in the same folder as this file
            header("Location: view_cart.php?bid=$branch_id&error=invalid_method");
            break;
    }
    
    // 5. Clean up the cart so the user doesn't double-order
    // (Optional: You can uncomment this if you want the cart cleared immediately)
    // unset($_SESSION['cart']); 
    exit();
} else {
    header("Location: ../online_store.php");
    exit();
}