<?php
/**
 * BIGE50 - Public Store Subscription Endpoint
 *
 * One source of truth:
 * A customer row in `customers` belonging to the current pharmacy + branch
 * represents the subscription.
 *
 * Identity matching:
 *   1. customers.client_id = logged-in clients.id
 *   2. same email
 *   3. same normalized phone
 *
 * This also repairs legacy/manual customer rows by attaching client_id.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Lusaka');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../includes/conn.php';

function subscription_json(string $status, string $message, array $extra = [], int $http = 200): never
{
    http_response_code($http);
    echo json_encode(array_merge([
        'status'  => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_subscription_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone));

    if ($phone === '') {
        return '';
    }

    // Zambia: +260XXXXXXXXX / 260XXXXXXXXX -> 0XXXXXXXXX
    if (str_starts_with($phone, '260') && strlen($phone) >= 12) {
        $phone = '0' . substr($phone, 3);
    }

    return $phone;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    subscription_json('error', 'Invalid request method.', [], 405);
}

$client_id = (int)($_SESSION['client_id'] ?? 0);
$branch_id = (int)($_POST['branch_id'] ?? $_SESSION['current_branch_id'] ?? 0);
$csrf      = (string)($_POST['csrf'] ?? '');

if ($client_id <= 0) {
    subscription_json('error', 'Please log in before changing your subscription.', [], 401);
}

if ($branch_id <= 0) {
    subscription_json('error', 'No valid pharmacy branch was selected.', [], 400);
}

$session_csrf = (string)($_SESSION['subscription_csrf'] ?? '');
if ($session_csrf === '' || $csrf === '' || !hash_equals($session_csrf, $csrf)) {
    subscription_json('error', 'Your session security token is invalid. Please refresh the store and try again.', [], 403);
}

/*
 * Resolve the branch and pharmacy from the database.
 * Never trust pharmacy_id supplied by the browser.
 */
$branch_stmt = $conn->prepare(
    "SELECT id, pharmacy_id, branch_name
     FROM branches
     WHERE id = ? AND is_active = 1
     LIMIT 1"
);

if (!$branch_stmt) {
    subscription_json('error', 'Unable to verify the selected branch.', [], 500);
}

$branch_stmt->bind_param('i', $branch_id);
$branch_stmt->execute();
$branch = $branch_stmt->get_result()->fetch_assoc() ?: [];
$branch_stmt->close();

if (!$branch) {
    subscription_json('error', 'The selected pharmacy branch is not available.', [], 404);
}

$pharmacy_id = (int)$branch['pharmacy_id'];

/*
 * Get the real logged-in client identity.
 */
$client_stmt = $conn->prepare(
    "SELECT id, full_name, email, phone
     FROM clients
     WHERE id = ?
     LIMIT 1"
);

if (!$client_stmt) {
    subscription_json('error', 'Unable to verify your account.', [], 500);
}

$client_stmt->bind_param('i', $client_id);
$client_stmt->execute();
$client = $client_stmt->get_result()->fetch_assoc() ?: [];
$client_stmt->close();

if (!$client) {
    subscription_json('error', 'Your client account could not be found.', [], 404);
}

$client_name  = trim((string)($client['full_name'] ?? ''));
$client_email = strtolower(trim((string)($client['email'] ?? '')));
$client_phone = normalize_subscription_phone((string)($client['phone'] ?? ''));

if ($client_name === '') {
    subscription_json('error', 'Your account does not have a valid name.', [], 422);
}

$action = strtolower(trim((string)($_POST['action'] ?? 'toggle')));
if (!in_array($action, ['subscribe', 'unsubscribe', 'toggle'], true)) {
    subscription_json('error', 'Invalid subscription action.', [], 400);
}

/*
 * Load every customer in this pharmacy + branch.
 * The table has a UNIQUE(branch_id, phone) constraint, so PHP-side
 * normalization lets us safely recognise 097..., 26097..., and +26097...
 * as the same person before changing rows.
 */
$candidate_stmt = $conn->prepare(
    "SELECT id, client_id, name, phone, email, address
     FROM customers
     WHERE pharmacy_id = ? AND branch_id = ?
     ORDER BY id ASC"
);

