<?php
session_start();
require_once '../../includes/conn.php'; 

$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($branch_id == 0) die("Invalid Branch ID");

// 1. Fetch Branch Name for the Filename
$branch_q = $conn->query("SELECT branch_name FROM branches WHERE id = $branch_id");
$branch_data = $branch_q->fetch_assoc();
$branch_name = $branch_data['branch_name'] ?? 'Branch';

// Set Dates (Adjust these variables if you use a POST/GET date picker)
$start = date('Y-m-01 00:00:00');
$end = date('Y-m-t 23:59:59');

/**
 * 2. Optimized Query for pharmacy_v1
 * JOIN users: to get the seller's username
 * Subquery on sales_items & store_items: to list the specific drugs sold in that invoice
 */
$query = "SELECT 
            s.id,
            s.sale_date, 
            u.username as seller_name, 
            s.payment_method, 
            s.total_amount,
            (SELECT GROUP_CONCAT(si.item_name SEPARATOR ', ') 
             FROM sales_items sli 
             JOIN store_items si ON sli.product_id = si.id 
             WHERE sli.sale_id = s.id) as drugs_sold
          FROM sales s
          LEFT JOIN users u ON s.user_id = u.id
          WHERE s.branch_id = $branch_id 
          AND s.sale_date BETWEEN '$start' AND '$end' 
          ORDER BY s.sale_date DESC";

$result = $conn->query($query);

// 3. Clean filename (Using the Branch Name as requested)
$safe_branch_name = str_replace(' ', '_', $branch_name);
$filename = "Report_" . $safe_branch_name . "_" . date('d_M_Y') . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Column Titles
fputcsv($output, ['Date/Time', 'Seller Name', 'Drugs Sold', 'Total Amount (K)', 'Payment Method']);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['sale_date'], 
            $row['seller_name'] ?? 'N/A', 
            $row['drugs_sold'] ?? 'No Items Found', 
            number_format($row['total_amount'], 2), 
            $row['payment_method']
        ]);
    }
}

fclose($output);
exit();