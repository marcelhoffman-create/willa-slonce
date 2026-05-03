<?php
/**
 * Inicjalizacja platnosci P24 dla rezerwacji domku
 * POST /payment/booking-init.php
 *
 * Body JSON (te same pola co formularz rezerwacji):
 * {
 *   "imie": "Jan", "nazwisko": "Kowalski",
 *   "email": "jan@example.com", "telefon": "600123456",
 *   "checkin": "2026-07-01", "checkout": "2026-07-05",
 *   "goscie": "4", "kwota": 2080, "noce": 4
 * }
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

if (!p24_configured()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Platnosci online sa tymczasowo niedostepne. Prosimy o przelew bankowy.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Walidacja
$imie     = trim($body['imie']     ?? '');
$nazwisko = trim($body['nazwisko'] ?? '');
$email    = trim($body['email']    ?? '');
$telefon  = trim($body['telefon']  ?? '');
$checkin  = trim($body['checkin']  ?? '');
$checkout = trim($body['checkout'] ?? '');
$goscie   = intval($body['goscie'] ?? 0);
$kwota    = intval($body['kwota']  ?? 0);
$noce     = intval($body['noce']   ?? 0);
$uwagi    = trim($body['uwagi']    ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy adres email.']);
    exit;
}

if (empty($imie) || empty($nazwisko)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brakuje imienia i nazwiska.']);
    exit;
}

if (empty($checkin) || empty($checkout)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brakuje dat rezerwacji.']);
    exit;
}

if ($kwota < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowa kwota.']);
    exit;
}

// Formatuj daty
function fmt($d) {
    if (!$d) return '';
    $p = explode('-', $d);
    return isset($p[2]) ? ($p[2] . '.' . $p[1] . '.' . $p[0]) : $d;
}

$sessionId = 'BOOK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$nights    = $noce > 0 ? $noce : 1;
$nightsStr = $nights . ($nights === 1 ? ' noc' : ($nights < 5 ? ' noce' : ' nocy'));
$description = "Rezerwacja Willa Slonce {$checkin}/{$checkout} — $imie $nazwisko, {$goscie}os., $nightsStr";

$urlReturn = SITE_URL . '/payment/return.php?type=booking&session=' . urlencode($sessionId);
$urlNotify = SITE_URL . '/payment/notify.php';

$amountGrosze = $kwota * 100;

save_order($sessionId, [
    'type'         => 'booking',
    'sessionId'    => $sessionId,
    'imie'         => $imie,
    'nazwisko'     => $nazwisko,
    'email'        => $email,
    'telefon'      => $telefon,
    'checkin'      => $checkin,
    'checkout'     => $checkout,
    'goscie'       => $goscie,
    'noce'         => $noce,
    'kwota'        => $kwota,
    'zaliczka'     => $kwota,
    'uwagi'        => $uwagi,
    'godzina'      => trim($body['godzina'] ?? ''),
    'amountGrosze' => $amountGrosze,
    'description'  => $description,
    'created'      => date('Y-m-d H:i:s'),
    'status'       => 'pending',
]);

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
