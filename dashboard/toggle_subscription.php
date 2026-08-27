<?php
/**
 * EchoTech POS
 * Customer Subscription Toggle
 *
 * This endpoint is intentionally in /dashboard/ because the dashboard
 * customer records live there, while store_header.php is used by the
 * public /api/ pages.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once "../includes/conn.php";

function subscription_json(bool $success, bool $subscribed, string $message, array $extra = []): void {
    echo json_encode(array_merge([
        'success' => $success,
        'subscribed' => $subscribed,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    subscription_json(false, false, 'Invalid subscription request.');
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_POST['branch_id'] ?? 0);

if ($client_id <= 0) {
    subscription_json(false, false, 'Please log in before subscribing.');
}

if ($branch_id <= 0) {
    subscription_json(false, false, 'Invalid branch.');
}

/*
 * Resolve the branch and pharmacy from the database.
 * Never trust the pharmacy_id supplied by the browser.
 */
$stmt = $conn->prepare("
    SELECT id, pharmacy_id, branch_name
    FROM branches
    WHERE id = ?
      AND is_active = 1
    LIMIT 1
");

if (!$stmt) {
    subscription_json(false, false, 'Unable to verify the branch.');
}

$stmt->bind_param('i', $branch_id);
$stmt->execute();
$branch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$branch) {
    subscription_json(false, false, 'The selected branch is unavailable.');
}

$pharmacy_id = (int)$branch['pharmacy_id'];
$branch_name = (string)$branch['branch_name'];

/*
 * Load the authenticated client.
 */
$stmt = $conn->prepare("
    SELECT id, full_name, phone, email
    FROM clients
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    subscription_json(false, false, 'Unable to load your account.');
}

$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    subscription_json(false, false, 'Your account could not be found.');
}

$name  = trim((string)($client['full_name'] ?? 'Customer'));
$phone = trim((string)($client['phone'] ?? ''));
$email = strtolower(trim((string)($client['email'] ?? '')));

if ($name === '') {
    $name = 'Customer';
}

/*
 * We use a transaction so the state returned to the browser is the
 * state that was actually committed to the database.
 */
$conn->begin_transaction();

