<?php
session_start();
require "../includes/conn.php";

if (!isset($_SESSION['client_id'])) {
    header("Location: ../login_client.php");
    exit();
}

$client_id = $_SESSION['client_id'];
$payment_info = $_POST['payment_info'] ?? '';
$bank_name = $_POST['bank_name'] ?? '';
$bank_acc_name = $_POST['bank_acc_name'] ?? '';
$bank_acc_no = $_POST['bank_acc_no'] ?? '';

$stmt = $conn->prepare("UPDATE clients SET payment_info = ?, bank_name = ?, bank_acc_name = ?, bank_acc_no = ? WHERE id = ?");
$stmt->bind_param("ssssi", $payment_info, $bank_name, $bank_acc_name, $bank_acc_no, $client_id);

if ($stmt->execute()) {
    header("Location: ../profile.php?status=success#payment");
} else {
    header("Location: ../profile.php?status=error#payment");
}
exit();