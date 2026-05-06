<?php
/**
 * Autopay (Blue Media) — konfiguracja i helpery
 * Dokumentacja: https://developers.autopay.eu/online/api/
 */

require_once __DIR__ . '/p24-config.php'; // SITE_URL, ORDERS_DIR, save_order, load_order, send_webhook, N8N_*

define('AUTOPAY_SERVICE_ID', '211642');
define('AUTOPAY_HASH_KEY',   'bf7386123b98cb82fd186c487e91ab3dfbb3b7c53485a9160b43c91a3af788e9');
define('AUTOPAY_URL',        'https://pay.autopay.eu/v1/transaction');

$GLOBALS['autopay_last_error'] = null;

/**
 * Tworzy transakcje w Autopay i zwraca URL do przekierowania
 */
function autopay_create(string $orderId, float $amount, string $description, string $email, string $returnUrl): ?string
{
    $amountStr = number_format($amount, 2, '.', '');
    $hash = hash('sha256', AUTOPAY_SERVICE_ID . '|' . $orderId . '|' . $amountStr . '|' . AUTOPAY_HASH_KEY);

    $payload = [
        'serviceID'     => (int) AUTOPAY_SERVICE_ID,
        'orderID'       => $orderId,
        'amount'        => $amountStr,
        'description'   => mb_substr($description, 0, 512),
        'gatewayID'     => 0,
        'currency'      => 'PLN',
        'language'      => 'PL',
        'customerEmail' => $email,
        'returnURL'     => $returnUrl,
        'hash'          => $hash,
    ];

    $ch = curl_init(AUTOPAY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("Autopay create error HTTP $httpCode | orderId=$orderId | $response");
        $GLOBALS['autopay_last_error'] = ['httpCode' => $httpCode, 'body' => $response];
        return null;
    }

    $data = json_decode($response, true);
    if (($data['status'] ?? '') !== 'SUCCESS') {
        error_log("Autopay create not SUCCESS | orderId=$orderId | " . json_encode($data));
        $GLOBALS['autopay_last_error'] = ['httpCode' => $httpCode, 'body' => $response];
        return null;
    }

    return $data['redirecturl'] ?? null;
}

/**
 * Weryfikuje hash z notyfikacji ITN od Autopay
 */
function autopay_verify_itn(array $data): bool
{
    $received = $data['hash'] ?? '';
    $expected = hash('sha256',
        ($data['serviceID']     ?? '') . '|' .
        ($data['orderID']       ?? '') . '|' .
        ($data['amount']        ?? '') . '|' .
        ($data['currency']      ?? '') . '|' .
        ($data['paymentStatus'] ?? '') . '|' .
        AUTOPAY_HASH_KEY
    );
    return hash_equals($expected, $received);
}

/**
 * Hash do potwierdzenia odbioru ITN
 */
function autopay_confirm_hash(string $orderId): string
{
    return hash('sha256', AUTOPAY_SERVICE_ID . '|' . $orderId . '|' . AUTOPAY_HASH_KEY);
}