try {

    $existing = null;

    /*
     * 1. Exact client_id match.
     */
    $stmt = $conn->prepare("
        SELECT id, client_id
        FROM customers
        WHERE pharmacy_id = ?
          AND branch_id = ?
          AND client_id = ?
        ORDER BY id ASC
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Unable to check subscription.');
    }

    $stmt->bind_param('iii', $pharmacy_id, $branch_id, $client_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /*
     * 2. Existing customer with same email.
     * This repairs old customer records created before subscriptions
     * were linked to the app account.
     */
    if (!$existing && $email !== '') {

        $stmt = $conn->prepare("
            SELECT id, client_id
            FROM customers
            WHERE pharmacy_id = ?
              AND branch_id = ?
              AND LOWER(TRIM(COALESCE(email,''))) = ?
            ORDER BY
                CASE WHEN client_id IS NULL THEN 1 ELSE 0 END,
                id ASC
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('Unable to check existing customer.');
        }

        $stmt->bind_param('iis', $pharmacy_id, $branch_id, $email);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    /*
     * 3. Existing customer with same phone.
     */
    if (!$existing && $phone !== '') {

        $stmt = $conn->prepare("
            SELECT id, client_id
            FROM customers
            WHERE pharmacy_id = ?
              AND branch_id = ?
              AND TRIM(COALESCE(phone,'')) = ?
            ORDER BY
                CASE WHEN client_id IS NULL THEN 1 ELSE 0 END,
                id ASC
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('Unable to check existing customer.');
        }

        $stmt->bind_param('iis', $pharmacy_id, $branch_id, $phone);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    /*
     * Existing record = currently subscribed.
     * Clicking again means unsubscribe.
     *
     * Remove all rows belonging to this exact app account/identity
     * for this pharmacy + branch, then verify no matching record remains.
     */
    if ($existing) {

        $delete_sql = "
            DELETE FROM customers
            WHERE pharmacy_id = ?
              AND branch_id = ?
              AND (
                    client_id = ?
        ";

        if ($email !== '') {
            $delete_sql .= "
                    OR LOWER(TRIM(COALESCE(email,''))) = ?
            ";
        }

        if ($phone !== '') {
            $delete_sql .= "
                    OR TRIM(COALESCE(phone,'')) = ?
            ";
        }

        $delete_sql .= "
              )
        ";

        if ($email !== '' && $phone !== '') {

            $stmt = $conn->prepare($delete_sql);

            if (!$stmt) {
                throw new Exception('Unable to unsubscribe.');
            }

            $stmt->bind_param(
                'iiiss',
                $pharmacy_id,
                $branch_id,
                $client_id,
                $email,
                $phone
            );

        } elseif ($email !== '') {

            $stmt = $conn->prepare($delete_sql);

            if (!$stmt) {
                throw new Exception('Unable to unsubscribe.');
            }

            $stmt->bind_param(
                'iiis',
                $pharmacy_id,
                $branch_id,
                $client_id,
                $email
            );

        } elseif ($phone !== '') {

            $stmt = $conn->prepare($delete_sql);

            if (!$stmt) {
                throw new Exception('Unable to unsubscribe.');
            }

            $stmt->bind_param(
                'iiis',
                $pharmacy_id,
                $branch_id,
                $client_id,
                $phone
            );

        } else {

            $stmt = $conn->prepare("
                DELETE FROM customers
                WHERE pharmacy_id = ?
                  AND branch_id = ?
                  AND client_id = ?
            ");

            if (!$stmt) {
                throw new Exception('Unable to unsubscribe.');
            }

            $stmt->bind_param(
                'iii',
                $pharmacy_id,
                $branch_id,
                $client_id
            );
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Unable to unsubscribe from this pharmacy.');
        }

        $stmt->close();

        /*
         * Verify deletion.
         */
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM customers
            WHERE pharmacy_id = ?
              AND branch_id = ?
              AND client_id = ?
        ");

        if (!$stmt) {
            throw new Exception('Unable to verify unsubscribe.');
        }

        $stmt->bind_param('iii', $pharmacy_id, $branch_id, $client_id);
        $stmt->execute();
        $remaining = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        if ($remaining > 0) {
            throw new Exception('The subscription could not be fully removed.');
        }

        $conn->commit();

        subscription_json(
            true,
            false,
            'You have been unsubscribed from ' . $branch_name . '.',
            ['customer_id' => (int)$existing['id']]
        );
    }

    /*
     * No existing subscription:
     * create a customer row linked directly to the app client.
     */
    $stmt = $conn->prepare("
        INSERT INTO customers
            (pharmacy_id, branch_id, client_id, name, phone, email)
        VALUES
            (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Unable to create your subscription.');
    }

    $stmt->bind_param(
        'iiisss',
        $pharmacy_id,
        $branch_id,
        $client_id,
        $name,
        $phone,
        $email
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception(
            $err !== ''
                ? 'Unable to subscribe: ' . $err
                : 'Unable to subscribe right now.'
        );
    }

    $customer_id = (int)$stmt->insert_id;
    $stmt->close();

    /*
     * Verify the record exists before telling the browser that the
     * button should become green.
     */
    $stmt = $conn->prepare("
        SELECT id
        FROM customers
        WHERE id = ?
          AND pharmacy_id = ?
          AND branch_id = ?
          AND client_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Unable to verify the new subscription.');
    }

    $stmt->bind_param(
        'iiii',
        $customer_id,
        $pharmacy_id,
        $branch_id,
        $client_id
    );

    $stmt->execute();
    $verified = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$verified) {
        throw new Exception('The subscription was not saved.');
    }

    $conn->commit();

    subscription_json(
        true,
        true,
        'Subscribed to ' . $branch_name . '. You are now in the pharmacy customer list.',
        [
            'customer_id' => $customer_id,
            'pharmacy_id' => $pharmacy_id,
            'branch_id' => $branch_id
        ]
    );

} catch (Throwable $e) {

    $conn->rollback();

    subscription_json(
        false,
        false,
        $e->getMessage() !== ''
            ? $e->getMessage()
            : 'Unable to update subscription.'
    );
}
?>
