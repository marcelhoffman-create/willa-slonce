# Plan wdrożenia: Płatności online Autopay — willaslonce.pl

> **Dla wykonawcy:** WYMAGANA PODSKILL: użyj superpowers:subagent-driven-development (zalecane) lub superpowers:executing-plans do realizacji zadanie po zadaniu. Kroki używają `- [ ]` do śledzenia.

**Cel:** Uruchomić działające, bezpieczne płatności Autopay dla rezerwacji i sklepu na willaslonce.pl, z przelewem bankowym jako alternatywą.

**Architektura:** Czystą logikę (hash, parsowanie ITN, liczenie cen) wydzielamy do plików bez efektów ubocznych (`autopay-lib.php`, `pricing.php`) i pokrywamy testami uruchamianymi lokalnie przez `php`. Endpointy (`*-init.php`, `autopay-notify.php`) i frontend to cienka warstwa spinająca, weryfikowana lintem `php -l` + realnym testem na produkcji (decyzja: brak sandboxa). Sekret trzymamy poza repo (wzorzec jak P24). Cena liczona wyłącznie po stronie serwera.

**Tech Stack:** PHP 8.5 (bez frameworka), statyczny HTML/JS (vanilla), Autopay (klasyczna bramka: formularz POST + ITN XML), deploy GitHub Actions → FTP Zenbox.

**Polskie znaki:** cały tekst user-facing z poprawnymi diakrytykami. Logi/komentarze ASCII ok.

**Uruchamianie testów:** `/usr/local/bin/php payment/tests/test-autopay.php` (oczekiwane: wszystkie PASS, exit 0).

**Reguła deployu:** zbieramy WSZYSTKIE zmiany, lint lokalnie, push JEDEN raz (push = auto-deploy). Push i go-live = Zadanie 12 (z udziałem Marcela). Wcześniejsze zadania commitują lokalnie, NIE pushują.

---

## Struktura plików

**Tworzone:**
- `payment/autopay-lib.php` — czyste funkcje Autopay (hash, budowa pól, parsowanie/weryfikacja ITN, XML potwierdzenia). Bez efektów ubocznych, bez `require` innych plików.
- `payment/pricing.php` — `calc_booking_amount()`, `calc_shop_items()`. Czyste, czytają ścieżkę do JSON przekazaną argumentem.
- `payment/autopay-credentials.example.php` — szablon danych dostępowych.
- `payment/tests/test-autopay.php` — harness testowy (asercje, liczniki).
- `payment/tests/fixtures/prices.json`, `payment/tests/fixtures/products.json` — dane testowe.
- `payment/orders/.htaccess` — blokada dostępu HTTP do plików zamówień.

**Modyfikowane:**
- `.gitignore` — dodać `payment/autopay-credentials.php`.
- `payment/autopay-config.php` — ładowanie creds, stałe z konfiguracji, `require autopay-lib.php`, cienkie wrappery + glue.
- `payment/booking-init.php` — cena serwerowa + zwrot pól bramki (JSON `{gatewayUrl, fields}`).
- `payment/shop-init.php` — przełączenie P24 → Autopay + cena z `products.json`.
- `payment/autopay-notify.php` — parsowanie ITN XML + weryfikacja + odpowiedź XML + webhook n8n.
- `rezerwacje.html` — w sukcesie zamiast `window.location` budowa i submit formularza bramki.
- `sklep.html` — to samo dla sklepu.

---

## Zadanie 1: Liczenie cen po stronie serwera (TDD)

**Pliki:**
- Utwórz: `payment/pricing.php`
- Utwórz: `payment/tests/test-autopay.php`
- Utwórz: `payment/tests/fixtures/prices.json`, `payment/tests/fixtures/products.json`

- [ ] **Krok 1: Fixtury testowe**

`payment/tests/fixtures/prices.json`:
```json
{ "by_guests": { "1": 350, "2": 420, "3": 480, "4": 520, "5": 570, "6": 620 }, "date_ranges": [], "updated": "2026-05-03" }
```

