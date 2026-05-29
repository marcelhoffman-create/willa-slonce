<?php
/**
 * Autopay — konfiguracja i glue. Czysta logika: autopay-lib.php.
 * Dokumentacja: https://developers.autopay.pl/online/dokumentacja
 */

require_once __DIR__ . '/p24-config.php';   // SITE_URL, ORDERS_DIR, save_order, load_order, send_webhook, N8N_*
require_once __DIR__ . '/autopay-lib.php';  // czyste funkcje

// Dane dostepowe — plik poza repo (wzorzec jak P24)
$autopayCred = __DIR__ . '/autopay-credentials.php';
if (file_exists($autopayCred)) {
    require $autopayCred;
} else {
    define('AUTOPAY_SERVICE_ID', '');
    define('AUTOPAY_HASH_KEY',   '');
    define('AUTOPAY_HASH_ALGO',  'sha256');
    define('AUTOPAY_HASH_SEP',   '|');
    define('AUTOPAY_TEST_MODE',  false);
}

define('AUTOPAY_GATEWAY_URL', AUTOPAY_TEST_MODE
    ? 'https://testpay.autopay.eu/payment'
    : 'https://pay.autopay.eu/payment');

function autopay_configured(): bool
{
    return AUTOPAY_SERVICE_ID !== '' && AUTOPAY_HASH_KEY !== '';
}

/** Wrapper: pola formularza bramki dla zamowienia. */
function autopay_payment(string $orderId, float $amount, string $email, string $description): array
{
    return autopay_payment_fields(
        AUTOPAY_SERVICE_ID, AUTOPAY_HASH_KEY, AUTOPAY_HASH_ALGO, AUTOPAY_HASH_SEP,
        AUTOPAY_GATEWAY_URL, $orderId, $amount, $email, $description
    );
}
