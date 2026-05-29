# Spec: Wdrożenie płatności online Autopay — willaslonce.pl

**Data:** 2026-05-29
**Status:** Zatwierdzony (Marcel, 2026-05-29)
**Repo:** github.com/marcelhoffman-create/willa-slonce (publiczne, statyczny HTML + backend PHP na Zenbox, deploy GitHub Actions → FTP)

---

## 1. Cel

Uruchomić działające, bezpieczne płatności online przez Autopay dla:
- **rezerwacji** noclegu (formularz w `rezerwacje.html`),
- **sklepu** z produktami lokalnymi (`sklep.html`).

Autopay potwierdził aktywację danych dostępowych usługi `211642`. Przelew bankowy zostaje jako alternatywa.

## 2. Stan obecny (przed pracą)

Integracja jest w ~80% napisana, ale **nie zadziała w obecnej formie** i ma luki bezpieczeństwa.

Pliki w `payment/`:
- `autopay-config.php` — helpery Autopay (init + ITN). **Błędny protokół** (patrz §4) + **klucz na sztywno**.
- `autopay-notify.php` — odbiór ITN. Błędny format (oczekuje JSON).
- `booking-init.php` — init rezerwacji przez Autopay. **Kwota brana z przeglądarki.**
- `shop-init.php` — init sklepu przez **Przelewy24** (niespójność).
- `p24-config.php` — konfiguracja P24 + wspólne helpery (`save_order`, `load_order`, `send_webhook`, `SITE_URL`, `ORDERS_DIR`, webhooki n8n). Credentials P24 ładowane z `p24-credentials.php` (gitignore — dobry wzorzec).
- `notify.php`, `return.php`, `booking-bank.php`, `shop-bank.php`, `p24-credentials.example.php`.

Dane: `prices.json` (ceny noclegu wg liczby gości), `products.json` (produkty sklepu). Zamówienia: pliki JSON w `payment/orders/` (gitignore).

## 3. Blokery zidentyfikowane

### Bloker #1 (krytyczny) — sekret Autopay w publicznym repo
`autopay-config.php` ma `AUTOPAY_SERVICE_ID` i `AUTOPAY_HASH_KEY` wpisane na sztywno (commit `3e7ee73`), repo publiczne. Klucz współdzielony = sekret. Z nim można podrobić ITN i oznaczyć zamówienie jako opłacone bez płatności (darmowa rezerwacja). Klucz jest już w historii git → **wymagana rotacja** w panelu Autopay; samo usunięcie z kodu nie wystarczy.

### Bloker #2 (krytyczny) — kwota z klienta
`booking-init.php`: `$kwota = intval($body['kwota'])` — wartość z ukrytego pola `kwotaHidden` w `rezerwacje.html`. Klient może zapłacić ile chce (np. 1 zł za pobyt). `shop-init.php` akceptuje dowolną cenę produktu ≥1 zł zamiast sprawdzać w `products.json`.

### Bloker #3 — katalog PII bez ochrony
`payment/orders/*.json` zawiera imię, nazwisko, email, telefon, adres. Brak `.htaccess`. Na Apache (Zenbox) ryzyko bezpośredniego pobrania plików.

### Bloker #4 (funkcjonalny) — błędny protokół Autopay
Patrz §4 — obecny kod był pisany pod nieistniejące REST API, nie pod faktyczną bramkę Autopay.

## 4. Faktyczny protokół Autopay (z dokumentacji developers.autopay.pl)

| Element | Obecny (błędny) kod | Poprawne wg dokumentacji |
|---|---|---|
| Inicjacja | `curl` JSON na `pay.autopay.eu/v1/transaction` | **Formularz POST** `application/x-www-form-urlencoded` z przekierowaniem przeglądarki na `https://pay.autopay.eu/payment` (test: `https://testpay.autopay.eu/payment`) |
| Nazwy pól | małe litery (`serviceID`) | **PascalCase**: `ServiceID`, `OrderID`, `Amount`, `CustomerEmail`, `Hash` |
| Hash init | `sha256(ServiceID\|OrderID\|Amount\|klucz)` | SHA256 z wartości **wszystkich wysłanych pól** w kolejności, separator `\|`, na końcu klucz współdzielony; **puste pola pomijane** (bez separatora) |
| ITN | oczekuje JSON, odsyła JSON | XML zakodowany **Base64** w polu POST `transactions`; po weryfikacji odsyła XML `<confirmationList>` z `CONFIRMED`/`NOTCONFIRMED` + hash |

**Pola inicjacji (planowane):** `ServiceID`, `OrderID`, `Amount` (format `0.00`), `CustomerEmail`, `Description` (≤79 znaków), `Currency=PLN`, `Hash`.

**Pola ITN (przychodzące):** `serviceID`, `orderID`, `remoteID`, `amount`, `currency`, `paymentStatus`, `paymentDate` (YYYYMMDDhhmmss), `hash`.

**Statusy:** `PENDING` (zainicjowana), `SUCCESS` (opłacona), `FAILURE` (nieudana).

**Odpowiedź na ITN (XML):**
```xml
<confirmationList>
  <serviceID>211642</serviceID>
  <transactionsConfirmations>
    <transactionConfirmed>
      <orderID>...</orderID>
      <confirmation>CONFIRMED</confirmation>
    </transactionConfirmed>
  </transactionsConfirmations>
  <hash>...</hash>
</confirmationList>
```

**Do zweryfikowania w implementacji (z panelu Autopay usługi 211642):** dokładny **algorytm hash** (SHA256 vs SHA512) i **separator** (domyślnie `|`) — ustawienia panelu muszą być odwzorowane w kodzie co do znaku. Dokładna kolejność pól w hashu init i w hashu ITN/confirmation — wg tabeli pól w dokumentacji; potwierdzić realnym testem.