`payment/tests/fixtures/products.json`:
```json
{ "products": [
  { "id": "miod-beskidzki", "name": "Miód beskidzki", "price": 39, "available": true },
  { "id": "ser-owczy", "name": "Ser owczy", "price": 35, "available": true },
  { "id": "swieca-niedostepna", "name": "Świeca", "price": 45, "available": false }
] }
```

- [ ] **Krok 2: Harness + pierwsze testy (failing)**

`payment/tests/test-autopay.php`:
```php
<?php
// Harness testowy logiki platnosci. Uruchom: php payment/tests/test-autopay.php
require __DIR__ . '/../pricing.php';

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

echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Krok 3: Uruchom — ma się wywalić**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: błąd „Call to undefined function calc_booking_amount" (pricing.php pusty).

- [ ] **Krok 4: Implementacja `pricing.php`**

`payment/pricing.php`:
```php
<?php
/** Liczenie cen po stronie serwera. Bez efektow ubocznych. */

/** Cena rezerwacji = cena za noc (wg liczby gosci) x liczba nocy. */
function calc_booking_amount(int $guests, string $checkin, string $checkout, string $pricesPath): array
{
    $err = fn(string $m) => ['ok' => false, 'amount' => 0, 'nights' => 0, 'error' => $m];

    if ($guests < 1 || $guests > 6) return $err('Nieprawidlowa liczba gosci.');

    $in  = DateTime::createFromFormat('Y-m-d', $checkin);
    $out = DateTime::createFromFormat('Y-m-d', $checkout);
    if (!$in || !$out) return $err('Nieprawidlowy format daty.');
    if ($in->format('Y-m-d') !== $checkin || $out->format('Y-m-d') !== $checkout) return $err('Nieprawidlowa data.');

    $nights = (int) $in->diff($out)->format('%r%a');
    if ($nights < 1) return $err('Data wyjazdu musi byc po dacie przyjazdu.');

    $prices = json_decode(@file_get_contents($pricesPath), true);
    $perNight = $prices['by_guests'][(string) $guests] ?? null;
    if (!is_numeric($perNight)) return $err('Brak ceny dla podanej liczby gosci.');

    return ['ok' => true, 'amount' => (int) $perNight * $nights, 'nights' => $nights, 'error' => null];
}

