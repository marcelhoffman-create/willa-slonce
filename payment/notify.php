<?php
/**
 * Przelewy24 — IPN webhook (powiadomienie o platnosci)
 * POST /payment/notify.php
 *
 * P24 wysyla ten request po potwierdzeniu platnosci.
 * Musimy:
 * 1. Sprawdzic podpis
 * 2. Zweryfikowac transakcje w API P24
 * 3. Zaktualizowac zamowienie
 * 4. Powiadomic n8n
 */

require __DIR__ . '/p24-config.php';

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid body']);
    exit;
}

$sessionId = trim($data['sessionId'] ?? '');
$orderId   = intval($data['orderId']   ?? 0);
$amount    = intval($data['amount']    ?? 0);

if (empty($sessionId) || $orderId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'missing fields']);
    exit;
}

// Weryfikuj podpis IPN
$expectedSign = p24_sign_notify($data);
if (($data['sign'] ?? '') !== $expectedSign) {
    error_log("P24 notify: invalid sign | session=$sessionId | got={$data['sign']} | expected=$expectedSign");
    http_response_code(400);
    echo json_encode(['error' => 'invalid sign']);
    exit;
}

// Zaladuj zamowienie
$order = load_order($sessionId);
if (!$order) {
    error_log("P24 notify: order not found | session=$sessionId");
    http_response_code(400);
    echo json_encode(['error' => 'order not found']);
    exit;
}

// Sprawdz kwote
if ($amount !== ($order['amountGrosze'] ?? 0)) {
    error_log("P24 notify: amount mismatch | session=$sessionId | got=$amount | expected={$order['amountGrosze']}");
    http_response_code(400);
    echo json_encode(['error' => 'amount mismatch']);
    exit;
}

// Jesli juz oplacone — idempotentnosc
if (($order['status'] ?? '') === 'paid') {
    echo json_encode(['status' => 200]);
    exit;
}

// Weryfikacja w P24
if (!p24_verify($sessionId, $orderId, $amount)) {
    error_log("P24 notify: verification failed | session=$sessionId");
    http_response_code(400);
    echo json_encode(['error' => 'verification failed']);
    exit;
}

// Aktualizuj status
$order['status']   = 'paid';
$order['orderId']  = $orderId;
$order['paidAt']   = date('Y-m-d H:i:s');
$order['p24Amount'] = $amount;
save_order($sessionId, $order);

// Wyslij do n8n
$webhookUrl = ($order['type'] ?? '') === 'booking' ? N8N_BOOKING_WEBHOOK : N8N_SHOP_WEBHOOK;
send_webhook($webhookUrl, array_merge($order, [
    'payment_method' => 'przelewy24',
    'p24_order_id'   => $orderId,
    'source'         => 'p24_notify',
]));

echo json_encode(['status' => 200]);
