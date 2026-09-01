<?php
declare(strict_types=1);

/**
 * EchoTech POS - ZRA VSDC ZRA Integration client.
 *
 * The VSDC/OSDC controller is responsible for device keys and signing.
 * EchoTech sends JSON to the configured controller and stores the response.
 */

function zra_pdo_or_mysqli_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function zra_secret_key(): string {
    $key = trim((string)(getenv('ECHOTECH_ZRA_CREDENTIAL_KEY') ?: ''));
    if ($key === '') {
        throw new RuntimeException('ECHOTECH_ZRA_CREDENTIAL_KEY is not configured on the server.');
    }
    return hash('sha256', $key, true);
}

function zra_encrypt_secret(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', zra_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt ZRA device credential.');
    return base64_encode($iv . $tag . $cipher);
}

function zra_decrypt_secret(?string $encoded): string {
    if (!$encoded) return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) throw new RuntimeException('Stored ZRA credential is invalid.');
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', zra_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('Unable to decrypt ZRA device credential.');
    return $plain;
}

function zra_http_post(string $baseUrl, string $endpoint, array $payload, int $timeout = 45): array {
    $baseUrl = rtrim(trim($baseUrl), '/');
    $endpoint = '/' . ltrim(trim($endpoint), '/');
    $url = $baseUrl . $endpoint;

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to encode ZRA request JSON.');

    $started = microtime(true);
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Unable to initialize cURL.');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $duration = (int)round((microtime(true) - $started) * 1000);

    if ($body === false) {
        return [
            'ok' => false,
            'http_status' => $httpStatus,
            'body' => '',
            'json' => null,
            'error' => $curlError !== '' ? $curlError : 'Unknown cURL error.',
            'duration_ms' => $duration,
            'url' => $url,
        ];
    }

    $decoded = json_decode((string)$body, true);
    $resultCode = is_array($decoded) ? (string)($decoded['resultCd'] ?? '') : '';
    $ok = $httpStatus >= 200 && $httpStatus < 300 && $resultCode === '000';

    return [
        'ok' => $ok,
        'http_status' => $httpStatus,
        'body' => (string)$body,
        'json' => is_array($decoded) ? $decoded : null,
        'error' => $ok ? '' : ((string)($decoded['resultMsg'] ?? '') ?: 'ZRA/VSDC request failed.'),
        'duration_ms' => $duration,
        'url' => $url,
    ];
}

function zra_payment_code(string $method): string {
    $m = strtolower(trim($method));
    if (str_contains($m, 'mobile')) return '06';
    if (str_contains($m, 'bank')) return '04';
    if (str_contains($m, 'cash')) return '01';
    if (str_contains($m, 'card') || str_contains($m, 'debit')) return '05';
    return '01';
}

function zra_tax_category(float $rate, string $configured = ''): string {
    $configured = strtoupper(trim($configured));
    if ($configured !== '') return $configured;
    return $rate > 0 ? 'A' : 'D';
}

function zra_rate(float $rate): float {
    return round(max(0, $rate), 4);
}

function zra_line_tax(float $gross, float $rate): array {
    $gross = round(max(0, $gross), 4);
    $rate = zra_rate($rate);
    if ($rate <= 0) return ['taxable' => $gross, 'vat' => 0.0, 'gross' => $gross];
    $taxable = round($gross / (1 + ($rate / 100)), 4);
    $vat = round($gross - $taxable, 4);
    return ['taxable' => $taxable, 'vat' => $vat, 'gross' => $gross];
}