/** Walidacja koszyka: ceny brane z products.json (nie od klienta). */
function calc_shop_items(array $items, string $productsPath): array
{
    $err = fn(string $m) => ['ok' => false, 'items' => [], 'subtotal' => 0, 'error' => $m];

    if (empty($items)) return $err('Koszyk jest pusty.');

    $catalog = json_decode(@file_get_contents($productsPath), true)['products'] ?? [];
    $byId = [];
    foreach ($catalog as $p) { $byId[$p['id']] = $p; }

    $subtotal = 0; $clean = [];
    foreach ($items as $item) {
        $id = (string) ($item['id'] ?? '');
        $prod = $byId[$id] ?? null;
        if (!$prod)                       return $err('Nieznany produkt: ' . $id);
        if (($prod['available'] ?? false) !== true) return $err('Produkt niedostepny: ' . ($prod['name'] ?? $id));

        $qty   = max(1, min(99, (int) ($item['qty'] ?? 1)));
        $price = (int) $prod['price']; // cena z serwera, ignoruje klienta
        $subtotal += $price * $qty;
        $clean[] = ['id' => $id, 'name' => $prod['name'], 'price' => $price, 'qty' => $qty];
    }

    return ['ok' => true, 'items' => $clean, 'subtotal' => $subtotal, 'error' => null];
}
```

- [ ] **Krok 5: Uruchom — ma przejść**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: `8 PASS, 0 FAIL`, exit 0.

- [ ] **Krok 6: Commit**

```bash
git add payment/pricing.php payment/tests/
git commit -m "feat(pricing): serwerowe liczenie cen rezerwacji i sklepu + testy"
```

---

## Zadanie 2: Hash i budowa pól płatności Autopay (TDD)

**Pliki:**
- Utwórz: `payment/autopay-lib.php`
- Modyfikuj: `payment/tests/test-autopay.php`

- [ ] **Krok 1: Testy (failing)** — dopisz w `test-autopay.php` po `require pricing.php`:

```php
require __DIR__ . '/../autopay-lib.php';
```

i przed podsumowaniem dodaj:
```php
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
```

- [ ] **Krok 2: Uruchom — fail**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: „Call to undefined function autopay_hash_raw".

- [ ] **Krok 3: Implementacja `autopay-lib.php` (część 1)**

`payment/autopay-lib.php`:
```php
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
```

- [ ] **Krok 4: Uruchom — pass**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: wszystkie PASS (8 + 6 nowych), exit 0.

- [ ] **Krok 5: Commit**

```bash
git add payment/autopay-lib.php payment/tests/test-autopay.php
git commit -m "feat(autopay): hash i budowa pol platnosci + testy"
```

---

## Zadanie 3: Parsowanie i weryfikacja ITN + XML potwierdzenia (TDD)

**Pliki:**
- Modyfikuj: `payment/autopay-lib.php`
- Modyfikuj: `payment/tests/test-autopay.php`

- [ ] **Krok 1: Testy (failing)** — dopisz przed podsumowaniem w `test-autopay.php`:

```php
// --- ITN: budujemy probke XML, kodujemy base64, parsujemy, weryfikujemy ---
$KEY = 'KLUCZTESTOWY';
$txHash = autopay_hash_raw(['211642','BOOK-1','29.00','PLN','SUCCESS'], $KEY, 'sha256', '|');
$xml = '<?xml version="1.0" encoding="UTF-8"?>'
     . '<transactionList><serviceID>211642</serviceID><transactions><transaction>'
     . '<serviceID>211642</serviceID><orderID>BOOK-1</orderID><amount>29.00</amount>'
     . '<currency>PLN</currency><paymentStatus>SUCCESS</paymentStatus>'
     . '<hash>' . $txHash . '</hash>'
     . '</transaction></transactions></transactionList>';
$b64 = base64_encode($xml);

$parsed = autopay_parse_itn($b64);
check('itn: parsuje serviceID', $parsed['serviceID'] === '211642');
check('itn: parsuje 1 transakcje', count($parsed['transactions']) === 1);
check('itn: pola transakcji', $parsed['transactions'][0]['orderID'] === 'BOOK-1' && $parsed['transactions'][0]['paymentStatus'] === 'SUCCESS');

check('itn: weryfikacja hash poprawna',
    autopay_verify_tx_hash($parsed['transactions'][0], $KEY, 'sha256', '|') === true);

$tampered = $parsed['transactions'][0];
$tampered['amount'] = '1.00';
check('itn: weryfikacja hash wykrywa manipulacje',
    autopay_verify_tx_hash($tampered, $KEY, 'sha256', '|') === false);

check('itn: zly base64 = null', autopay_parse_itn('@@@niepoprawne@@@') === null);

// --- XML potwierdzenia ---
$conf = autopay_confirmation_xml('211642', ['BOOK-1' => 'CONFIRMED'], $KEY, 'sha256', '|');
check('confirm: zawiera CONFIRMED i orderID', str_contains($conf, '<confirmation>CONFIRMED</confirmation>') && str_contains($conf, '<orderID>BOOK-1</orderID>'));
check('confirm: zawiera hash', str_contains($conf, '<hash>'));
```

- [ ] **Krok 2: Uruchom — fail**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: „Call to undefined function autopay_parse_itn".

- [ ] **Krok 3: Implementacja `autopay-lib.php` (część 2)** — dopisz funkcje:

```php
/**
 * Dekoduje ITN (base64 XML z pola POST `transactions`) i zwraca strukture.
 * Zwraca ['serviceID' => ..., 'transactions' => [ {pola transakcji w kolejnosci dokumentu, z 'hash'} ]] lub null.
 * Kolejnosc pol zachowana (wazne dla weryfikacji hasha po kolejnosci dokumentu).
 */
