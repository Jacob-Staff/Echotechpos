<?php
// Set up error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure the connection file is included
// Path is relative from dashboard/ to includes/conn.php
require "../includes/conn.php"; 

if (!$conn) {
    die("Database connection failed. Please ensure MySQL is running and includes/conn.php is correct.");
}

echo "<h2>Jacobs PHARMACY Database Setup</h2>";
echo "<p>Using Database: <b>pharmacy_v1</b></p><hr>";

// --- 0. CRITICAL: Disable Foreign Key Checks for clean table drop/recreation ---
if (!mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0;")) {
    echo "<span style='color:red;'>❌ WARNING: Could not disable foreign key checks. Errors may follow.</span><br>";
}

// --- SQL Statements to Create Tables ---

// 1. Table for Pharmacy Configuration (info) - NO CHANGE
$sql_create_info = "
CREATE TABLE IF NOT EXISTS info (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Pharmacy Name',
    address VARCHAR(255),
    phone_no VARCHAR(50),
    email VARCHAR(100) UNIQUE,
    setup_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// 2. Table for Stocked Pharmacy Items (store_items) - NO CHANGE
$sql_create_store_items = "
CREATE TABLE IF NOT EXISTS store_items (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL COMMENT 'Unit selling price',
    category VARCHAR(100) COMMENT 'Category, e.g., Analgesics, Antibiotics',
    quantity INT(11) NOT NULL DEFAULT 0 COMMENT 'Current stock quantity',
    expiry_date DATE NOT NULL,
    manufacturer VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// 3. Table for Sales/Transactions HEADER (sales) - NEW/CORRECTED STRUCTURE
// This table holds transaction-level details (who, when, how much).
$sql_create_sales = "
CREATE TABLE IF NOT EXISTS sales (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice VARCHAR(50) NOT NULL UNIQUE, 
    total DECIMAL(10, 2) NOT NULL COMMENT 'Final total price including tax',
    payment VARCHAR(50) NOT NULL COMMENT 'Payment method (Cash, Credit, etc.)',
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

// 4. Table for Sales/Transaction DETAILS (sales_items) - NEW TABLE
// This table holds the individual products sold in each transaction.
$sql_create_sales_items = "
CREATE TABLE IF NOT EXISTS sales_items (
    id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT(11) UNSIGNED NOT NULL COMMENT 'Links to sales(id)',
    product_id INT(11) UNSIGNED NOT NULL COMMENT 'Links to store_items(id)',
    quantity INT(11) NOT NULL,
    price DECIMAL(10, 2) NOT NULL COMMENT 'Price per unit at time of sale',
    
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES store_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";


// ----------------------------------------------------
// Execution Block
// ----------------------------------------------------

// Define the order of drops and creations (ensure dependent tables are dropped first)
$tables_to_create = [
    // Drop the problematic tables first, now that checks are disabled
    'sales_items_drop' => "DROP TABLE IF EXISTS sales_items",
    'sales_drop' => "DROP TABLE IF EXISTS sales",
    
    // Create the core tables
    'info' => $sql_create_info, 
    'store_items' => $sql_create_store_items,
    
    // Create the corrected sales structure
    'sales' => $sql_create_sales,
    'sales_items' => $sql_create_sales_items 
];

$success = true;

foreach ($tables_to_create as $table_name => $sql_query) {
    // Skip status messages for temporary drop queries
    if (strpos($table_name, 'drop') !== false) {
        mysqli_query($conn, $sql_query);
        continue;
    }
    
    echo "Attempting to create table: <b>{$table_name}</b>... ";
    
    if (mysqli_query($conn, $sql_query)) {
        echo "<span style='color:green;'>✅ SUCCESS</span><br>";
    } else {
        echo "<span style='color:red;'>❌ ERROR:</span> " . mysqli_error($conn) . "<br>";
        $success = false;
    }
}

// --- CRITICAL: Re-enable Foreign Key Checks ---
if (!mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1;")) {
    echo "<span style='color:red;'>❌ WARNING: Failed to re-enable foreign key checks.</span><br>";
    $success = false;
}

if ($success) {
    echo "<hr><h3 style='color:green;'>🎉 Setup Complete!</h3>";
    echo "<p>All necessary tables are now created and ready for use (including the new, corrected sales structure). The setup is now robust against initial foreign key issues.</p>";
    echo "<p>You should now be able to process sales using the <b>process_sale.php</b> script.</p>";
} else {
    echo "<hr><h3 style='color:red;'>⚠️ Setup Failed</h3>";
    echo "<p>Review the errors above.</p>";
}

mysqli_close($conn);
?>
