<?php
session_start();
// Path corrected to reach includes from the /api folder
require "../includes/conn.php"; 

/**
 * ECHO PRIME MULTI-TENANCY PAYMENT ROUTER & INVENTORY SYNC
 * 1. Logs Order 
 * 2. Deducts Physical Stock from Database
 * 3. Redirects to Payment/WhatsApp
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

    // --- DATABASE OPERATIONS ---
    $order_ref = "EP-" . $branch_id . "-" . time(); 

    // A. Create the main order record
    $stmt_order = $conn->prepare("INSERT INTO orders (order_ref, branch_id, total_amount, payment_method, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt_order->bind_param("sids", $order_ref, $branch_id, $total_amount, $payment_method);
    $stmt_order->execute();
    $order_db_id = $conn->insert_id;

    // B. Save items AND REDUCE STOCK (Inventory Sync)
    foreach ($_SESSION['cart'] as $item_id => $details) {
        // Log the specific item sold
        $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, item_name, price, quantity) VALUES (?, ?, ?, ?)");
        $stmt_items->bind_param("isdi", $order_db_id, $details['name'], $details['price'], $details['qty']);
        $stmt_items->execute();

        // REDUCE STOCK: Subtracts sold quantity from the pharmacy_v1.store_items table
        $update_stock = $conn->prepare("UPDATE store_items SET quantity = quantity - ? WHERE id = ? AND branch_id = ?");
        $update_stock->bind_param("iii", $details['qty'], $item_id, $branch_id);
        $update_stock->execute();
    }
    // --- END DATABASE OPERATIONS ---

    // 4. ROUTING LOGIC (Using local paths inside /api/)
    switch ($payment_method) {
        case 'momo':
            // Logic for Mobile Money redirection
            $redirect_url = "payment_status.php?status=pending&ref=$order_ref&bid=$branch_id";
            break;

        case 'card':
            // Logic for Bank Card redirection
            $redirect_url = "payment_status.php?status=awaiting_card&ref=$order_ref&bid=$branch_id";
            break;

        case 'whatsapp':
            // Build the professional WhatsApp message
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
            $redirect_url = "https://wa.me/" . preg_replace('/[^0-9]/', '', $branch['phone']) . "?text=" . $msg;
            break;

        default:
            $redirect_url = "view_cart.php?bid=$branch_id&error=invalid_method";
            break;
    }
    
    // 5. Clean up Cart and Finalize Redirect
    unset($_SESSION['cart']); 
    session_write_close(); 
    header("Location: " . $redirect_url);
    exit();

} else {
    // If someone tries to access this file directly, send them back to the store
    header("Location: ../online_store.php");
    exit();
}
?>