function autopay_parse_itn(string $transactionsParam): ?array
{
    $xmlStr = base64_decode($transactionsParam, true);
    if ($xmlStr === false || $xmlStr === '') return null;

    $prev = libxml_use_internal_errors(true);
    $root = simplexml_load_string($xmlStr);
    libxml_use_internal_errors($prev);
    if ($root === false) return null;

    $out = ['serviceID' => (string) ($root->serviceID ?? ''), 'transactions' => []];
    foreach ($root->transactions->transaction ?? [] as $tx) {
        $fields = [];
        foreach ($tx->children() as $child) {
            $fields[$child->getName()] = (string) $child; // kolejnosc dokumentu zachowana
        }
        $out['transactions'][] = $fields;
    }
    return $out;
}

/**
 * Weryfikuje hash transakcji: hash nad wartosciami wszystkich pol (w kolejnosci dokumentu)
 * OPROCZ samego 'hash', + klucz. Porownanie odporne na timing.
 */
function autopay_verify_tx_hash(array $txFields, string $key, string $algo, string $sep): bool
{
    $received = $txFields['hash'] ?? '';
    if ($received === '') return false;
    $values = [];
    foreach ($txFields as $name => $val) {
        if ($name === 'hash') continue;
        $values[] = $val;
    }
    $expected = autopay_hash_raw($values, $key, $algo, $sep);
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
```

- [ ] **Krok 4: Uruchom — pass**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: wszystkie PASS, exit 0.

- [ ] **Krok 5: Commit**

```bash
git add payment/autopay-lib.php payment/tests/test-autopay.php
git commit -m "feat(autopay): parsowanie/weryfikacja ITN + XML potwierdzenia + testy"
```

> **UWAGA dot. kolejności pól hasha:** dokumentacja Autopay liczy hash ITN po wartościach pól w kolejności dokumentu — `autopay_verify_tx_hash` celowo iteruje po kolejności dokumentu (odporne). Dokładny algorytm hasha potwierdzenia (`autopay_confirmation_xml`) jest implementacją wg wzorca `confirmationList`; jeśli pierwszy realny ITN nie zostanie zaakceptowany (Autopay ponawia notyfikację), sprawdzić log z Zadania 7 i skorygować kolejność/skład. To jedyny punkt niepewny — siatka bezpieczeństwa = logowanie surowego ITN.

---

## Zadanie 4: Plik danych dostępowych poza repo + ładowanie w config

**Pliki:**
- Utwórz: `payment/autopay-credentials.example.php`
- Modyfikuj: `.gitignore`
- Modyfikuj: `payment/autopay-config.php`

- [ ] **Krok 1: Szablon creds**

`payment/autopay-credentials.example.php`:
```php
<?php
/**
 * SZABLON. Skopiuj jako payment/autopay-credentials.php (plik poza git, wgraj przez FTP).
 * Wartosci z panel.autopay.eu -> usluga -> dane integracji.
 */
define('AUTOPAY_SERVICE_ID', '');   // np. 211642
define('AUTOPAY_HASH_KEY',   '');   // WYROTOWANY klucz wspoldzielony
define('AUTOPAY_HASH_ALGO',  'sha256'); // 'sha256' lub 'sha512' — wg ustawien uslugi
define('AUTOPAY_HASH_SEP',   '|');      // separator hasha — wg ustawien uslugi
define('AUTOPAY_TEST_MODE',  false);    // true = testpay.autopay.eu
```

- [ ] **Krok 2: `.gitignore`** — dodaj linię po `payment/p24-credentials.php`:

```
payment/autopay-credentials.php
```

- [ ] **Krok 3: Przepisz `payment/autopay-config.php`** (cała zawartość):

```php
<?php
/**
 * Autopay — konfiguracja i glue. Czysta logika: autopay-lib.php.
 * Dokumentacja: https://developers.autopay.pl/online/dokumentacja
 */

require_once __DIR__ . '/p24-config.php';   // SITE_URL, ORDERS_DIR, save_order, load_order, send_webhook, N8N_*
require_once __DIR__ . '/autopay-lib.php';  // czyste funkcje

// Dane dostepowe — plik poza repo (wzorzec jak P24)
$autopayCred = __DIR__ . '/autopay-credentials.php';
if (file_exists($autopayCred)) {
    require $autopayCred;
} else {
    define('AUTOPAY_SERVICE_ID', '');
    define('AUTOPAY_HASH_KEY',   '');
    define('AUTOPAY_HASH_ALGO',  'sha256');
    define('AUTOPAY_HASH_SEP',   '|');
    define('AUTOPAY_TEST_MODE',  false);
}

define('AUTOPAY_GATEWAY_URL', AUTOPAY_TEST_MODE
    ? 'https://testpay.autopay.eu/payment'
    : 'https://pay.autopay.eu/payment');

function autopay_configured(): bool
{
    return AUTOPAY_SERVICE_ID !== '' && AUTOPAY_HASH_KEY !== '';
}

/** Wrapper: pola formularza bramki dla zamowienia. */
function autopay_payment(string $orderId, float $amount, string $email, string $description): array
{
    return autopay_payment_fields(
        AUTOPAY_SERVICE_ID, AUTOPAY_HASH_KEY, AUTOPAY_HASH_ALGO, AUTOPAY_HASH_SEP,
        AUTOPAY_GATEWAY_URL, $orderId, $amount, $email, $description
    );
}
```

- [ ] **Krok 4: Lint**

Run: `/usr/local/bin/php -l payment/autopay-config.php && /usr/local/bin/php -l payment/autopay-credentials.example.php`
Oczekiwane: „No syntax errors detected" dla obu.

- [ ] **Krok 5: Regresja testów** (autopay-lib niezmieniony, ale upewnij się)

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: wszystkie PASS.

- [ ] **Krok 6: Commit**

```bash
git add .gitignore payment/autopay-config.php payment/autopay-credentials.example.php
git commit -m "refactor(autopay): sekret poza repo + ladowanie creds (wzorzec P24)"
```

---

## Zadanie 5: Rezerwacja — cena serwerowa + pola bramki (`booking-init.php`)

**Pliki:**
- Modyfikuj: `payment/booking-init.php`

- [ ] **Krok 1: Przepisz logikę po walidacji pól** — zastąp w `booking-init.php` blok od `$kwota = intval(...)` oraz całą część budowania zamówienia/`autopay_create` tak, by:
  1. ładować `pricing.php`,
  2. liczyć kwotę serwerowo z `prices.json`,
  3. budować pola bramki przez `autopay_payment()`,
  4. zwracać `{ ok, gatewayUrl, fields, sessionId }`.

Docelowy kształt kluczowych fragmentów:

Na górze, przy `require __DIR__ . '/autopay-config.php';` dodaj:
```php
require_once __DIR__ . '/pricing.php';
```

Usuń odczyt i walidację `$kwota` z `$body['kwota']`. Po walidacji dat/danych:
```php
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
    'type'=>'booking','sessionId'=>$sessionId,'imie'=>$imie,'nazwisko'=>$nazwisko,
    'email'=>$email,'telefon'=>$telefon,'checkin'=>$checkin,'checkout'=>$checkout,
    'goscie'=>$goscie,'noce'=>$noce,'kwota'=>$kwota,'uwagi'=>$uwagi,
    'godzina'=>trim($body['godzina'] ?? ''),'description'=>$description,
    'created'=>date('Y-m-d H:i:s'),'status'=>'pending','payment'=>'autopay',
]);

