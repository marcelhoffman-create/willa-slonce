<?php
/**
 * Lista zamowien dla panelu admina (PII — tylko POST + haslo).
 * POST /admin-orders.php  body: { "password": "..." }
 */
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

require_once __DIR__ . '/admin-auth.php';

$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!admin_password_valid($body['password'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// --- Akcje na zamowieniu (mark-paid / archive) ---
$action = $body['action'] ?? 'list';
if ($action === 'mark-paid' || $action === 'archive') {
    $id = safe_order_id((string) ($body['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Brak id zamowienia']);
        exit;
    }
    $file = __DIR__ . '/payment/orders/' . $id . '.json';
    if (!is_file($file)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Nie znaleziono zamowienia']);
        exit;
    }

    if ($action === 'mark-paid') {
        $o = json_decode(file_get_contents($file), true) ?: [];
        $o['status']       = 'paid';
        $o['paidAt']       = date('Y-m-d H:i:s');
        $o['paidManually'] = true; // recznie oznaczone w panelu (bez ITN, bez maila/webhooka)
        file_put_contents($file, json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo json_encode(['ok' => true]);
        exit;
    }

    // archive — przenies do _archive (dziedziczy .htaccess Require all denied)
    $archiveDir = __DIR__ . '/payment/orders/_archive';
    if (!is_dir($archiveDir)) { @mkdir($archiveDir, 0755, true); }
    if (!@rename($file, $archiveDir . '/' . $id . '.json')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Blad archiwizacji']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

$orders = [];
foreach (glob(__DIR__ . '/payment/orders/*.json') ?: [] as $file) {
    $o = json_decode(@file_get_contents($file), true);
    if (!is_array($o)) continue;
    $orders[] = [
        'id'        => basename($file, '.json'),
        'type'      => $o['type']      ?? '',
        'sessionId' => $o['sessionId'] ?? '',
        'created'   => $o['created']   ?? '',
        'status'    => $o['status']    ?? '',
        'paidAt'    => $o['paidAt']    ?? '',
        'kwota'     => $o['kwota']     ?? ($o['total'] ?? 0),
        'imie'      => $o['imie']      ?? '',
        'nazwisko'  => $o['nazwisko']  ?? '',
        'name'      => $o['name']      ?? '',
        'email'     => $o['email']     ?? '',
        'telefon'   => $o['telefon']   ?? ($o['phone'] ?? ''),
        'checkin'   => $o['checkin']   ?? '',
        'checkout'  => $o['checkout']  ?? '',
        'noce'      => $o['noce']      ?? 0,
        'goscie'    => $o['goscie']    ?? 0,
        'items'     => $o['items']     ?? [],
        'order'     => $o['order']     ?? '',
        'delivery'  => $o['delivery']  ?? '',
        'address'   => $o['address']   ?? '',
    ];
}
usort($orders, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));

echo json_encode(['ok' => true, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
