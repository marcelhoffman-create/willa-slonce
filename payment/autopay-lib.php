<?php
/**
 * Czyste funkcje Autopay (klasyczna bramka). Bez efektow ubocznych.
 * Protokol: formularz POST na bramke + ITN XML.
 * Dokumentacja: https://developers.autopay.pl/online/dokumentacja
 */

/**
 * Hash Autopay: wartosci (puste pomijane) + klucz, polaczone separatorem, funkcja $algo.
 * Przyklad z dokumentacji: SHA256("2|100|1.50|2test2").
 */
function autopay_hash_raw(array $values, string $key, string $algo, string $sep): string
{
    $parts = array_values(array_filter($values, fn($v) => $v !== '' && $v !== null));
    $parts[] = $key;
    return hash($algo, implode($sep, $parts));
}

/**
 * Buduje pola formularza do POST na bramke Autopay.
 * Kolejnosc pol = kolejnosc liczenia hasha (kanoniczna wg dokumentacji).
 * Zwraca ['gatewayUrl' => ..., 'fields' => [PascalCase => wartosc, ..., 'Hash' => ...]].
 */
function autopay_payment_fields(
    string $serviceId, string $key, string $algo, string $sep, string $gatewayUrl,
    string $orderId, float $amount, string $email, string $description
): array {
    // Kolejnosc kanoniczna: ServiceID, OrderID, Amount, Description, Currency, CustomerEmail
    $ordered = [
        'ServiceID'     => $serviceId,
        'OrderID'       => $orderId,
        'Amount'        => number_format($amount, 2, '.', ''),
        'Description'   => mb_substr($description, 0, 79),
        'Currency'      => 'PLN',
        'CustomerEmail' => $email,
    ];
    $hash = autopay_hash_raw(array_values($ordered), $key, $algo, $sep);
    return ['gatewayUrl' => $gatewayUrl, 'fields' => $ordered + ['Hash' => $hash]];
}

/**
 * Dekoduje ITN (base64 XML z pola POST `transactions`) i zwraca strukture.
 *
 * Realna struktura Autopay (potwierdzona produkcyjnym ITN):
 *   <transactionList>
 *     <serviceID>..</serviceID>
 *     <transactions><transaction>..pola..</transaction>..</transactions>
 *     <hash>..</hash>            <-- jeden hash dla CALEGO komunikatu, nie per transakcja
 *   </transactionList>
 *
 * Zwraca:
 *   'serviceID'    => string,
 *   'hash'         => string (z poziomu transactionList),
 *   'transactions' => [ [pole=>wartosc, ...], ... ],
 *   'hashValues'   => [wartosci wszystkich pol w kolejnosci dokumentu, BEZ pola hash]
 * lub null przy bledzie.
 */
function autopay_parse_itn(string $transactionsParam): ?array
{
    $xmlStr = base64_decode($transactionsParam, true);
    if ($xmlStr === false || $xmlStr === '') return null;

    $prev = libxml_use_internal_errors(true);
    $root = simplexml_load_string($xmlStr);
    libxml_use_internal_errors($prev);
    if ($root === false) return null;

    $out = ['serviceID' => '', 'hash' => '', 'transactions' => [], 'hashValues' => []];

    foreach ($root->children() as $child) {
        $name = $child->getName();

        if ($name === 'hash') {
            $out['hash'] = (string) $child; // hash nie wchodzi do wartosci
            continue;
        }

        if ($name === 'transactions') {
            foreach ($child->transaction as $tx) {
                $fields = [];
                foreach ($tx->children() as $f) {
                    $fields[$f->getName()] = (string) $f;
                    $out['hashValues'][] = (string) $f; // kolejnosc dokumentu
                }
                $out['transactions'][] = $fields;
            }
            continue;
        }

        // pola na poziomie glownym (serviceID i ew. inne) — wartosc wchodzi do hasha
        if ($name === 'serviceID') {
            $out['serviceID'] = (string) $child;
        }
        $out['hashValues'][] = (string) $child;
    }

    return $out;
}

/**
 * Weryfikuje hash CALEGO komunikatu ITN: hash nad wartosciami wszystkich pol
 * (w kolejnosci dokumentu, bez pola hash) + klucz. Porownanie odporne na timing.
 */
function autopay_verify_message_hash(array $parsed, string $key, string $algo, string $sep): bool
{
    $received = $parsed['hash'] ?? '';
    if ($received === '') return false;
    $expected = autopay_hash_raw($parsed['hashValues'] ?? [], $key, $algo, $sep);
    return hash_equals($expected, $received);
}

/**
 * Buduje XML potwierdzenia ITN dla Autopay.
 * $confirmations: [orderID => 'CONFIRMED'|'NOTCONFIRMED'].
 * Hash potwierdzenia: serviceID + (orderID + confirmation dla kazdej) + klucz.
 */
function autopay_confirmation_xml(string $serviceId, array $confirmations, string $key, string $algo, string $sep): string
{
    $hashValues = [$serviceId];
    $rows = '';
    foreach ($confirmations as $orderId => $status) {
        $hashValues[] = (string) $orderId;
        $hashValues[] = (string) $status;
        $rows .= '<transactionConfirmed><orderID>' . htmlspecialchars((string) $orderId, ENT_XML1)
              . '</orderID><confirmation>' . htmlspecialchars((string) $status, ENT_XML1)
              . '</confirmation></transactionConfirmed>';
    }
    $hash = autopay_hash_raw($hashValues, $key, $algo, $sep);

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<confirmationList><serviceID>' . htmlspecialchars($serviceId, ENT_XML1) . '</serviceID>'
        . '<transactionsConfirmations>' . $rows . '</transactionsConfirmations>'
        . '<hash>' . $hash . '</hash></confirmationList>';
}
