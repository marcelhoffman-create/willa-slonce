<?php
/**
 * Przelewy24 — Konfiguracja i helpery
 *
 * PRZED URUCHOMIENIEM:
 * 1. Uzupelnij dane z panelu Przelewy24: https://panel.przelewy24.pl
 *    Panel → Moje konto → API (zakładka)
 * 2. Zmień P24_SANDBOX na false po testach
 * 3. Upewnij się że SITE_URL jest poprawny
 */

// === KONFIGURACJA ===
// Laduje p24-credentials.php jesli istnieje (plik lokalny, poza git).
// Jesli nie istnieje — uzupelnij stale ponizej lub wgraj plik przez FTP.
$credFile = __DIR__ . '/p24-credentials.php';
if (file_exists($credFile)) {
    require $credFile;
} else {
    // Fallback — uzupelnij bezposrednio (tylko jesli nie uzywasz p24-credentials.php)
    define('P24_MERCHANT_ID', '');   // np. '123456'
    define('P24_POS_ID',      '');   // zwykle = MERCHANT_ID
    define('P24_CRC',         '');   // klucz CRC
    define('P24_API_KEY',     '');   // API key (haslo REST)
    define('P24_SANDBOX',     true); // false na produkcji!
}

// URL strony (bez trailing slash)
define('SITE_URL', 'https://willaslonce.pl');

// Webhooki n8n
define('N8N_SHOP_WEBHOOK',    'https://n8n.marcelhoffman.pl/webhook/N8LA2zPOksuXPntB/webhook/brenna-shop-order');
define('N8N_BOOKING_WEBHOOK', 'https://n8n.marcelhoffman.pl/webhook/N8LA2zPOksuXPntB/webhook/brenna-book');

// === P24 endpoints ===
define('P24_API_URL', P24_SANDBOX
    ? 'https://sandbox.przelewy24.pl/api/v1'
    : 'https://secure.przelewy24.pl/api/v1'
);
define('P24_PAYMENT_URL', P24_SANDBOX
    ? 'https://sandbox.przelewy24.pl/trnRequest/'
    : 'https://secure.przelewy24.pl/trnRequest/'
);

// === Katalog zamowien ===
define('ORDERS_DIR', __DIR__ . '/orders');
if (!is_dir(ORDERS_DIR)) {
    mkdir(ORDERS_DIR, 0755, true);
}

/**
 * Sprawdz czy credentials sa skonfigurowane
 */
function p24_configured(): bool
{
    return P24_MERCHANT_ID !== '' && P24_CRC !== '' && P24_API_KEY !== '';
}

/**
 * Rejestracja transakcji w P24
 * @return string|null token do przekierowania, lub null przy bledzie
 */
function p24_register(string $sessionId, int $amount, string $description, string $email, string $urlReturn, string $urlNotify): ?string
{
    $payload = [
        'merchantId'  => (int)P24_MERCHANT_ID,
        'posId'       => (int)P24_POS_ID,
        'sessionId'   => $sessionId,
        'amount'      => $amount,
        'currency'    => 'PLN',
        'description' => mb_substr($description, 0, 1024),
        'email'       => $email,
        'country'     => 'PL',
        'language'    => 'pl',
        'urlReturn'   => $urlReturn,
        'urlNotify'   => $urlNotify,
        'sign'        => p24_sign_register($sessionId, (int)P24_MERCHANT_ID, $amount, 'PLN'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(P24_API_URL . '/transaction/register');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(P24_MERCHANT_ID . ':' . P24_API_KEY),
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 201) {
        error_log("P24 register error HTTP $httpCode | session=$sessionId | response=$response");
        return null;
    }

    $data = json_decode($response, true);
    return $data['data']['token'] ?? null;
}

/**
 * Weryfikacja transakcji po IPN od P24
 */
function p24_verify(string $sessionId, int $orderId, int $amount): bool
{
    $payload = [
        'merchantId' => (int)P24_MERCHANT_ID,
        'posId'      => (int)P24_POS_ID,
        'sessionId'  => $sessionId,
        'amount'     => $amount,
        'currency'   => 'PLN',
        'orderId'    => $orderId,
        'sign'       => p24_sign_verify($sessionId, $orderId, $amount),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(P24_API_URL . '/transaction/verify');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(P24_MERCHANT_ID . ':' . P24_API_KEY),
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("P24 verify failed HTTP $httpCode | session=$sessionId | orderId=$orderId | response=$response");
        return false;
    }
    return true;
}

/** SHA384({sessionId, merchantId, amount, currency, crc}) */
function p24_sign_register(string $sessionId, int $merchantId, int $amount, string $currency): string
{
    return hash('sha384', json_encode([
        'sessionId'  => $sessionId,
        'merchantId' => $merchantId,
        'amount'     => $amount,
        'currency'   => $currency,
        'crc'        => P24_CRC,
    ]));
}

/** SHA384({sessionId, orderId, amount, currency, crc}) */
function p24_sign_verify(string $sessionId, int $orderId, int $amount): string
{
    return hash('sha384', json_encode([
        'sessionId' => $sessionId,
        'orderId'   => $orderId,
        'amount'    => $amount,
        'currency'  => 'PLN',
        'crc'       => P24_CRC,
    ]));
}

/** SHA384 podpisu dla IPN notify */
function p24_sign_notify(array $d): string
{
    return hash('sha384', json_encode([
        'merchantId'   => (int)($d['merchantId']   ?? 0),
        'posId'        => (int)($d['posId']         ?? 0),
        'sessionId'    => $d['sessionId']            ?? '',
        'amount'       => (int)($d['amount']         ?? 0),
        'originAmount' => (int)($d['originAmount']   ?? 0),
        'currency'     => $d['currency']             ?? 'PLN',
        'orderId'      => (int)($d['orderId']        ?? 0),
        'methodId'     => (int)($d['methodId']       ?? 0),
        'statement'    => $d['statement']            ?? '',
        'crc'          => P24_CRC,
    ]));
}

/** Zapisz zamowienie do pliku JSON */
function save_order(string $sessionId, array $data): void
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionId);
    file_put_contents(
        ORDERS_DIR . '/' . $safe . '.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/** Odczytaj zamowienie z pliku */
function load_order(string $sessionId): ?array
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionId);
    $file = ORDERS_DIR . '/' . $safe . '.json';
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true) ?: null;
}

/** Wyslij webhook do n8n */
function send_webhook(string $url, array $data): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
