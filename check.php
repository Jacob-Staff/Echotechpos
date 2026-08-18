<?php
// Direct connection to avoid 'require' errors
$host = "localhost";
$user = "root";
$pass = "";
$db   = "pharmacy_v1"; 

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("<h2 style='color:red'>Connection Failed: " . $conn->connect_error . "</h2>");
}

echo "<h2 style='font-family:sans-serif;'>Inspecting Database: <span style='color:green;'>$db</span></h2>";

// Get all tables in your database
$tables = $conn->query("SHOW TABLES");

while ($tableRow = $tables->fetch_array()) {
    $tableName = $tableRow[0];
    echo "<div style='font-family:sans-serif; background:#f9f9f9; padding:15px; margin-bottom:20px; border:1px solid #ccc; border-radius:8px;'>";
    echo "<h3 style='margin-top:0;'>Table: <span style='color:blue;'>$tableName</span></h3>";

    // Get columns
    $columns = $conn->query("SHOW COLUMNS FROM `$tableName`");
    echo "<strong>Columns:</strong> ";
    $cols_list = [];
    while ($col = $columns->fetch_assoc()) {
        $cols_list[] = $col['Field'];
    }
    echo implode(", ", $cols_list);

    // Show data preview
    $data = $conn->query("SELECT * FROM `$tableName` LIMIT 2");
    if ($data->num_rows > 0) {
        echo "<h4 style='margin-bottom:5px;'>Content Preview:</h4>";
        echo "<table border='1' style='border-collapse:collapse; width:100%; font-size:12px; background:white;'><tr>";
        // Headers
        foreach($cols_list as $c) echo "<th style='padding:5px; background:#eee;'>$c</th>";
        echo "</tr>";
        // Rows
        while($row = $data->fetch_assoc()) {
            echo "<tr>";
            foreach($row as $val) echo "<td style='padding:5px;'>".htmlspecialchars($val)."</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:orange;'><em>Table is empty.</em></p>";
    }
    echo "</div>";
}
?>