# Spec: Widok rezerwacji w panelu admina + zabezpieczenie hasła — willaslonce.pl

**Data:** 2026-05-29
**Status:** Zatwierdzony (Marcel, 2026-05-29)
**Repo:** github.com/marcelhoffman-create/willa-slonce (publiczne, statyczny HTML + backend PHP na Zenbox, deploy GitHub Actions → FTP)

---

## 1. Cel

Dać Marcelowi w panelu admina (`admin.html`) widok wszystkich zamówień: kto zarezerwował/kupił, kontakt, termin/produkty, kwota, status. Przy okazji — wymóg wynikający z wystawienia danych osobowych — zabezpieczyć uwierzytelnianie panelu, które dziś opiera się na haśle jawnym w publicznym repo.

## 2. Stan obecny

**Dane zamówień:** pliki JSON w `payment/orders/` (rezerwacje + sklep), katalog zablokowany `.htaccess` (`Require all denied`). Pola rezerwacji: `type=booking, imie, nazwisko, email, telefon, checkin, checkout, goscie, noce, kwota, status, created, paidAt, ...`. Pola sklepu: `type=shop, name, email, phone, delivery, address, items[], total, status, created, paidAt`.

**Uwierzytelnianie panelu (dwie warstwy, obie słabe):**
- Klient (`admin.html`): bramka `sha256(hasło) === PASSWORD_HASH` (stała w pliku, linia ~430). Czysto kosmetyczna — łatwa do obejścia (kod po stronie przeglądarki).
- Serwer (`save-data.php`): `ADMIN_PASSWORD = 'brenna2026'` na sztywno, porównanie z `$body['password']` (jawny tekst). **To jest realna luka** — hasło jest w publicznym repo, więc każdy może zapisywać ceny/produkty/blokady, a po dodaniu widoku zamówień — pobrać dane osobowe klientów.
- Wszystkie zapisy wysyłają jawne hasło z `sessionStorage['adminPwd']`. `sha256(pwd).then(...)` w `loadBlockedDates` (linia ~859) jest pozostałością — liczy hash i go nie używa.

## 3. Decyzje (Marcel)

- Widok pokazuje **rezerwacje + zamówienia sklepu** w jednym miejscu, z oznaczeniem typu.
- **Wszystkie statusy** (opłacone / oczekujące / nieudane) z etykietą.
- **Zabezpieczyć hasło teraz** — bo widok wystawia PII.

## 4. Architektura

### A. Zabezpieczenie uwierzytelniania (jedno źródło prawdy = serwer)
- Nowy `admin-credentials.php` (root, w `.gitignore`, wzorzec jak `autopay-credentials.php`) — `define('ADMIN_PASSWORD', '<nowe haslo>')`. Wgrywa Marcel przez FTP. W repo tylko `admin-credentials.example.php`.
- Nowy `admin-auth.php` (root, wspólny) — ładuje creds (jeśli istnieją), fallback `ADMIN_PASSWORD=''`, funkcja `admin_password_valid(string $provided): bool` = `ADMIN_PASSWORD !== '' && hash_equals(ADMIN_PASSWORD, $provided)` (timing-safe; puste hasło NIGDY nie przechodzi).
- `save-data.php` — usuń `define('ADMIN_PASSWORD','brenna2026')`, `require_once admin-auth.php`, zamień check na `if (!admin_password_valid($body['password'] ?? '')) → 403`.
- `admin.html` — login weryfikowany **po stronie serwera**: `login()` POST-uje hasło do `admin-orders.php`; 200 → wejście do panelu (i od razu mamy listę zamówień), 403 → błąd. Usuń klientowy hash: stałą `PASSWORD_HASH`, funkcje `sha256`/`sha256js`, oraz pozostałościowy `sha256(pwd).then(...)` wrapper w `loadBlockedDates` (zostaw samo `fetch`).

