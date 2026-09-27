<?php
declare(strict_types=1);

session_start();
require __DIR__ . "/conn.php";

/*
 * EchoTech POS - Bulk Stock Importer
 *
 * Important:
 * - Barcode is ALWAYS treated as TEXT.
 * - Never cast a barcode to int/float.
 * - UTF-8/UTF-16 CSV files are normalized before parsing.
 * - Scientific-notation barcode values are rejected because the original
 *   digits cannot be safely recovered after Excel has converted them.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['stock_file'])) {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('No stock file was uploaded.'));
    exit;
}

if (!isset($_SESSION['pharmacy_id'], $_SESSION['branch_id'])) {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('Your pharmacy or branch session has expired. Please log in again.'));
    exit;
}

$upload = $_FILES['stock_file'];

if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('The stock file could not be uploaded.'));
    exit;
}

$pharmacy_id = (int)$_SESSION['pharmacy_id'];
$branch_id   = (int)$_SESSION['branch_id'];
$file        = (string)$upload['tmp_name'];

if ($pharmacy_id <= 0 || $branch_id <= 0 || !is_uploaded_file($file)) {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('Invalid stock upload.'));
    exit;
}

$file_content = file_get_contents($file);

if ($file_content === false || $file_content === '') {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('The uploaded stock file is empty.'));
    exit;
}

/* Reject native Excel .xlsx/.xls binary files. This importer is CSV/TSV. */
if (strncmp($file_content, "PK", 2) === 0) {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('Please save the Excel file as CSV UTF-8 before uploading it.'));
    exit;
}

/**
 * Convert common Excel CSV encodings to UTF-8 without changing numeric
 * barcode characters. In particular, this function NEVER casts values.
 */
function echotech_csv_to_utf8(string $content): string
{
    /* UTF-8 BOM */
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        return substr($content, 3);
    }

    /* UTF-16 Little Endian BOM */
    if (strncmp($content, "\xFF\xFE", 2) === 0) {
        return echotech_convert_encoding($content, 'UTF-8', 'UTF-16LE');
    }

    /* UTF-16 Big Endian BOM */
    if (strncmp($content, "\xFE\xFF", 2) === 0) {
        return echotech_convert_encoding($content, 'UTF-8', 'UTF-16BE');
    }

    /* Excel can produce UTF-16 without a BOM. NULL bytes are a strong signal. */
    if (strpos($content, "\x00") !== false) {
        $sample = substr($content, 0, min(strlen($content), 2000));

        if (preg_match('/(?:\x00[A-Za-z,\t\r\n])|(?:[A-Za-z,\t\r\n]\x00)/', $sample)) {
            $utf16le = echotech_convert_encoding($content, 'UTF-8', 'UTF-16LE');
            if ($utf16le !== '' && strpos($utf16le, "\x00") === false) {
                return $utf16le;
            }
        }
    }

    /* Windows Excel CSV is commonly Windows-1252. Convert only when needed. */
    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $encoding = mb_detect_encoding(
            $content,
            ['UTF-8', 'Windows-1252', 'ISO-8859-1'],
            true
        );

        if ($encoding && strtoupper($encoding) !== 'UTF-8') {
            return mb_convert_encoding($content, 'UTF-8', $encoding);
        }
    }

    return $content;
}

function echotech_convert_encoding(string $content, string $to, string $from): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($content, $to, $from);
    }

    if (function_exists('iconv')) {
        $converted = iconv($from, $to . '//IGNORE', $content);
        return $converted === false ? $content : $converted;
    }

    return $content;
}

/** Remove BOM/control contamination while preserving normal Unicode text. */
function echotech_clean_csv_text($value): string
{
    $value = (string)$value;
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = str_replace("\0", '', $value);
    return trim($value);
}

/**
 * A scientific-notation barcode means Excel has already changed the original
 * barcode representation. Do NOT guess the missing digits.
 */
function echotech_is_scientific_barcode(string $barcode): bool
{
    return (bool)preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $barcode);
}

$file_content = echotech_csv_to_utf8($file_content);

/* Ensure MySQL receives UTF-8 text for names, strength and categories. */
if (method_exists($conn, 'set_charset')) {
    $conn->set_charset('utf8mb4');
}

/* AUTO-DETECT DELIMITER (Comma vs Tab). */
$delimiter = (strpos($file_content, "\t") !== false) ? "\t" : ",";

$handle = fopen('php://temp', 'r+');
if ($handle === false) {
    header('Location: ../dashboard/update_items_stock.php?status=error&message=' . rawurlencode('Unable to read the stock file.'));
    exit;
}

fwrite($handle, $file_content);
rewind($handle);

/* Skip the header row. */
fgetcsv($handle, 0, $delimiter);

$count = 0;
$row_number = 1;
$barcode_error_row = 0;
$barcode_error_value = '';

$sql = "INSERT INTO store_items (
            pharmacy_id, branch_id, item_name, strength, cost,
            price, quantity, category, barcode, expiry_date, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

$stmt = $conn->prepare($sql);

try {
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_number++;

        /* Skip completely empty rows. */
        if (!isset($data[0]) || trim((string)$data[0]) === '') {
            continue;
        }

        /* EXTRACT DATA SAFELY. Barcode remains a STRING throughout. */
        $raw_name = echotech_clean_csv_text($data[0] ?? '');
        $strength = echotech_clean_csv_text($data[1] ?? '');

        $item_name = trim($raw_name . ($strength !== '' ? ' ' . $strength : ''));

        $cost = isset($data[2])
            ? (float)str_replace(',', '', echotech_clean_csv_text($data[2]))
            : 0.00;

        $price = isset($data[3])
            ? (float)str_replace(',', '', echotech_clean_csv_text($data[3]))
            : 0.00;

        $quantity = isset($data[4])
            ? (int)preg_replace('/[^0-9-]/', '', echotech_clean_csv_text($data[4]))
            : 0;

        $category = isset($data[5]) && trim((string)$data[5]) !== ''
            ? echotech_clean_csv_text($data[5])
            : 'Medicine';

        /* CRITICAL: barcode is text. Do NOT cast it to int or float. */
        $barcode = isset($data[6]) ? echotech_clean_csv_text($data[6]) : '';

        /* If Excel still exported scientific notation, stop instead of saving a wrong barcode. */
        if ($barcode !== '' && echotech_is_scientific_barcode($barcode)) {
            $barcode_error_row = $row_number;
            $barcode_error_value = $barcode;
            break;
        }

        /* Date Conversion (DD/MM/YYYY to YYYY-MM-DD). */
        $expiry_date = '0000-00-00';
        if (isset($data[7]) && trim((string)$data[7]) !== '') {
            $date_raw = echotech_clean_csv_text($data[7]);
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_raw);
            if ($date_obj !== false) {
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

        $stmt->execute();
        $count++;
    }
} finally {
    $stmt->close();
    fclose($handle);
}

if ($barcode_error_row > 0) {
    $message = 'Barcode error on CSV row ' . $barcode_error_row
        . ': "' . $barcode_error_value . '" is scientific notation. '
        . 'Format the Barcode column as Text in Excel and save the CSV as UTF-8. '
        . 'The original barcode digits cannot safely be recovered from scientific notation.';

    header('Location: ../dashboard/update_items_stock.php?status=error&count=' . $count . '&message=' . rawurlencode($message));
    exit;
}

header('Location: ../dashboard/update_items_stock.php?status=success&count=' . $count);
exit;
