<?php
require "conn.php";

$response = [
    "stock" => 0,
    "sales" => 0
];

// Total Stock Value
$q1 = mysqli_query($conn, "SELECT quantity, unit_price FROM products");
if ($q1 && mysqli_num_rows($q1) > 0) {
    while ($row = mysqli_fetch_assoc($q1)) {
        $response["stock"] += ($row['quantity'] * $row['unit_price']);
    }
}

// Total Sales Value
$q2 = mysqli_query($conn, "SELECT total_price FROM sales");
if ($q2 && mysqli_num_rows($q2) > 0) {
    while ($row = mysqli_fetch_assoc($q2)) {
        $response["sales"] += $row['total_price'];
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>
