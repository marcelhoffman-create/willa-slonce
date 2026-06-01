<?php
/**
 * Autopay ITN — notyfikacja o platnosci.
 * POST /payment/autopay-notify.php  (pole `transactions` = base64(XML))
 * URL skonfigurowac w panel.autopay.eu -> usluga -> adres powiadomien.
 */

require __DIR__ . '/autopay-config.php';

header('Content-Type: application/xml; charset=utf-8');

// === TEMP DEBUG ITN (usunac po diagnozie) ===
$__rawInput = file_get_contents('php://input');
$__dbg  = '[' . date('Y-m-d H:i:s') . "] ITN hit\n"
        . 'METHOD: ' . ($_SERVER['REQUEST_METHOD'] ?? '') . "\n"
        . 'CONTENT_TYPE: ' . ($_SERVER['CONTENT_TYPE'] ?? '') . "\n"
        . 'POST_KEYS: ' . implode(',', array_keys($_POST)) . "\n"
        . 'GET_KEYS: ' . implode(',', array_keys($_GET)) . "\n"
        . 'RAW_INPUT: ' . substr($__rawInput, 0, 5000) . "\n";
if (!empty($_POST['transactions'])) {
    $__dbg .= "DECODED_XML:\n" . base64_decode($_POST['transactions'], true) . "\n";
}
$__dbg .= "----\n";
@file_put_contents(__DIR__ . '/orders/_itn_raw.log', $__dbg, FILE_APPEND | LOCK_EX);
// === /TEMP DEBUG ===

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

// Notyfikacja musi dotyczyc naszej uslugi
if ((string) AUTOPAY_SERVICE_ID !== '' && $parsed['serviceID'] !== (string) AUTOPAY_SERVICE_ID) {
    error_log('Autopay ITN: niezgodny serviceID | otrzymano=' . $parsed['serviceID']);
    http_response_code(400);
    echo '<error>service mismatch</error>';
    exit;
}

// Weryfikacja hasha CALEGO komunikatu (jeden hash dla transactionList)
if (!autopay_verify_message_hash($parsed, AUTOPAY_HASH_KEY, AUTOPAY_HASH_ALGO, AUTOPAY_HASH_SEP)) {
    // TEMP: log wartosci (BEZ klucza) na wypadek rozjazdu kolejnosci pol — usunac po potwierdzeniu
    @file_put_contents(__DIR__ . '/orders/_itn_raw.log',
        '[' . date('Y-m-d H:i:s') . "] HASH MISMATCH\n"
        . 'values=' . implode('|', $parsed['hashValues'] ?? []) . "\n"
        . 'received=' . ($parsed['hash'] ?? '') . "\n----\n", FILE_APPEND | LOCK_EX);
    http_response_code(400);
    echo '<error>invalid hash</error>';
    exit;
}

