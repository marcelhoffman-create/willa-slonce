<?php
/**
 * Inicjalizacja platnosci P24 dla zamówienia ze sklepu
 * POST /payment/shop-init.php
 *
 * Body JSON:
 * {
 *   "email": "klient@example.com",
 *   "name": "Jan Kowalski",
 *   "phone": "600123456",
 *   "delivery": "domek|kurier",
 *   "address": "ul. Przykładowa 1, 00-001 Warszawa",
 *   "items": [{"id":"...", "name":"...", "price":49, "qty":2}]
 * }
 *
 * Odpowiedź 200:
 * { "ok": true, "redirectUrl": "https://secure.przelewy24.pl/trnRequest/TOKEN", "sessionId": "..." }
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

require __DIR__ . '/p24-config.php';

// Sprawdz czy P24 jest skonfigurowane
if (!p24_configured()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Platnoci online sa tymczasowo niedostepne. Prosimy o przelew bankowy.']);
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
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy adres email.']);
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
    echo json_encode(['ok' => false, 'error' => 'Podaj adres dostawy dla wysyłki kurierskiej.']);
    exit;
}

// Oblicz sume z items
$subtotal = 0;
$cleanItems = [];
foreach ($items as $item) {
    $price = intval($item['price'] ?? 0);
    $qty   = max(1, min(99, intval($item['qty'] ?? 1)));
    $iname = mb_substr(trim($item['name'] ?? 'Produkt'), 0, 200);

    if ($price < 1 || $price > 99999) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nieprawidłowa cena produktu: ' . htmlspecialchars($iname)]);
        exit;
    }

    $subtotal += $price * $qty;
    $cleanItems[] = [
        'id'    => mb_substr(trim($item['id'] ?? ''), 0, 100),
        'name'  => $iname,
        'price' => $price,
        'qty'   => $qty,
    ];
}

// Koszt wysylki
$shipping = ($delivery === 'kurier' && $subtotal < 150) ? 15 : 0;
$total    = $subtotal + $shipping;

// Session ID
$sessionId = 'SHOP-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

// Opis dla P24
$itemsLabels = array_map(function($i) { return $i['name'] . ' ×' . $i['qty']; }, $cleanItems);
$description = 'Sklep Willa Słońce: ' . implode(', ', $itemsLabels);

// URL-e
$urlReturn = SITE_URL . '/payment/return.php?type=shop&session=' . urlencode($sessionId);
$urlNotify = SITE_URL . '/payment/notify.php';

// Kwota w groszach
$amountGrosze = $total * 100;

// Zapisz zamowienie (zanim przekierujemy, zeby notify moglo je odczytac)
save_order($sessionId, [
    'type'         => 'shop',
    'sessionId'    => $sessionId,
    'email'        => $email,
    'name'         => $name,
    'phone'        => $phone,
    'delivery'     => $delivery,
    'address'      => $address,
    'items'        => $cleanItems,
    'subtotal'     => $subtotal,
    'shipping'     => $shipping,
    'total'        => $total,
    'amountGrosze' => $amountGrosze,
    'description'  => $description,
    'created'      => date('Y-m-d H:i:s'),
    'status'       => 'pending',
]);

// Zarejestruj w P24
$token = p24_register($sessionId, $amountGrosze, $description, $email, $urlReturn, $urlNotify);

if (!$token) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Błąd inicjalizacji płatności. Spróbuj ponownie lub wybierz przelew bankowy.']);
    exit;
}

echo json_encode([
    'ok'          => true,
    'redirectUrl' => P24_PAYMENT_URL . $token,
    'sessionId'   => $sessionId,
]);