if (!$candidate_stmt) {
    subscription_json('error', 'Unable to read customer records.', [], 500);
}

$candidate_stmt->bind_param('ii', $pharmacy_id, $branch_id);
$candidate_stmt->execute();
$candidate_result = $candidate_stmt->get_result();

$matches = [];

while ($row = $candidate_result->fetch_assoc()) {
    $row_client_id = (int)($row['client_id'] ?? 0);
    $row_email     = strtolower(trim((string)($row['email'] ?? '')));
    $row_phone     = normalize_subscription_phone((string)($row['phone'] ?? ''));

    $match_client = ($row_client_id === $client_id);
    $match_email  = ($client_email !== '' && $row_email !== '' && $row_email === $client_email);
    $match_phone  = ($client_phone !== '' && $row_phone !== '' && $row_phone === $client_phone);

    if ($match_client || $match_email || $match_phone) {
        $row['_match_client'] = $match_client;
        $row['_match_email']  = $match_email;
        $row['_match_phone']  = $match_phone;
        $matches[] = $row;
    }
}

$candidate_result->free();
$candidate_stmt->close();

$is_currently_subscribed = !empty($matches);

/*
 * If action was toggle, determine it from the real database state.
 */
if ($action === 'toggle') {
    $action = $is_currently_subscribed ? 'unsubscribe' : 'subscribe';
}

/*
 * =========================================================
 * UNSUBSCRIBE
 * =========================================================
 * Delete ALL matching identity rows for this client in this
 * pharmacy + branch. This is important:
 *
 * If an old/manual row exists with client_id = NULL and another
 * row has client_id = 2, deleting only client_id = 2 would leave
 * the manual row behind and the header would still say Subscribed.
 *
 * We remove every row that represents this same client.
 */