$confirmations = [];
foreach ($parsed['transactions'] as $tx) {
    $orderId = $tx['orderID'] ?? '';
    if ($orderId === '') continue;

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
        // Kwota z ITN musi zgadzac sie z kwota zamowienia (obrona w glab)
        $expected  = ($order['type'] ?? '') === 'booking' ? ($order['kwota'] ?? 0) : ($order['total'] ?? 0);
        $itnAmount = number_format((float) ($tx['amount'] ?? 0), 2, '.', '');
        if ($itnAmount !== number_format((float) $expected, 2, '.', '')) {
            error_log("Autopay ITN: niezgodna kwota | orderId=$orderId | ITN=$itnAmount oczekiwano=" . number_format((float) $expected, 2, '.', ''));
            $confirmations[$orderId] = 'NOTCONFIRMED';
            continue;
        }

        $order['status']      = 'paid';
        $order['paidAt']      = date('Y-m-d H:i:s');
        $order['autopayData'] = $tx;
        save_order($orderId, $order);

        $webhookUrl = ($order['type'] ?? '') === 'booking' ? N8N_BOOKING_WEBHOOK : N8N_SHOP_WEBHOOK;
        send_webhook($webhookUrl, array_merge($order, ['payment_method' => 'autopay', 'source' => 'autopay_itn']));
        autopay_admin_paid_email($order);
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


/**
 * Mail do admina po zaksięgowaniu płatności Autopay (analogicznie do przelewu).
 */
function autopay_admin_paid_email(array $o): void
{
    $adminEmail = 'marcelhoffman@gmail.com';
    $isBooking  = ($o['type'] ?? '') === 'booking';
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    if ($isBooking) {
        $who   = trim(($o['imie'] ?? '') . ' ' . ($o['nazwisko'] ?? ''));
        $title = 'Opłacona rezerwacja - ' . $who . ' - ' . ($o['checkin'] ?? '');
        $rows  = '<tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;width:40%;">Gość</td><td style="padding:6px 12px;">' . $e($who) . '</td></tr>'
               . '<tr><td style="padding:6px 12px;font-weight:bold;">Email</td><td style="padding:6px 12px;">' . $e($o['email'] ?? '') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Telefon</td><td style="padding:6px 12px;">' . $e($o['telefon'] ?? '') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;font-weight:bold;">Przyjazd</td><td style="padding:6px 12px;">' . $e($o['checkin'] ?? '') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Wyjazd</td><td style="padding:6px 12px;">' . $e($o['checkout'] ?? '') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;font-weight:bold;">Noce / goście</td><td style="padding:6px 12px;">' . ((int) ($o['noce'] ?? 0)) . ' / ' . ((int) ($o['goscie'] ?? 0)) . '</td></tr>';
        $kwota = (int) ($o['kwota'] ?? 0);
    } else {
        $who   = $o['name'] ?? '';
        $title = 'Opłacone zamówienie (sklep) - ' . $who;
        $items = '';
        foreach (($o['items'] ?? []) as $it) {
            $items .= $e($it['name'] ?? '') . ' x' . ((int) ($it['qty'] ?? 1)) . '<br>';
        }
        $rows  = '<tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;width:40%;">Klient</td><td style="padding:6px 12px;">' . $e($who) . '</td></tr>'
               . '<tr><td style="padding:6px 12px;font-weight:bold;">Email</td><td style="padding:6px 12px;">' . $e($o['email'] ?? '') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Telefon</td><td style="padding:6px 12px;">' . $e($o['phone'] ?? '') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;font-weight:bold;">Produkty</td><td style="padding:6px 12px;">' . ($items ?: '-') . '</td></tr>'
               . '<tr><td style="padding:6px 12px;background:#f5f0e8;font-weight:bold;">Dostawa</td><td style="padding:6px 12px;">' . $e($o['delivery'] ?? '') . (!empty($o['address']) ? ' / ' . $e($o['address']) : '') . '</td></tr>';
        $kwota = (int) ($o['total'] ?? 0);
    }

    $subject = '=?UTF-8?B?' . base64_encode($title) . '?=';
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;color:#222;max-width:600px;margin:0 auto;padding:20px;">'
          . '<h2 style="color:#C17817;">Płatność zaksięgowana - Willa Słońce</h2>'
          . '<table style="border-collapse:collapse;width:100%;">' . $rows
          . '<tr><td style="padding:6px 12px;font-weight:bold;font-size:1.1em;">Kwota</td><td style="padding:6px 12px;font-size:1.1em;color:#C17817;font-weight:bold;">' . $kwota . ' zł</td></tr>'
          . '</table>'
          . '<p style="margin-top:20px;color:#888;font-size:.85em;">Płatność: Autopay (online) | Ref: ' . $e($o['sessionId'] ?? '') . ' | ' . date('d.m.Y H:i') . '</p>'
          . '</body></html>';

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: Willa Slonce <noreply@willaslonce.pl>' . "\r\n";

    @mail($adminEmail, $subject, $html, $headers);
}

