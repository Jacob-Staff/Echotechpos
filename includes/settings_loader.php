<?php
// settings_loader.php
// Include this at the top of all main pages

require "conn.php";

// Fetch settings from the 'info' table
$settings_sql = "SELECT * FROM info LIMIT 1";
$settings_res = mysqli_query($conn, $settings_sql);

if ($settings_res && mysqli_num_rows($settings_res) > 0) {
    $app_settings = mysqli_fetch_assoc($settings_res);

    // Global variables
    $CURRENCY      = $app_settings['currency'] ?? "ZMW";
    $TAX_RATE      = $app_settings['tax_rate'] ?? 0;
    $RECEIPT_LOGO  = $app_settings['receipt_logo'] ?? null;
    $PHARMACY_NAME = $app_settings['name'] ?? "PHARMACY";
    $PHARMACY_ADDRESS = $app_settings['address'] ?? "";
} else {
    // Defaults
    $CURRENCY      = "ZMW";
    $TAX_RATE      = 0;
    $RECEIPT_LOGO  = null;
    $PHARMACY_NAME = "PHARMACY";
    $PHARMACY_ADDRESS = "";
}
?>