$pay = autopay_payment($sessionId, (float) $kwota, $email, $description);

echo json_encode([
    'ok' => true,
    'gatewayUrl' => $pay['gatewayUrl'],
    'fields' => $pay['fields'],
    'sessionId' => $sessionId,
]);
```

(Pozostaw istniejące walidacje email/imię/daty oraz nagłówki. Usuń stare `autopay_create(...)` i zwracanie `redirectUrl`.)

- [ ] **Krok 2: Lint**

Run: `/usr/local/bin/php -l payment/booking-init.php`
Oczekiwane: „No syntax errors detected".

- [ ] **Krok 3: Commit**

```bash
git add payment/booking-init.php
git commit -m "feat(booking): cena liczona serwerowo + pola bramki Autopay"
```

---

## Zadanie 6: Sklep — Autopay + cena z katalogu (`shop-init.php`)

**Pliki:**
- Modyfikuj: `payment/shop-init.php`

- [ ] **Krok 1: Przepisz** — przełącz z P24 na Autopay i licz ceny z `products.json`:

Dodaj na górze przy require:
```php
require_once __DIR__ . '/pricing.php';
```

Zamień walidację konfiguracji P24 na Autopay:
```php
if (!autopay_configured()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Platnosci online sa chwilowo niedostepne. Prosimy o przelew bankowy.']);
    exit;
}
```
(oraz dodaj `require __DIR__ . '/autopay-config.php';` zamiast/obok `p24-config.php` — `autopay-config.php` i tak ładuje `p24-config.php`, więc wystarczy `require __DIR__ . '/autopay-config.php';`)

Zastąp ręczne liczenie `$subtotal` z pętli po `items` wywołaniem walidatora katalogu:
```php
$priced = calc_shop_items($items, __DIR__ . '/../products.json');
if (!$priced['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $priced['error']]);
    exit;
}
$cleanItems = $priced['items'];
$subtotal   = $priced['subtotal'];
$shipping   = ($delivery === 'kurier' && $subtotal < 150) ? 15 : 0;
$total      = $subtotal + $shipping;
```

Zamień rejestrację P24 i odpowiedź na Autopay:
```php
$sessionId   = 'SHOP-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$itemsLabels = array_map(fn($i) => $i['name'] . ' x' . $i['qty'], $cleanItems);
$description = 'Sklep Willa Slonce: ' . implode(', ', $itemsLabels);

save_order($sessionId, [
    'type'=>'shop','sessionId'=>$sessionId,'email'=>$email,'name'=>$name,'phone'=>$phone,
    'delivery'=>$delivery,'address'=>$address,'items'=>$cleanItems,'subtotal'=>$subtotal,
    'shipping'=>$shipping,'total'=>$total,'description'=>$description,
    'created'=>date('Y-m-d H:i:s'),'status'=>'pending','payment'=>'autopay',
]);

$pay = autopay_payment($sessionId, (float) $total, $email, $description);

echo json_encode([
    'ok' => true,
    'gatewayUrl' => $pay['gatewayUrl'],
    'fields' => $pay['fields'],
    'sessionId' => $sessionId,
]);
```

(Usuń `amountGrosze`, `p24_register`, `urlNotify` P24, `P24_PAYMENT_URL`. Zachowaj walidacje email/name/items/delivery+address.)

- [ ] **Krok 2: Lint**

Run: `/usr/local/bin/php -l payment/shop-init.php`
Oczekiwane: „No syntax errors detected".

- [ ] **Krok 3: Commit**

```bash
git add payment/shop-init.php
git commit -m "feat(shop): platnosc przez Autopay + cena z products.json"
```

---

## Zadanie 7: Odbiór ITN Autopay (`autopay-notify.php`)

**Pliki:**
- Modyfikuj: `payment/autopay-notify.php` (przepisz całość)

- [ ] **Krok 1: Przepisz** `payment/autopay-notify.php`:

```php
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
```

- [ ] **Krok 2: Lint**

Run: `/usr/local/bin/php -l payment/autopay-notify.php`
Oczekiwane: „No syntax errors detected".

- [ ] **Krok 3: Commit**

```bash
git add payment/autopay-notify.php
git commit -m "feat(autopay): odbior ITN XML + weryfikacja + potwierdzenie XML + webhook"
```

---

## Zadanie 8: Frontend rezerwacji — submit formularza bramki (`rezerwacje.html`)

**Pliki:**
- Modyfikuj: `rezerwacje.html`

- [ ] **Krok 1: Dodaj helper i zamień obsługę sukcesu.** W bloku `<script>` (przy innych funkcjach) dodaj:

```javascript
function submitGatewayForm(gatewayUrl, fields) {
  var f = document.createElement('form');
  f.method = 'POST';
  f.action = gatewayUrl;
  Object.keys(fields).forEach(function (k) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = k;
    input.value = fields[k];
    f.appendChild(input);
  });
  document.body.appendChild(f);
  f.submit();
}
```

W gałęzi `if (payMethod === 'p24') { ... }` zastąp obsługę odpowiedzi:
```javascript
        .then(function(data) {
          if (data.ok && data.gatewayUrl && data.fields) {
            submitGatewayForm(data.gatewayUrl, data.fields);
          } else {
            alert((data.error || 'Błąd płatności.') + '\n\nWybierz przelew bankowy lub spróbuj ponownie.');
            btn.disabled = false;
            btn.textContent = 'Rezerwuj termin →';
          }
        })
