<?php
// Set response content type to JSON
header('Content-Type: application/json; charset=utf-8');

// Include database connection (adjust path if db.php is elsewhere)
require_once __DIR__ . '/../db.php'; 

$response = [
    'status'  => 'error',
    'message' => '',
    'data'    => null
];

try {
    // Get store/branch ID from request (defaulting to session or primary record if available)
    session_start();
    $branch_id = $_GET['branch_id'] ?? $_SESSION['branch_id'] ?? 1;

    /* 
     * Note on column names: 
     * We alias `branch_name` AS `name` to ensure compatibility across all API endpoints 
     * without triggering "Column not found: 1054 Unknown column 'name'".
     */
    $sql = "SELECT 
                id,
                branch_name AS name,
                address,
                phone,
                email,
                tax_number,
                receipt_footer
            FROM branches 
            WHERE id = ? 
            LIMIT 1";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($store = $result->fetch_assoc()) {
            $response['status'] = 'success';
            $response['data']   = [
                'id'             => (int)$store['id'],
                'name'           => $store['name'] ?? 'Pharmacy Store',
                'address'        => $store['address'] ?? '',
                'phone'          => $store['phone'] ?? '',
                'email'          => $store['email'] ?? '',
                'tax_number'     => $store['tax_number'] ?? '',
                'receipt_footer' => $store['receipt_footer'] ?? 'Thank you for your business!'
            ];
        } else {
            // Fallback if branch ID doesn't exist
            $response['status']  = 'warning';
            $response['message'] = 'Branch details not found. Returning default store profile.';
            $response['data']    = [
                'id'             => 0,
                'name'           => 'Pharmacy Store',
                'address'        => 'Main Branch',
                'phone'          => '',
                'email'          => '',
                'receipt_footer' => 'Thank you for your business!'
            ];
        }
        $stmt->close();
    } else {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }
} catch (Exception $e) {
    http_response_code(500);
    $response['status']  = 'error';
    $response['message'] = $e->getMessage();
}

// Output final JSON
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
