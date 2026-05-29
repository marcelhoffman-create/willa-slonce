<?php
/**
 * Inicjalizacja platnosci Autopay dla zamowienia ze sklepu
 * POST /payment/shop-init.php
 *
 * Body JSON:
 * {
 *   "email": "klient@example.com",
 *   "name": "Jan Kowalski",
 *   "phone": "600123456",
 *   "delivery": "domek|kurier",
 *   "address": "ul. Przykladowa 1, 00-001 Warszawa",
 *   "items": [{"id":"...", "qty":2}]
 * }
 *
 * Odpowiedz 200:
 * { "ok": true, "gatewayUrl": "https://pay.autopay.eu/payment", "fields": {...}, "sessionId": "..." }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/autopay-config.php';
require_once __DIR__ . '/pricing.php';

if (!autopay_configured()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Platnosci online sa chwilowo niedostepne. Prosimy o przelew bankowy.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Walidacja
$email    = trim($body['email']    ?? '');
$name     = trim($body['name']     ?? '');
$phone    = trim($body['phone']    ?? '');
$delivery = trim($body['delivery'] ?? 'domek');
$address  = trim($body['address']  ?? '');
$items    = $body['items']         ?? [];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidlowy adres email.']);
    exit;
}

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brakuje imienia i nazwiska.']);
    exit;
}

if (empty($items) || !is_array($items)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Koszyk jest pusty.']);
    exit;
}

if ($delivery === 'kurier' && empty($address)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Podaj adres dostawy dla wysylki kurierskiej.']);
    exit;
}

// Ceny z katalogu serwera (nie od klienta)
$priced = calc_shop_items($items, __DIR__ . '/../products.json');
if (!$priced['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $priced['error']]);
    exit;
}
$cleanItems = $priced['items'];
$subtotal   = $priced['subtotal'];
$shipping   = ($delivery === 'kurier' && $subtotal < 150) ? 15 : 0;
$total      = $subtotal + $shipping;

$sessionId   = 'SHOP-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$itemsLabels = array_map(fn($i) => $i['name'] . ' x' . $i['qty'], $cleanItems);
$description = 'Sklep Willa Slonce: ' . implode(', ', $itemsLabels);

save_order($sessionId, [
    'type'        => 'shop',
    'sessionId'   => $sessionId,
    'email'       => $email,
    'name'        => $name,
    'phone'       => $phone,
    'delivery'    => $delivery,
    'address'     => $address,
    'items'       => $cleanItems,
    'subtotal'    => $subtotal,
    'shipping'    => $shipping,
    'total'       => $total,
    'description' => $description,
    'created'     => date('Y-m-d H:i:s'),
    'status'      => 'pending',
    'payment'     => 'autopay',
]);

$pay = autopay_payment($sessionId, (float) $total, $email, $description);

echo json_encode([
    'ok'         => true,
    'gatewayUrl' => $pay['gatewayUrl'],
    'fields'     => $pay['fields'],
    'sessionId'  => $sessionId,
]);
