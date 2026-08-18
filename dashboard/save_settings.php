<?php
session_start();
require_once '../includes/conn.php';

// List of all possible settings keys to save
$settings_keys = [
    'company_name',
    'company_contact',
    'company_address',
    'company_email',
    'currency',
    'tax_rate',
    'discount_rate',
    'invoice_prefix',
    'receipt_footer',
    'theme',
    'session_timeout',
    'backup_mode',
    'welcome_message',
    'manage_account_text',
    'switch_branch_text',
    'contact_text'
];

// Loop through each key and insert or update
foreach ($settings_keys as $key) {
    $value = $_POST[$key] ?? '';

    // Check if the setting already exists
    $stmt_check = $conn->prepare("SELECT COUNT(*) as cnt FROM pos_settings WHERE setting_key = ?");
    $stmt_check->bind_param("s", $key);
    $stmt_check->execute();
    $res = $stmt_check->get_result();
    $row = $res->fetch_assoc();
    $stmt_check->close();

    if ($row['cnt'] > 0) {
        // Update existing setting
        $stmt = $conn->prepare("UPDATE pos_settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->bind_param("ss", $value, $key);
        $stmt->execute();
        $stmt->close();
    } else {
        // Insert new setting
        $stmt = $conn->prepare("INSERT INTO pos_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

echo "Settings saved successfully!";