### B. Endpoint listy zamówień — `admin-orders.php` (root, nowy)
- Tylko **POST** (PII nie w GET/URL/logach), nagłówki: `Content-Type: application/json`, `Access-Control-Allow-Origin: https://willaslonce.pl`.
- `require_once admin-auth.php`; `if (!admin_password_valid($body['password'] ?? '')) → 403`.
- Czyta `glob(__DIR__.'/payment/orders/*.json')`, dekoduje każdy, zwraca **przyciętą** listę pól (bez `autopayData`), posortowaną malejąco po `created`.
- Odpowiedź: `{ ok:true, orders:[ {type, sessionId, created, status, paidAt, kwota|total, imie, nazwisko, name, email, telefon|phone, checkin, checkout, noce, goscie, items, delivery, address} ] }`.
- Brak zamówień / brak katalogu → `orders: []`.

### C. Widok w `admin.html` — sekcja „Rezerwacje i zamówienia"
- Nowa karta (po sekcji zablokowanych terminów). Tabela renderowana z `escHtml()` (już istnieje, linia ~767) — bez XSS.
- Kolumny: **Data** (created, `d.m.Y H:i`), **Typ** (badge Nocleg/Sklep), **Klient** (imię+nazwisko lub name), **Kontakt** (email + telefon), **Szczegóły** (nocleg: `12.07–14.07 (2 noce), 2 os.`; sklep: `Miód ×2, Ser ×1` + dostawa/adres), **Kwota** (zł), **Status** (badge: Opłacone zielony / Oczekuje żółty / Nieudane czerwony), nr ref. drobnym drukiem.
- Przycisk **Odśwież** + filtr statusu (`<select>`: wszystkie / opłacone / oczekujące). Dane pobierane raz przy logowaniu (z odpowiedzi `login()`) i przy odświeżeniu; filtr działa na już pobranej liście.
- Mapowanie: `status: paid→Opłacone, pending→Oczekuje, failed→Nieudane`; `type: booking→Nocleg, shop→Sklep`.

## 5. Bezpieczeństwo

- Dane osobowe wychodzą wyłącznie przez `admin-orders.php` (POST + poprawne hasło). Katalog `payment/orders/` pozostaje zablokowany `.htaccess`.
- `hash_equals` przy weryfikacji hasła; puste `ADMIN_PASSWORD` (brak pliku creds) → odrzucenie wszystkiego.
- CORS ograniczony do `https://willaslonce.pl`.
- Hasło już nie istnieje w repo (poza historią git — Marcel ustawia NOWE hasło w `admin-credentials.php`, więc stare `brenna2026` przestaje cokolwiek chronić).

## 6. Wdrożenie

Jeden deploy (push na main). Sekwencja go-live:
1. Push kodu (deploy FTP).
2. Marcel: utwórz `admin-credentials.php` z NOWYM hasłem, wgraj przez FTP do `public_html/`.
3. Claude: reset opcache (endpoint `payment/_oc.php`, jak wcześniej).
4. Marcel: twardy refresh `admin.html` (Cmd+Shift+R — statyczny plik, cache przeglądarki), zaloguj nowym hasłem, sprawdź listę.

**Uwaga:** między krokiem 1 a 2 panel jest zablokowany (brak pliku creds → 403). To krótkie okno, akceptowalne.
**Uwaga:** deploy nadpisze `prices.json`/`products.json` wartościami z repo (repo ma realne ceny 350+, więc testowa cena 1 zł zostanie cofnięta do realnej — pożądane).

## 7. Poza zakresem (YAGNI)

- Edycja/usuwanie/zmiana statusu zamówień z panelu (na razie tylko podgląd).
- Eksport CSV, paginacja, wyszukiwarka (wolumen niski — pełna lista wystarcza).
- Migracja zamówień do bazy danych.
- Zmiana minimum ceny noclegu z powrotem na 50 zł (osobno, jeśli Marcel zechce — teraz 1 zł po testach).

## 8. Ryzyka

- Chicken-egg creds: po deployu panel zablokowany do czasu wgrania `admin-credentials.php`. Mitygacja: jasna kolejność kroków.
- Opcache: `save-data.php`/`admin-orders.php`/`admin-auth.php` to PHP — wymagają resetu opcache po deployu (Claude robi).
- Cache przeglądarki dla `admin.html` — twardy refresh.