if ($action === 'unsubscribe') {
    if (!$is_currently_subscribed) {
        subscription_json('success', 'You are already unsubscribed from this pharmacy.', [
            'subscribed' => false,
            'customer_id' => 0,
        ]);
    }

    $conn->begin_transaction();

    try {
        $delete_stmt = $conn->prepare(
            "DELETE FROM customers
             WHERE pharmacy_id = ?
               AND branch_id = ?
               AND id = ?"
        );

        if (!$delete_stmt) {
            throw new RuntimeException('Unable to prepare customer removal.');
        }

        $deleted = 0;

        foreach ($matches as $match) {
            $match_id = (int)$match['id'];
            $delete_stmt->bind_param('iii', $pharmacy_id, $branch_id, $match_id);

            if (!$delete_stmt->execute()) {
                throw new RuntimeException('Unable to remove customer record.');
            }

            $deleted += $delete_stmt->affected_rows;
        }

        $delete_stmt->close();

        /*
         * Final verification. The account must really be gone before
         * reporting success to the browser.
         */
        $verify_stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM customers
             WHERE pharmacy_id = ? AND branch_id = ?
               AND client_id = ?"
        );

        if (!$verify_stmt) {
            throw new RuntimeException('Unable to verify customer removal.');
        }

        $verify_stmt->bind_param('iii', $pharmacy_id, $branch_id, $client_id);
        $verify_stmt->execute();
        $verify_row = $verify_stmt->get_result()->fetch_assoc() ?: [];
        $verify_stmt->close();

        if ((int)($verify_row['total'] ?? 0) > 0) {
            throw new RuntimeException('The customer record could not be fully removed.');
        }

        $conn->commit();

        subscription_json('success', 'You have been unsubscribed. Your customer account has been removed from this pharmacy branch.', [
            'subscribed' => false,
            'customer_id' => 0,
            'deleted_rows' => $deleted,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();

        subscription_json('error', $e->getMessage(), [], 500);
    }
}

/*
 * =========================================================
 * SUBSCRIBE
 * =========================================================
 * If the customer already exists, NEVER create another customer.
 * Re-use the existing row and attach client_id.
 */
if ($action === 'subscribe') {
    $target = null;

    // Prefer a row already linked to this exact client.
    foreach ($matches as $match) {
        if (!empty($match['_match_client'])) {
            $target = $match;
            break;
        }
    }

    // Otherwise reuse the first email/phone match.
    if ($target === null && !empty($matches)) {
        $target = $matches[0];
    }

    $conn->begin_transaction();

    try {
        if ($target !== null) {
            $target_id = (int)$target['id'];

            /*
             * Remove duplicate matching rows first. This also protects
             * the UNIQUE(branch_id, phone) index when we update the
             * selected row with the client's real phone.
             */
            $duplicate_ids = [];
            foreach ($matches as $match) {
                if ((int)$match['id'] !== $target_id) {
                    $duplicate_ids[] = (int)$match['id'];
                }
            }

            if (!empty($duplicate_ids)) {
                $delete_stmt = $conn->prepare(
                    "DELETE FROM customers
                     WHERE pharmacy_id = ?
                       AND branch_id = ?
                       AND id = ?"
                );

                if (!$delete_stmt) {
                    throw new RuntimeException('Unable to clean duplicate customer records.');
                }

                foreach ($duplicate_ids as $duplicate_id) {
                    $delete_stmt->bind_param('iii', $pharmacy_id, $branch_id, $duplicate_id);

                    if (!$delete_stmt->execute()) {
                        throw new RuntimeException('Unable to clean duplicate customer records.');
                    }
                }

                $delete_stmt->close();
            }

            /*
             * Preserve an existing address. Subscription should not
             * erase information staff already entered.
             */
            $existing_address = (string)($target['address'] ?? '');

            $update_stmt = $conn->prepare(
                "UPDATE customers
                 SET client_id = ?, name = ?, phone = ?, email = ?
                 WHERE id = ? AND pharmacy_id = ? AND branch_id = ?"
            );

            if (!$update_stmt) {
                throw new RuntimeException('Unable to link the existing customer account.');
            }

            $update_stmt->bind_param(
                'isssiii',
                $client_id,
                $client_name,
                $client_phone,
                $client_email,
                $target_id,
                $pharmacy_id,
                $branch_id
            );

            if (!$update_stmt->execute()) {
                throw new RuntimeException('Unable to link the existing customer account: ' . $update_stmt->error);
            }

            $update_stmt->close();

            $customer_id = $target_id;
        } else {
            if ($client_phone === '') {
                throw new RuntimeException('Your account needs a valid phone number before you can subscribe.');
            }

            $insert_stmt = $conn->prepare(
                "INSERT INTO customers
                    (pharmacy_id, branch_id, client_id, name, phone, email, address)
                 VALUES (?, ?, ?, ?, ?, ?, '')"
            );

            if (!$insert_stmt) {
                throw new RuntimeException('Unable to prepare the subscription record.');
            }

            $insert_stmt->bind_param(
                'iiisss',
                $pharmacy_id,
                $branch_id,
                $client_id,
                $client_name,
                $client_phone,
                $client_email
            );

            if (!$insert_stmt->execute()) {
                throw new RuntimeException('Unable to create the subscription record: ' . $insert_stmt->error);
            }

            $customer_id = (int)$insert_stmt->insert_id;
            $insert_stmt->close();
        }

        /*
         * Final verification: the subscription MUST exist and must point
         * to this client before the browser receives success.
         */
        $verify_stmt = $conn->prepare(
            "SELECT id
             FROM customers
             WHERE pharmacy_id = ?
               AND branch_id = ?
               AND client_id = ?
             LIMIT 1"
        );

        if (!$verify_stmt) {
            throw new RuntimeException('Unable to verify the subscription.');
        }

        $verify_stmt->bind_param('iii', $pharmacy_id, $branch_id, $client_id);
        $verify_stmt->execute();
        $verified = $verify_stmt->get_result()->fetch_assoc() ?: [];
        $verify_stmt->close();

        if (!$verified) {
            throw new RuntimeException('The subscription was not saved.');
        }

        $customer_id = (int)$verified['id'];

        $conn->commit();

        subscription_json('success', 'Subscription saved. Your customer account is now linked to this pharmacy branch.', [
            'subscribed' => true,
            'customer_id' => $customer_id,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();

        subscription_json('error', $e->getMessage(), [], 500);
    }
}

subscription_json('error', 'No subscription action was completed.', [], 400);
