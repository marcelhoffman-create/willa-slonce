<?php
/**
 * Zamówienie przelewowe ze sklepu
 * POST /payment/shop-bank.php
 *
 * Zapisuje zamówienie do pliku JSON i wysyła mail bezpośrednio przez mail().
 * Zero zależności od n8n — działa zawsze.
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

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Walidacja podstawowa
$name     = mb_substr(trim($body['imie']      ?? ''), 0, 200);
$email    = trim($body['email']               ?? '');
$phone    = mb_substr(trim($body['telefon']   ?? ''), 0, 50);
$delivery = trim($body['dostawa']             ?? 'domek');
$address  = mb_substr(trim($body['adres']     ?? ''), 0, 300);
$order    = mb_substr(trim($body['zamowienie'] ?? ''), 0, 1000);
$total    = intval($body['kwota']             ?? 0);

if (empty($name) || $total < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brakuje danych zamówienia.']);
    exit;
}

// ID zamówienia
$orderId = 'BANK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$data = [
    'type'      => 'shop_bank',
    'orderId'   => $orderId,
    'name'      => $name,
    'email'     => $email,
    'phone'     => $phone,
    'delivery'  => $delivery,
    'address'   => $address,
    'order'     => $order,
    'total'     => $total,
    'created'   => date('Y-m-d H:i:s'),
    'status'    => 'awaiting_payment',
];

// Zapisz do pliku
save_order($orderId, $data);

// Wyślij mail do właściciela
$adminEmail = 'marcelhoffman@gmail.com';
$subject    = '=?UTF-8?B?' . base64_encode('Nowe zamówienie sklep — ' . $name . ' — ' . $total . ' zł') . '?=';

$deliveryLabel = ($delivery === 'kurier') ? 'Kurier' : 'Odbiór w domku';

$htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;color:#222;max-width:600px;margin:0 auto;padding:20px;">
<h2 style="color:#C17817;">Nowe zamówienie — Sklep Willa Słońce</h2>
<table style="border-collapse:collapse;width:100%;">
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;width:40%;">ID zamówienia</td><td style="padding:6px 12px;">' . htmlspecialchars($orderId) . '</td></tr>
  <tr><td style="padding:6px 12px;font-weight:bold;">Imię i nazwisko</td><td style="padding:6px 12px;">' . htmlspecialchars($name) . '</td></tr>
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Email</td><td style="padding:6px 12px;">' . htmlspecialchars($email) . '</td></tr>
  <tr><td style="padding:6px 12px;font-weight:bold;">Telefon</td><td style="padding:6px 12px;">' . htmlspecialchars($phone) . '</td></tr>
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Dostawa</td><td style="padding:6px 12px;">' . htmlspecialchars($deliveryLabel) . '</td></tr>
  ' . ($address ? '<tr><td style="padding:6px 12px;font-weight:bold;">Adres</td><td style="padding:6px 12px;">' . htmlspecialchars($address) . '</td></tr>' : '') . '
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Zamówienie</td><td style="padding:6px 12px;">' . nl2br(htmlspecialchars($order)) . '</td></tr>
  <tr><td style="padding:6px 12px;font-weight:bold;font-size:1.1em;">Kwota</td><td style="padding:6px 12px;font-size:1.1em;color:#C17817;font-weight:bold;">' . $total . ' zł</td></tr>
</table>
<p style="margin-top:20px;color:#888;font-size:.85em;">Zamówienie z dnia ' . date('d.m.Y H:i') . ' | Płatność: przelew bankowy | Status: oczekuje na wpłatę</p>
</body></html>';

$headers  = 'MIME-Version: 1.0' . "\r\n";
$headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
$headers .= 'From: Willa Slonce <noreply@willaslonce.pl>' . "\r\n";

$mailSent = mail($adminEmail, $subject, $htmlBody, $headers);

// Opcjonalnie: spróbuj też n8n (jeśli skonfigurowane), ale nie blokuj
if (defined('N8N_SHOP_WEBHOOK') && N8N_SHOP_WEBHOOK !== '') {
    send_webhook(N8N_SHOP_WEBHOOK, array_merge($body, ['orderId' => $orderId]));
}

echo json_encode(['ok' => true, 'orderId' => $orderId]);
