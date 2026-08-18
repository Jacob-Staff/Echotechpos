<?php
require "../includes/conn.php";
$pid = intval($_GET['pharmacy_id']);
$res = $conn->query("SELECT id, branch_name FROM branches WHERE pharmacy_id = $pid AND is_active = 1");

echo '<option value="">-- Select Branch --</option>';
while($row = $res->fetch_assoc()) {
    echo "<option value='{$row['id']}'>{$row['branch_name']}</option>";
}
?>