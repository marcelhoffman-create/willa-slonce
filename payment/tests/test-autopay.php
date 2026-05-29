<?php
// Harness testowy logiki platnosci. Uruchom: php payment/tests/test-autopay.php
require __DIR__ . '/../pricing.php';
require __DIR__ . '/../autopay-lib.php';

$pass = 0; $fail = 0;
function check(string $name, $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else       { $fail++; echo "FAIL  $name\n"; }
}

$FX = __DIR__ . '/fixtures';

// --- calc_booking_amount ---
$r = calc_booking_amount(2, '2026-07-10', '2026-07-12', "$FX/prices.json");
check('booking: 2 goscie x 2 noce = 840', $r['ok'] && $r['amount'] === 840 && $r['nights'] === 2);

$r = calc_booking_amount(6, '2026-07-10', '2026-07-11', "$FX/prices.json");
check('booking: 6 gosci x 1 noc = 620', $r['ok'] && $r['amount'] === 620 && $r['nights'] === 1);

$r = calc_booking_amount(2, '2026-07-12', '2026-07-10', "$FX/prices.json");
check('booking: checkout przed checkin = blad', $r['ok'] === false);

$r = calc_booking_amount(9, '2026-07-10', '2026-07-12', "$FX/prices.json");
check('booking: liczba gosci poza zakresem = blad', $r['ok'] === false);

$r = calc_booking_amount(2, 'nie-data', '2026-07-12', "$FX/prices.json");
check('booking: zla data = blad', $r['ok'] === false);

// --- calc_shop_items ---
$r = calc_shop_items([['id'=>'miod-beskidzki','qty'=>2,'price'=>1]], "$FX/products.json");
check('shop: cena z serwera (39 nie 1) x2 = 78', $r['ok'] && $r['subtotal'] === 78 && $r['items'][0]['price'] === 39);

$r = calc_shop_items([['id'=>'nieistnieje','qty'=>1]], "$FX/products.json");
check('shop: nieznany produkt = blad', $r['ok'] === false);

$r = calc_shop_items([['id'=>'swieca-niedostepna','qty'=>1]], "$FX/products.json");
check('shop: produkt niedostepny = blad', $r['ok'] === false);

$r = calc_shop_items([], "$FX/products.json");
check('shop: pusty koszyk = blad', $r['ok'] === false);

// --- autopay_hash_raw (przyklad z dokumentacji Autopay: SHA256("2|100|1.50|2test2")) ---
check('hash: przyklad z dokumentacji',
    autopay_hash_raw(['2','100','1.50'], '2test2', 'sha256', '|') === hash('sha256', '2|100|1.50|2test2'));

check('hash: puste pola pomijane (bez separatora)',
    autopay_hash_raw(['2','', '1.50'], 'k', 'sha256', '|') === hash('sha256', '2|1.50|k'));

// --- autopay_payment_fields ---
$pf = autopay_payment_fields('211642', 'KLUCZTESTOWY', 'sha256', '|',
    'https://testpay.autopay.eu/payment', 'BOOK-1', 29.0, 'jan@example.com', 'Test zakup');
check('fields: komplet wymaganych pol', isset($pf['fields']['ServiceID'],$pf['fields']['OrderID'],$pf['fields']['Amount'],$pf['fields']['CustomerEmail'],$pf['fields']['Hash']));
check('fields: Amount sformatowany 0.00', $pf['fields']['Amount'] === '29.00');
check('fields: gatewayUrl przekazany', $pf['gatewayUrl'] === 'https://testpay.autopay.eu/payment');
check('fields: hash policzony nad wartosciami w kolejnosci',
    $pf['fields']['Hash'] === autopay_hash_raw(['211642','BOOK-1','29.00','Test zakup','PLN','jan@example.com'], 'KLUCZTESTOWY', 'sha256', '|'));

echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