function zra_build_sale_payload(array $sale, array $items, array $settings, string $bhfId): array {
    $tpin = preg_replace('/\D+/', '', (string)($settings['tpin'] ?? ''));
    if (strlen($tpin) !== 10) throw new RuntimeException('ZRA TPIN must contain exactly 10 digits.');
    if (!preg_match('/^\d{3}$/', $bhfId)) throw new RuntimeException('ZRA branch ID (bhfId) must contain exactly 3 characters.');
    if (!$items) throw new RuntimeException('Sale contains no sale items.');

    $taxable = array_fill_keys(['A','B','C1','C2','C3','D','RVAT','E','F','IPL1','IPL2','TL','ECM','EXE','TOT'], 0.0);
    $taxAmt = array_fill_keys(array_keys($taxable), 0.0);
    $taxRates = array_fill_keys(array_keys($taxable), 0.0);
    $itemList = [];

    $sequence = 0;
    $sumGross = 0.0;
    $sumTaxable = 0.0;
    $sumVat = 0.0;

    foreach ($items as $item) {
        $sequence++;
        $qty = max(0.0001, (float)$item['quantity']);
        $price = max(0.0, (float)$item['unit_price']);
        $gross = round($qty * $price, 4);
        $rate = zra_rate((float)($item['tax_rate'] ?? 0));
        $cat = zra_tax_category($rate, (string)($item['zra_tax_type_cd'] ?? ''));
        $calc = zra_line_tax($gross, $rate);
        $sumGross += $calc['gross'];
        $sumTaxable += $calc['taxable'];
        $sumVat += $calc['vat'];
        if (!isset($taxable[$cat])) { $cat = $rate > 0 ? 'A' : 'D'; }
        $taxable[$cat] += $calc['taxable'];
        $taxAmt[$cat] += $calc['vat'];
        $taxRates[$cat] = $rate;

        $itemCd = trim((string)($item['zra_item_cd'] ?? ''));
        $itemCls = trim((string)($item['zra_item_cls_cd'] ?? ''));
        if ($itemCd === '' || $itemCls === '') {
            throw new RuntimeException('Product "'.($item['item_name'] ?? 'Unknown').'" is not ZRA item-registered/classified.');
        }

        $itemList[] = [
            'itemSeq' => $sequence,
            'itemCd' => $itemCd,
            'itemClsCd' => $itemCls,
            'itemNm' => mb_substr((string)$item['item_name'], 0, 200),
            'bcd' => (string)($item['barcode'] ?? ''),
            'pkgUnitCd' => (string)($item['zra_pkg_unit_cd'] ?? 'EA'),
            'pkg' => 0.0,
            'qtyUnitCd' => (string)($item['zra_qty_unit_cd'] ?? 'EA'),
            'qty' => round($qty, 4),
            'prc' => round($price, 4),
            'splyAmt' => round($gross, 4),
            'dcRt' => 0.0,
            'dcAmt' => 0.0,
            'vatCatCd' => $cat,
            'exciseTxCatCd' => null,
            'tlCatCd' => null,
            'iplCatCd' => null,
            'vatTaxblAmt' => round($calc['taxable'], 4),
            'vatAmt' => round($calc['vat'], 4),
            'exciseTaxblAmt' => 0.0,
            'tlTaxblAmt' => 0.0,
            'iplTaxblAmt' => 0.0,
            'vatTaxAmt' => round($calc['vat'], 4),
            'exciseTxAmt' => 0.0,
            'totAmt' => round($gross, 4),
        ];
    }

    $payment = zra_payment_code((string)($sale['payment_method'] ?? 'Cash'));
    $created = !empty($sale['created_at']) ? strtotime((string)$sale['created_at']) : time();
    if (!$created) $created = time();

    return [
        'tpin' => $tpin,
        'bhfId' => $bhfId,
        'orgInvcNo' => 0,
        'cisInvcNo' => mb_substr((string)$sale['invoice'], 0, 50),
        'custTpin' => null,
        'custNm' => 'Walk-In Customer',
        'salesTyCd' => 'N',
        'rcptTyCd' => 'S',
        'pmtTyCd' => $payment,
        'salesSttsCd' => '02',
        'cfmDt' => date('YmdHis', $created),
        'salesDt' => date('Ymd', $created),
        'stockRlsDt' => date('YmdHis', $created),
        'cnclReqDt' => null,
        'cnclDt' => null,
        'rfdDt' => null,
        'rfdRsnCd' => null,
        'totItemCnt' => count($itemList),
        'taxblAmtA' => round($taxable['A'], 4),
        'taxblAmtB' => round($taxable['B'], 4),
        'taxblAmtC1' => round($taxable['C1'], 4),
        'taxblAmtC2' => round($taxable['C2'], 4),
        'taxblAmtC3' => round($taxable['C3'], 4),
        'taxblAmtD' => round($taxable['D'], 4),
        'taxblAmtRvat' => round($taxable['RVAT'], 4),
        'taxblAmtE' => round($taxable['E'], 4),
        'taxblAmtF' => round($taxable['F'], 4),
        'taxblAmtIpl1' => round($taxable['IPL1'], 4),
        'taxblAmtIpl2' => round($taxable['IPL2'], 4),
        'taxblAmtTl' => round($taxable['TL'], 4),
        'taxblAmtEcm' => round($taxable['ECM'], 4),
        'taxblAmtExeeg' => round($taxable['EXE'], 4),
        'taxblAmtTot' => round($taxable['TOT'], 4),
        'taxRtA' => $taxRates['A'],
        'taxRtB' => $taxRates['B'],
        'taxRtC1' => $taxRates['C1'],
        'taxRtC2' => $taxRates['C2'],
        'taxRtC3' => $taxRates['C3'],
        'taxRtD' => $taxRates['D'],
        'taxRtRvat' => $taxRates['RVAT'],
        'taxRtE' => $taxRates['E'],
        'taxRtF' => $taxRates['F'],
        'taxRtIpl1' => $taxRates['IPL1'],
        'taxRtIpl2' => $taxRates['IPL2'],
        'taxRtTl' => $taxRates['TL'],
        'taxRtEcm' => $taxRates['ECM'],
        'taxRtExeeg' => $taxRates['EXE'],
        'taxRtTot' => $taxRates['TOT'],
        'taxAmtA' => round($taxAmt['A'], 4),
        'taxAmtB' => round($taxAmt['B'], 4),
        'taxAmtC1' => round($taxAmt['C1'], 4),
        'taxAmtC2' => round($taxAmt['C2'], 4),
        'taxAmtC3' => round($taxAmt['C3'], 4),
        'taxAmtD' => round($taxAmt['D'], 4),
        'taxAmtRvat' => round($taxAmt['RVAT'], 4),
        'taxAmtE' => round($taxAmt['E'], 4),
        'taxAmtF' => round($taxAmt['F'], 4),
        'taxAmtIpl1' => round($taxAmt['IPL1'], 4),
        'taxAmtIpl2' => round($taxAmt['IPL2'], 4),
        'taxAmtTl' => round($taxAmt['TL'], 4),
        'taxAmtEcm' => round($taxAmt['ECM'], 4),
        'taxAmtExeeg' => round($taxAmt['EXE'], 4),
        'taxAmtTot' => round($sumVat, 4),
        'totTaxblAmt' => round($sumTaxable, 4),
        'totTaxAmt' => round($sumVat, 4),
        'cashDcRt' => 0.0,
        'cashDcAmt' => 0.0,
        'totAmt' => round($sumGross, 4),
        'prchrAcptcYn' => 'N',
        'remark' => 'EchoTech POS',
        'regrId' => (string)($sale['user_id'] ?? 'system'),
        'regrNm' => (string)($sale['issued_by'] ?? 'EchoTech POS'),
        'modrId' => (string)($sale['user_id'] ?? 'system'),
        'modrNm' => (string)($sale['issued_by'] ?? 'EchoTech POS'),
        'saleCtyCd' => '1',
        'lpoNumber' => null,
        'currencyTyCd' => 'ZMW',
        'exchangeRt' => '1',
        'destnCountryCd' => '',
        'dbtRsnCd' => '',
        'invcAdjustReason' => '',
        'itemList' => $itemList,
    ];
}