```

(Reszta gałęzi `bank` bez zmian. Body `fetch` może nadal zawierać `kwota` — serwer ją ignoruje, więc nie trzeba usuwać, ale można.)

- [ ] **Krok 2: Weryfikacja składni HTML/JS** — otwórz `rezerwacje.html` w przeglądarce lokalnie, otwórz konsolę, potwierdź brak błędów JS przy załadowaniu i przy kliknięciu „Rezerwuj" z wybraną opcją Autopay (fetch poleci na lokalny brak PHP, ale funkcja `submitGatewayForm` musi być zdefiniowana — sprawdź w konsoli `typeof submitGatewayForm === 'function'`).

Run: `open rezerwacje.html` (lub `python3 -m http.server` w katalogu repo i wejście na stronę).
Oczekiwane: brak błędów składni JS w konsoli; `submitGatewayForm` zdefiniowana.

- [ ] **Krok 3: Commit**

```bash
git add rezerwacje.html
git commit -m "feat(rezerwacje): submit formularza bramki Autopay zamiast redirect URL"
```

---

## Zadanie 9: Frontend sklepu — submit formularza bramki (`sklep.html`)

**Pliki:**
- Modyfikuj: `sklep.html`

- [ ] **Krok 1: Dodaj ten sam helper i zamień obsługę sukcesu.** W `<script>` sklepu dodaj `submitGatewayForm` (identyczny jak w Zadaniu 8). W obsłudze odpowiedzi `fetch(SHOP_INIT_URL...)` (okolice linii 1625) zastąp:
```javascript
          if (data.ok && data.gatewayUrl && data.fields) {
            submitGatewayForm(data.gatewayUrl, data.fields);
          } else {
            alert((data.error || 'Błąd płatności.') + '\n\nWybierz przelew bankowy lub spróbuj ponownie.');
            // przywróć stan przycisku (zachowaj istniejącą logikę reset przycisku)
          }
