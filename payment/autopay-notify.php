<?php
/**
 * Autopay ITN — notyfikacja o platnosci
 * POST /payment/autopay-notify.php
 *
 * Autopay wysyla ten request po potwierdzeniu platnosci.
 * Nalezy skonfigurowac ten URL w panelu Autopay:
 * panel.autopay.eu → Uslugi → [usługa] → Adres powiadomien
 */

require __DIR__ . '/autopay-config.php';

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid body']);
    exit;
}

// Weryfikuj hash
if (!autopay_verify_itn($data)) {
    error_log('Autopay ITN: invalid hash | ' . $raw);
    http_response_code(400);
    echo json_encode(['error' => 'invalid hash']);
    exit;
}

$orderId       = $data['orderID']       ?? '';
$paymentStatus = $data['paymentStatus'] ?? '';

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['error' => 'missing orderID']);
    exit;
}

$order = load_order($orderId);
if (!$order) {
    error_log("Autopay ITN: order not found | orderId=$orderId");
    http_response_code(400);
    echo json_encode(['error' => 'order not found']);
    exit;
}

// Idempotentnosc — jesli juz oplacone, tylko potwierdz
if (($order['status'] ?? '') === 'paid') {
    echo json_encode([
        'serviceID' => (int) AUTOPAY_SERVICE_ID,
        'orderID'   => $orderId,
        'hash'      => autopay_confirm_hash($orderId),
    ]);
    exit;
}

if ($paymentStatus === 'SUCCESS') {
    $order['status']      = 'paid';
    $order['paidAt']      = date('Y-m-d H:i:s');
    $order['autopayData'] = $data;
    save_order($orderId, $order);

    $webhookUrl = ($order['type'] ?? '') === 'booking' ? N8N_BOOKING_WEBHOOK : N8N_SHOP_WEBHOOK;
    send_webhook($webhookUrl, array_merge($order, [
        'payment_method' => 'autopay',
        'source'         => 'autopay_itn',
    ]));
}

// Potwierdzenie odbioru do Autopay
echo json_encode([
    'serviceID' => (int) AUTOPAY_SERVICE_ID,
    'orderID'   => $orderId,
    'hash'      => autopay_confirm_hash($orderId),
]);
