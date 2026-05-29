<?php
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        error_log('booking-init fatal: ' . json_encode($err));
        echo json_encode(['ok' => false, 'error' => 'Wystapil blad serwera. Sprobuj ponownie lub wybierz przelew bankowy.']);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://willaslonce.pl');
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

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$imie     = trim($body['imie']     ?? '');
$nazwisko = trim($body['nazwisko'] ?? '');
$email    = trim($body['email']    ?? '');
$telefon  = trim($body['telefon']  ?? '');
$checkin  = trim($body['checkin']  ?? '');
$checkout = trim($body['checkout'] ?? '');
$goscie   = intval($body['goscie'] ?? 0);
$uwagi    = trim($body['uwagi']    ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidlowy adres email.']);
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

if (!autopay_configured()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Platnosci online sa chwilowo niedostepne. Wybierz przelew bankowy.']);
    exit;
}

$priced = calc_booking_amount($goscie, $checkin, $checkout, __DIR__ . '/../prices.json');
if (!$priced['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $priced['error']]);
    exit;
}
$kwota = $priced['amount'];
$noce  = $priced['nights'];

$sessionId   = 'BOOK-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$nightsStr   = $noce . ($noce === 1 ? ' noc' : ($noce < 5 ? ' noce' : ' nocy'));
$description = "Willa Slonce {$checkin}/{$checkout} {$imie} {$nazwisko} {$goscie}os. $nightsStr";

save_order($sessionId, [
    'type'        => 'booking',
    'sessionId'   => $sessionId,
    'imie'        => $imie,
    'nazwisko'    => $nazwisko,
    'email'       => $email,
    'telefon'     => $telefon,
    'checkin'     => $checkin,
    'checkout'    => $checkout,
    'goscie'      => $goscie,
    'noce'        => $noce,
    'kwota'       => $kwota,
    'uwagi'       => $uwagi,
    'godzina'     => trim($body['godzina'] ?? ''),
    'description' => $description,
    'created'     => date('Y-m-d H:i:s'),
    'status'      => 'pending',
    'payment'     => 'autopay',
]);

$pay = autopay_payment($sessionId, (float) $kwota, $email, $description);

echo json_encode([
    'ok'         => true,
    'gatewayUrl' => $pay['gatewayUrl'],
    'fields'     => $pay['fields'],
    'sessionId'  => $sessionId,
]);
