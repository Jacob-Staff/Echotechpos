<?php
session_start();
require "conn.php"; 

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['stock_file'])) {
    $pharmacy_id = (int)$_SESSION['pharmacy_id'];
    $branch_id = (int)$_SESSION['branch_id'];
    
    $file = $_FILES['stock_file']['tmp_name'];
    
    // 1. AUTO-DETECT DELIMITER (Comma vs Tab)
    $file_content = file_get_contents($file);
    $delimiter = (strpos($file_content, "\t") !== false) ? "\t" : ",";
    
    $handle = fopen($file, "r");
    
    // Skip the header row
    fgetcsv($handle, 1000, $delimiter);
    
    $count = 0;
    while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
        
        // Skip if the row is empty or first column is missing
        if (!isset($data[0]) || trim($data[0]) == "") {
            continue; 
        }

        // 2. EXTRACT DATA SAFELY
        $raw_name = trim($data[0]);
        $strength = isset($data[1]) ? trim($data[1]) : '';
        
        // Combine Name and Strength for the Pharmacy Stock table
        $item_name = trim($raw_name . " " . $strength);
        
        $cost     = isset($data[2]) ? (float)str_replace(',', '', $data[2]) : 0.00;
        $price    = isset($data[3]) ? (float)str_replace(',', '', $data[3]) : 0.00;
        $quantity = isset($data[4]) ? (int)$data[4] : 0;
        $category = !empty($data[5]) ? trim($data[5]) : 'Medicine';
        $barcode  = isset($data[6]) ? trim($data[6]) : '';
        
        // Date Conversion (DD/MM/YYYY to YYYY-MM-DD)
        $expiry_date = '0000-00-00';
        if (!empty($data[7])) {
            $date_raw = trim($data[7]);
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_raw);
            if ($date_obj) { 
                $expiry_date = $date_obj->format('Y-m-d'); 
            }
        }

        // 3. DATABASE INSERT
        $sql = "INSERT INTO store_items (
                    pharmacy_id, branch_id, item_name, strength, cost, 
                    price, quantity, category, barcode, expiry_date, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iissddisss", 
            $pharmacy_id, 
            $branch_id, 
            $item_name, 
            $strength, 
            $cost, 
            $price, 
            $quantity, 
            $category, 
            $barcode, 
            $expiry_date
        );
        
        if ($stmt->execute()) {
            $count++;
        }
        $stmt->close();
    }
    
    fclose($handle);
    
    // Redirect with count
    header("Location: ../dashboard/update_items_stock.php?status=success&count=$count");
    exit();
}