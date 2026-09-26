<?php
declare(strict_types=1);

session_start();
require "conn.php";

/*
 * Bulk stock is a CSV/TSV importer. Excel can save CSV files in several
 * encodings, so normalize the uploaded text before fgetcsv() sees it.
 */
function bulk_stock_normalize_text(string $content): string
{
    /* UTF-8 BOM */
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }

    /* UTF-16 BOMs */
    if (strncmp($content, "\xFF\xFE", 2) === 0) {
        $converted = iconv('UTF-16LE', 'UTF-8//IGNORE', substr($content, 2));
        if ($converted !== false) {
            return str_replace("\0", '', $converted);
        }
    }

    if (strncmp($content, "\xFE\xFF", 2) === 0) {
        $converted = iconv('UTF-16BE', 'UTF-8//IGNORE', substr($content, 2));
        if ($converted !== false) {
            return str_replace("\0", '', $converted);
        }
    }

    /* Detect UTF-16 files that have no BOM. */
    $sample = substr($content, 0, 4096);
    $nullCount = substr_count($sample, "\0");
    if ($nullCount > 8) {
        $evenNulls = 0;
        $oddNulls = 0;
        $sampleLength = strlen($sample);
        for ($i = 0; $i < $sampleLength; $i++) {
            if ($sample[$i] === "\0") {
                if (($i % 2) === 0) {
                    $evenNulls++;
                } else {
                    $oddNulls++;
                }
            }
        }

        $encoding = $oddNulls >= $evenNulls ? 'UTF-16LE' : 'UTF-16BE';
        $converted = iconv($encoding, 'UTF-8//IGNORE', $content);
        if ($converted !== false && $converted !== '') {
            return str_replace("\0", '', $converted);
        }
    }

    /* Already valid UTF-8. */
    if (preg_match('//u', $content) === 1) {
        return str_replace("\0", '', $content);
    }

    /* Common Windows/Excel legacy CSV encoding. */
    $converted = iconv('Windows-1252', 'UTF-8//IGNORE', $content);
    if ($converted !== false && preg_match('//u', $converted) === 1) {
        return str_replace("\0", '', $converted);
    }

    /* Last text fallback. */
    $converted = iconv('ISO-8859-1', 'UTF-8//IGNORE', $content);
    if ($converted !== false) {
        return str_replace("\0", '', $converted);
    }

    return str_replace("\0", '', $content);
}

function bulk_stock_redirect_error(string $message): never
{
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode($message));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_FILES['stock_file'])) {
    bulk_stock_redirect_error('Please select a CSV file to upload.');
}

if ((int)($_FILES['stock_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    bulk_stock_redirect_error('The stock file could not be uploaded. Please try again.');
}

$pharmacy_id = (int)($_SESSION['pharmacy_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

if ($pharmacy_id <= 0 || $branch_id <= 0) {
    bulk_stock_redirect_error('Your pharmacy or branch session is missing. Please log in again.');
}

$file = (string)$_FILES['stock_file']['tmp_name'];
$file_content = file_get_contents($file);

if ($file_content === false || $file_content === '') {
    bulk_stock_redirect_error('The uploaded stock file is empty or unreadable.');
}

/* The screen explicitly asks for CSV, not an XLSX/Excel binary workbook. */
if (strncmp($file_content, "PK\x03\x04", 4) === 0) {
    bulk_stock_redirect_error('Please save the Excel workbook as CSV UTF-8 before uploading.');
}

$file_content = bulk_stock_normalize_text($file_content);

/* Do not let binary/control data reach MySQL as product names. */
if (preg_match('//u', $file_content) !== 1) {
    bulk_stock_redirect_error('The CSV contains invalid text encoding. Save it as CSV UTF-8 and try again.');
}

/* mysqli must speak the same character set as the normalized CSV. */
if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');
}

$delimiter = (strpos($file_content, "\t") !== false) ? "\t" : ",";

/* fgetcsv() works on a stream, so feed it our normalized UTF-8 content. */
$handle = fopen('php://temp', 'r+');
if ($handle === false) {
    bulk_stock_redirect_error('The stock file could not be processed.');
}
fwrite($handle, $file_content);
rewind($handle);

/* Skip the header row. */
fgetcsv($handle, 0, $delimiter);

$count = 0;
$lineNumber = 1;

$sql = "INSERT INTO store_items (
            pharmacy_id, branch_id, item_name, strength, cost,
            price, quantity, category, barcode, expiry_date, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    fclose($handle);
    bulk_stock_redirect_error('The stock import could not be prepared.');
}

while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
    $lineNumber++;

    if (!isset($data[0]) || trim((string)$data[0]) === '') {
        continue;
    }

    /* Normalize every text field as an extra safeguard. */
    foreach ($data as $key => $value) {
        $data[$key] = trim(bulk_stock_normalize_text((string)$value));
    }

    $raw_name = trim((string)$data[0]);
    $strength = isset($data[1]) ? trim((string)$data[1]) : '';
    $item_name = trim($raw_name . ' ' . $strength);

    if ($item_name === '') {
        continue;
    }

    $cost = isset($data[2]) ? (float)str_replace(',', '', (string)$data[2]) : 0.00;
    $price = isset($data[3]) ? (float)str_replace(',', '', (string)$data[3]) : 0.00;
    $quantity = isset($data[4]) ? (int)$data[4] : 0;
    $category = !empty($data[5]) ? trim((string)$data[5]) : 'Medicine';
    $barcode = isset($data[6]) ? trim((string)$data[6]) : '';

    /* Date Conversion (DD/MM/YYYY to YYYY-MM-DD). */
    $expiry_date = '0000-00-00';
    if (!empty($data[7])) {
        $date_raw = trim((string)$data[7]);
        $date_obj = DateTime::createFromFormat('d/m/Y', $date_raw);
        if ($date_obj) {
            $expiry_date = $date_obj->format('Y-m-d');
        }
    }

    $stmt->bind_param(
        'iissddisss',
        $pharmacy_id,
        $branch_id,
        $item_name,
        $strength,
        $cost,
        $price,
        $quantity,
        $category,
        $barcode,
        $expiry_date
    );

    try {
        $stmt->execute();
        $count++;
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        fclose($handle);
        bulk_stock_redirect_error(
            'Stock import stopped at CSV row ' . $lineNumber . '. Please check the product text/format and try again.'
        );
    }
}

$stmt->close();
fclose($handle);

header('Location: ../dashboard/update_items_stock.php?status=success&count=' . $count);
exit();