```
(Zachowaj istniejącą obsługę resetu przycisku i gałąź `SHOP_BANK_URL` bez zmian.)

- [ ] **Krok 2: Weryfikacja składni JS** — jak w Zadaniu 8 dla `sklep.html`: konsola bez błędów, `typeof submitGatewayForm === 'function'`.

- [ ] **Krok 3: Commit**

```bash
git add sklep.html
git commit -m "feat(sklep): submit formularza bramki Autopay zamiast redirect URL"
```

---

## Zadanie 10: Ochrona katalogu zamówień (PII)

**Pliki:**
- Utwórz: `payment/orders/.htaccess`

- [ ] **Krok 1: Utwórz blokadę** `payment/orders/.htaccess`:

```apache
# Blokada bezposredniego dostepu HTTP do plikow zamowien (dane osobowe)
Require all denied
Options -Indexes
```

- [ ] **Krok 2: Upewnij się, że plik trafi do repo** — `.gitignore` ignoruje `payment/orders/`, więc wymuś dodanie:

Run: `git add -f payment/orders/.htaccess`
Oczekiwane: plik dodany mimo gitignore.

- [ ] **Krok 3: Commit**

```bash
git commit -m "security: blokada HTTP do payment/orders (.htaccess)"
```

---

## Zadanie 11: Lint końcowy + przegląd + bez pushu

**Pliki:** wszystkie zmienione PHP.

- [ ] **Krok 1: Lint wszystkich zmienionych plików PHP**

Run:
```bash
for f in payment/autopay-lib.php payment/autopay-config.php payment/pricing.php payment/booking-init.php payment/shop-init.php payment/autopay-notify.php; do /usr/local/bin/php -l "$f"; done
```
Oczekiwane: „No syntax errors detected" dla każdego.

- [ ] **Krok 2: Pełen przebieg testów**

Run: `/usr/local/bin/php payment/tests/test-autopay.php`
Oczekiwane: wszystkie PASS, exit 0.

- [ ] **Krok 3: Przegląd diffu** — `git log --oneline` i `git diff main@{upstream}...HEAD --stat` (lub `git diff 3e7ee73 --stat`). Potwierdź, że NIE ma w diffie `payment/autopay-credentials.php` ani realnego klucza. Zatrzymaj się — push wykonuje Marcel w Zadaniu 12.

---

## Zadanie 12: Go-live (Marcel + Claude) — checklist produkcyjna

> To zadanie wymaga działań Marcela w panelach (Autopay, FTP, Zenbox). Nie automatyzować.

- [ ] **1. Rotacja klucza** — panel.autopay.eu → usługa 211642 → wygeneruj NOWY klucz współdzielony (stary jest publiczny w historii git → martwy po rotacji).
- [ ] **2. Konfiguracja usługi** — odczytaj/ustaw algorytm hash (SHA256/SHA512) i separator (`|`); zapamiętaj do creds.
- [ ] **3. Adres powiadomień ITN** — ustaw `https://willaslonce.pl/payment/autopay-notify.php`.
- [ ] **4. Utwórz `payment/autopay-credentials.php`** lokalnie z kopii `*.example.php`, wpisz: nowy ServiceID, nowy klucz, algo, separator, `AUTOPAY_TEST_MODE=false`.
- [ ] **5. Push** zmian na `main` (auto-deploy FTP).
- [ ] **6. Wgraj `payment/autopay-credentials.php` przez FTP** do `public_html/payment/` (plik nie jest w repo).
- [ ] **7. Reset opcache** na Zenboksie (produkcja cachuje stare PHP).
- [ ] **8. Weryfikacja dostępności** — `payment/orders/` zwraca 403; `willaslonce.pl/rezerwacje.html` i `/sklep.html` ładują się bez błędów JS.
- [ ] **9. Test realny — sklep, herbata 29 zł** (najtańszy realny test; rezerwacja z cennika = min. 350 zł): pełna ścieżka koszyk → Autopay → płatność → powrót.
- [ ] **10. Weryfikacja ITN** — sprawdź log serwera: ITN przyszedł, hash OK, status zamówienia `paid`, webhook n8n odpalił, `return.php` pokazuje „opłacone". Jeśli hash ITN/confirmation nie pasuje → log z Zadania 7 pokazuje surowy ITN; skoryguj kolejność/skład hasha i ponów.
- [ ] **11. Zwrot transakcji testowej** w panelu Autopay.
- [ ] **12. Aktualizacja kontekstu** — zaktualizuj memory (`project_willa_slonce_website.md`: płatności online live) + `decisions/log.md` w repo Asystent.

---

## Self-review planu (kontrola pokrycia spec)

- §3 Bloker #1 (sekret) → Zadanie 4 ✅
- §3 Bloker #2 (cena) → Zadania 1, 5, 6 ✅
- §3 Bloker #3 (PII) → Zadanie 10 ✅
- §3 Bloker #4 + §4 (protokół) → Zadania 2, 3, 5, 6, 7, 8, 9 ✅
- §5.E spójność operatora (sklep→Autopay) → Zadanie 6 ✅; przelew fallback zostaje (brak zmian w `*-bank.php`) ✅
- §5.F test live → Zadanie 12 ✅
- §6 zależności od Marcela → Zadanie 12 ✅
- Brak placeholderów; nazwy funkcji spójne między zadaniami (`autopay_hash_raw`, `autopay_payment_fields`/`autopay_payment`, `autopay_parse_itn`, `autopay_verify_tx_hash`, `autopay_confirmation_xml`, `calc_booking_amount`, `calc_shop_items`).
```