## 5. Architektura wdrożenia

### A. Sekret poza repo
- Nowy `payment/autopay-credentials.php` (dodany do `.gitignore`, wzorzec jak `p24-credentials.php`), wgrany przez FTP. Zawiera **wyrotowany** `AUTOPAY_SERVICE_ID`, `AUTOPAY_HASH_KEY`, `AUTOPAY_HASH_ALGO`, `AUTOPAY_HASH_SEPARATOR`, `AUTOPAY_TEST_MODE`.
- `autopay-config.php`: `require` pliku creds jeśli istnieje; usunięcie wartości na sztywno. Helper `autopay_configured()` analogiczny do `p24_configured()`.
- Dodać `payment/autopay-credentials.example.php` jako szablon (jak P24).

### B. Przepisanie integracji Autopay (protokół bramki)
- **Init** (`autopay_create` → zmiana sygnatury/zwracanej wartości): backend liczy poprawny hash i zwraca dane formularza; przekierowanie realizowane przez **auto-submit form** (POST form-urlencoded na bramkę). Wariant techniczny: endpoint init zwraca do frontu zestaw pól + URL bramki, front renderuje i auto-submituje formularz (albo serwer zwraca gotowy HTML z auto-submit). Wybór w planie.
- **ITN** (`autopay-notify.php`): odczyt `transactions` (POST), `base64_decode`, parsowanie XML, weryfikacja hash, ustaw `paid`, idempotentność (jak teraz), webhook n8n, odpowiedź XML `<confirmationList>`.
- Mapowanie statusów: `SUCCESS`→`paid`, `PENDING`→`pending`, `FAILURE`→`failed`.

### C. Cena po stronie serwera
- **Rezerwacja** (`booking-init.php`): kwota liczona serwerowo = cena z `prices.json` (`by_guests[liczba_gości]`) × liczba nocy (różnica `checkout`−`checkin`). Walidacja: daty poprawne, `checkout` > `checkin`, liczba gości 1–6. Kwota z klienta **ignorowana**.
- **Sklep** (`shop-init.php`): cena każdej pozycji pobierana z `products.json` po `id` (nie z klienta). Pozycje spoza `products.json` lub `available=false` → błąd. Wysyłka liczona serwerowo wg obecnej reguły (kurier <150 zł → +15 zł).

### D. Ochrona danych osobowych
- `payment/orders/.htaccess` z `Require all denied` (Apache 2.4) + `Options -Indexes`. Plik wersjonowany (sam katalog `orders/` zostaje w gitignore).
- Opcjonalnie (jeśli struktura Zenbox pozwala): przeniesienie `ORDERS_DIR` poza webroot. Decyzja w planie — minimum to `.htaccess`.

### E. Spójność operatora
- `shop-init.php` przełączony z `p24_register` na ścieżkę Autopay (jak booking).
- Adres ITN sklepu → `autopay-notify.php` (jeden odbiornik, rozróżnienie po `type` w zamówieniu).
- Pliki P24 (`p24-config.php` helpery wspólne zostają; `notify.php` P24 uśpiony) — nie usuwamy, zostają jako zapas.
- Przelew bankowy (`booking-bank.php`, `shop-bank.php`) zostaje jako alternatywa w UI.

### F. Test na produkcji (decyzja: od razu live)
1. Wdrożenie wszystkich zmian jednym pushem na `main` (auto-deploy FTP).
2. Wgranie `autopay-credentials.php` przez FTP (z wyrotowanym kluczem).
3. Reset opcache (produkcja cachuje stare PHP).
4. **Test:** zakup w sklepie najtańszego produktu (herbata 29 zł) — najtańszy realny test (rezerwacja z cennika = min. 350 zł).
5. Weryfikacja pełnej ścieżki: redirect na Autopay → płatność → ITN z poprawnym hashem → status `paid` → webhook n8n → `return.php` pokazuje „opłacone".
6. Zwrot transakcji testowej w panelu Autopay.

## 6. Zależności od Marcela (przed go-live)

1. **Rotacja klucza** w panel.autopay.eu (usługa 211642) → przekazać nowy klucz (do pliku creds, nie do kodu).
2. **Adres powiadomień ITN** w panelu → `https://willaslonce.pl/payment/autopay-notify.php`.
3. **Produkt Autopay** — potwierdzić, że to klasyczna bramka „Płatności online" (formularz + ITN).
4. **Konfiguracja hash** usługi — algorytm (SHA256/SHA512) + separator (`|`).

## 7. Poza zakresem (YAGNI)

- Migracja zamówień z plików JSON do bazy danych.
- Panel administracyjny zwrotów/refundów (zwrot ręcznie w panelu Autopay).
- Obsługa wielu walut (tylko PLN).
- Płatności cykliczne / kontynuacja transakcji (BLIK one-click itp.).
- Usuwanie kodu P24 (uśpiony, nie kasowany).

## 8. Ryzyka

- Błędna kolejność pól w hashu lub niezgodny separator/algorytm → transakcje odpadają. Mitygacja: weryfikacja z panelem + realny test za 29 zł przed komunikacją do klientów.
- Brak sandboxa (decyzja live) → pierwszy test realnie kosztuje (29 zł, zwracane).
- `docs/` w repo zostanie zdeployowane na FTP (markdown, bez PII, nielinkowane). Niegroźne; ewentualny wykluczenie z deployu poza zakresem (zmiana infra bez prośby).
