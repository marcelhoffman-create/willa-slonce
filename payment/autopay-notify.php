<?php
/**
 * Autopay ITN — notyfikacja o platnosci.
 * POST /payment/autopay-notify.php  (pole `transactions` = base64(XML))
 * URL skonfigurowac w panel.autopay.eu -> usluga -> adres powiadomien.
 */

require __DIR__ . '/autopay-config.php';

header('Content-Type: application/xml; charset=utf-8');

$transactionsParam = $_POST['transactions'] ?? '';
if ($transactionsParam === '') {
    error_log('Autopay ITN: brak pola transactions | ' . file_get_contents('php://input'));
    http_response_code(400);
    echo '<error>missing transactions</error>';
    exit;
}

$parsed = autopay_parse_itn($transactionsParam);
if ($parsed === null) {
    error_log('Autopay ITN: nie udalo sie sparsowac | ' . $transactionsParam);
    http_response_code(400);
    echo '<error>invalid xml</error>';
    exit;
}

$confirmations = [];
foreach ($parsed['transactions'] as $tx) {
    $orderId = $tx['orderID'] ?? '';
    if ($orderId === '') continue;

    if (!autopay_verify_tx_hash($tx, AUTOPAY_HASH_KEY, AUTOPAY_HASH_ALGO, AUTOPAY_HASH_SEP)) {
        error_log("Autopay ITN: zly hash | orderId=$orderId | " . json_encode($tx));
        $confirmations[$orderId] = 'NOTCONFIRMED';
        continue;
    }

    $order = load_order($orderId);
    if (!$order) {
        error_log("Autopay ITN: brak zamowienia | orderId=$orderId");
        $confirmations[$orderId] = 'NOTCONFIRMED';
        continue;
    }

    $status = $tx['paymentStatus'] ?? '';

    // Idempotentnosc — juz oplacone
    if (($order['status'] ?? '') === 'paid') {
        $confirmations[$orderId] = 'CONFIRMED';
        continue;
    }

    if ($status === 'SUCCESS') {
        $order['status']      = 'paid';
        $order['paidAt']      = date('Y-m-d H:i:s');
        $order['autopayData'] = $tx;
        save_order($orderId, $order);

        $webhookUrl = ($order['type'] ?? '') === 'booking' ? N8N_BOOKING_WEBHOOK : N8N_SHOP_WEBHOOK;
        send_webhook($webhookUrl, array_merge($order, ['payment_method' => 'autopay', 'source' => 'autopay_itn']));
        $confirmations[$orderId] = 'CONFIRMED';
    } elseif ($status === 'FAILURE') {
        $order['status'] = 'failed';
        save_order($orderId, $order);
        $confirmations[$orderId] = 'CONFIRMED'; // potwierdzamy odbior notyfikacji (porazka tez)
    } else {
        // PENDING — potwierdzamy odbior, status bez zmian
        $confirmations[$orderId] = 'CONFIRMED';
    }
}

if (empty($confirmations)) {
    http_response_code(400);
    echo '<error>no transactions</error>';
    exit;
}

echo autopay_confirmation_xml((string) AUTOPAY_SERVICE_ID, $confirmations, AUTOPAY_HASH_KEY, AUTOPAY_HASH_ALGO, AUTOPAY_HASH_SEP);
