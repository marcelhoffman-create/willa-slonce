<?php
/**
 * Rezerwacja przelewowa
 * POST /payment/booking-bank.php
 *
 * Zapisuje rezerwację do pliku JSON i wysyła mail bezpośrednio przez mail().
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

$imie     = mb_substr(trim($body['imie']     ?? ''), 0, 100);
$nazwisko = mb_substr(trim($body['nazwisko'] ?? ''), 0, 100);
$email    = trim($body['email']              ?? '');
$telefon  = mb_substr(trim($body['telefon']  ?? ''), 0, 50);
$checkin  = trim($body['checkin']            ?? '');
$checkout = trim($body['checkout']           ?? '');
$noce     = intval($body['noce']             ?? 0);
$kwota    = intval($body['kwota']            ?? 0);
$uwagi    = mb_substr(trim($body['uwagi']    ?? ''), 0, 500);

if (empty($imie) || empty($checkin) || $kwota < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brakuje danych rezerwacji.']);
    exit;
}

$bookingId = 'BBOOK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$data = [
    'type'      => 'booking_bank',
    'bookingId' => $bookingId,
    'imie'      => $imie,
    'nazwisko'  => $nazwisko,
    'email'     => $email,
    'telefon'   => $telefon,
    'checkin'   => $checkin,
    'checkout'  => $checkout,
    'noce'      => $noce,
    'kwota'     => $kwota,
    'uwagi'     => $uwagi,
    'created'   => date('Y-m-d H:i:s'),
    'status'    => 'awaiting_payment',
];

save_order($bookingId, $data);

$adminEmail = 'marcelhoffman@gmail.com';
$subject    = '=?UTF-8?B?' . base64_encode('Nowa rezerwacja — ' . $imie . ' ' . $nazwisko . ' — ' . $checkin) . '?=';

$htmlBody = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;color:#222;max-width:600px;margin:0 auto;padding:20px;">
<h2 style="color:#C17817;">Nowa rezerwacja — Willa Słońce Brenna</h2>
<table style="border-collapse:collapse;width:100%;">
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;width:40%;">ID rezerwacji</td><td style="padding:6px 12px;">' . htmlspecialchars($bookingId) . '</td></tr>
  <tr><td style="padding:6px 12px;font-weight:bold;">Gość</td><td style="padding:6px 12px;">' . htmlspecialchars($imie . ' ' . $nazwisko) . '</td></tr>
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Email</td><td style="padding:6px 12px;">' . htmlspecialchars($email) . '</td></tr>
  <tr><td style="padding:6px 12px;font-weight:bold;">Telefon</td><td style="padding:6px 12px;">' . htmlspecialchars($telefon) . '</td></tr>
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Check-in</td><td style="padding:6px 12px;">' . htmlspecialchars($checkin) . '</td></tr>
  <tr><td style="padding:6px 12px;font-weight:bold;">Check-out</td><td style="padding:6px 12px;">' . htmlspecialchars($checkout) . '</td></tr>
  <tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Noce</td><td style="padding:6px 12px;">' . $noce . '</td></tr>
  ' . ($uwagi ? '<tr><td style="padding:6px 12px;font-weight:bold;">Uwagi</td><td style="padding:6px 12px;">' . nl2br(htmlspecialchars($uwagi)) . '</td></tr>' : '') . '
  <tr><td style="padding:6px 12px;font-weight:bold;font-size:1.1em;">Kwota</td><td style="padding:6px 12px;font-size:1.1em;color:#C17817;font-weight:bold;">' . $kwota . ' zł</td></tr>
</table>
<p style="margin-top:20px;color:#888;font-size:.85em;">Rezerwacja z dnia ' . date('d.m.Y H:i') . ' | Płatność: przelew bankowy | Status: oczekuje na wpłatę</p>
</body></html>';

$headers  = 'MIME-Version: 1.0' . "\r\n";
$headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
$headers .= 'From: Willa Slonce <noreply@willaslonce.pl>' . "\r\n";

mail($adminEmail, $subject, $htmlBody, $headers);

// Opcjonalnie: spróbuj też n8n
if (defined('N8N_BOOKING_WEBHOOK') && N8N_BOOKING_WEBHOOK !== '') {
    send_webhook(N8N_BOOKING_WEBHOOK, array_merge($body, ['bookingId' => $bookingId]));
}

echo json_encode(['ok' => true, 'bookingId' => $bookingId]);
