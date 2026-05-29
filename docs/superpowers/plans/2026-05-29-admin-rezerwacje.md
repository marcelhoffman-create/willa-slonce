# Plan: Widok rezerwacji w adminie + zabezpieczenie hasła — willaslonce.pl

> **Dla wykonawcy:** WYMAGANA PODSKILL: superpowers:subagent-driven-development (zalecane) lub superpowers:executing-plans. Kroki `- [ ]` do śledzenia.

**Cel:** Sekcja „Rezerwacje i zamówienia" w `admin.html` (rezerwacje + sklep, wszystkie statusy) + przeniesienie uwierzytelniania panelu w całości na serwer (hasło poza repo).

**Architektura:** Wspólny `admin-auth.php` ładuje hasło z `admin-credentials.php` (poza repo) i daje timing-safe `admin_password_valid()`. `save-data.php` i nowy `admin-orders.php` weryfikują przez niego. `admin.html` loguje przez serwer (POST do `admin-orders.php`), bez klientowego hasha. Logika auth czysta i testowana lokalnie; endpoint + UI weryfikowane lintem/`node --check` + testem live.

**Tech Stack:** PHP 8.5 (bez frameworka), statyczny HTML/JS (vanilla), deploy GitHub Actions → FTP.

**Polskie znaki:** tekst user-facing z diakrytykami; bez em dash.

**Testy:** `/usr/local/bin/php tests/test-admin.php` (oczekiwane: wszystkie PASS, exit 0). Lint: `/usr/local/bin/php -l <plik>`.

**Deploy:** push JEDEN raz na końcu (Zadanie 6, z Marcelem). Zadania 1-5 commitują lokalnie, NIE pushują.

---

## Struktura plików

**Tworzone:**
- `admin-auth.php` (root) — ładowanie creds + `admin_pwd_check()` (czysta) + `admin_password_valid()`.
- `admin-credentials.example.php` (root) — szablon hasła.
- `admin-orders.php` (root) — endpoint POST: auth + odczyt `payment/orders/*.json` → JSON.
- `tests/test-admin.php` — test `admin_pwd_check`.

**Modyfikowane:**
- `.gitignore` — `admin-credentials.php`.
- `save-data.php` — auth przez `admin-auth.php`.
- `admin.html` — login po stronie serwera (usunięcie klientowego hasha) + sekcja „Rezerwacje i zamówienia".

---

## Zadanie 1: Wspólne uwierzytelnianie + test (TDD)

**Pliki:**
- Utwórz: `admin-auth.php`, `admin-credentials.example.php`, `tests/test-admin.php`
- Modyfikuj: `.gitignore`

- [ ] **Krok 1: Test (failing)** — `tests/test-admin.php`:
```php
<?php
// Uruchom: php tests/test-admin.php
require __DIR__ . '/../admin-auth.php';
$pass = 0; $fail = 0;
function check(string $n, $c): void { global $pass,$fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

check('puste oczekiwane haslo = false', admin_pwd_check('', 'cokolwiek') === false);
check('puste oba = false',              admin_pwd_check('', '') === false);
check('poprawne haslo = true',          admin_pwd_check('tajne123', 'tajne123') === true);
check('zle haslo = false',              admin_pwd_check('tajne123', 'inne') === false);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Krok 2: Uruchom — fail**

Run: `/usr/local/bin/php tests/test-admin.php`
Oczekiwane: „Failed opening required '.../admin-auth.php'" lub „undefined function admin_pwd_check".

- [ ] **Krok 3: Implementacja `admin-auth.php`:**
```php
<?php
/**
 * Wspolne uwierzytelnianie panelu admina.
 * Haslo poza repo: admin-credentials.php (gitignore). Fallback '' = panel zablokowany.
 */
$adminCred = __DIR__ . '/admin-credentials.php';
if (file_exists($adminCred)) {
    require_once $adminCred;
}
if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', '');
}

/** Czysta weryfikacja: puste oczekiwane haslo NIGDY nie przechodzi. Timing-safe. */
function admin_pwd_check(string $expected, string $provided): bool
{
    return $expected !== '' && hash_equals($expected, $provided);
}

function admin_password_valid(string $provided): bool
{
    return admin_pwd_check(ADMIN_PASSWORD, $provided);
}
```

- [ ] **Krok 4: `admin-credentials.example.php`:**
```php
<?php
/**
 * SZABLON. Skopiuj jako admin-credentials.php (poza git, wgraj przez FTP do public_html/).
 * Ustaw NOWE haslo (stare 'brenna2026' bylo jawne w repo).
 */
define('ADMIN_PASSWORD', '');
```

- [ ] **Krok 5: `.gitignore`** — dodaj linię (po `payment/autopay-credentials.php`):
```
admin-credentials.php
```

- [ ] **Krok 6: Uruchom — pass**

Run: `/usr/local/bin/php tests/test-admin.php`
Oczekiwane: `4 PASS, 0 FAIL`, exit 0.

- [ ] **Krok 7: Lint + commit**
```bash
/usr/local/bin/php -l admin-auth.php && /usr/local/bin/php -l admin-credentials.example.php
git add admin-auth.php admin-credentials.example.php tests/test-admin.php .gitignore
git commit -m "feat(admin-auth): haslo poza repo + timing-safe weryfikacja + test"
```

---

## Zadanie 2: `save-data.php` — auth przez admin-auth

**Pliki:** Modyfikuj: `save-data.php`

- [ ] **Krok 1: Podmień auth.** Usuń linię `define('ADMIN_PASSWORD', 'brenna2026');` oraz blok:
```php
if (($body['password'] ?? '') !== ADMIN_PASSWORD) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
```
W ich miejsce (po dekodowaniu `$body`, przed `$type = ...`):
```php
require_once __DIR__ . '/admin-auth.php';
if (!admin_password_valid($body['password'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
```

- [ ] **Krok 2: Lint**

Run: `/usr/local/bin/php -l save-data.php`
Oczekiwane: „No syntax errors detected".

- [ ] **Krok 3: Potwierdź brak jawnego hasła**

Run: `grep -c "brenna2026" save-data.php`
Oczekiwane: `0`.

- [ ] **Krok 4: Commit**
```bash
git add save-data.php
git commit -m "security(admin): save-data.php weryfikuje haslo przez admin-auth (bez hasla w repo)"
```

---

## Zadanie 3: Endpoint listy zamówień `admin-orders.php`

**Pliki:** Utwórz: `admin-orders.php`

- [ ] **Krok 1: Implementacja:**
```php
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

$orders = [];
foreach (glob(__DIR__ . '/payment/orders/*.json') ?: [] as $file) {
    $o = json_decode(@file_get_contents($file), true);
    if (!is_array($o)) continue;
    $orders[] = [
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
        'delivery'  => $o['delivery']  ?? '',
        'address'   => $o['address']   ?? '',
    ];
}
usort($orders, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));

echo json_encode(['ok' => true, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
```

- [ ] **Krok 2: Lint**

Run: `/usr/local/bin/php -l admin-orders.php`
Oczekiwane: „No syntax errors detected".

- [ ] **Krok 3: Smoke test auth lokalnie** (bez creds → ADMIN_PASSWORD='' → 403 nawet z pustym hasłem):

Run:
```bash
/usr/local/bin/php -r '$_SERVER["REQUEST_METHOD"]="POST"; require "admin-auth.php"; var_dump(admin_password_valid(""));'
```
Oczekiwane: `bool(false)` (pusty fallback nie przepuszcza).

- [ ] **Krok 4: Commit**
```bash
git add admin-orders.php
git commit -m "feat(admin): endpoint listy zamowien (POST + haslo, PII chronione)"
```

---

## Zadanie 4: `admin.html` — login serwerowy + sekcja „Rezerwacje i zamówienia"

**Pliki:** Modyfikuj: `admin.html`

> Czytaj plik przed edycją. Numery linii orientacyjne — dopasuj do realnej zawartości.

- [ ] **Krok 1: Usuń klientowy hash.** Usuń stałą `var PASSWORD_HASH = '...';` (≈linia 430) oraz funkcje `async function sha256(msg) {...}` (≈440-447) i `function sha256js(s) {...}` (≈450-466).

- [ ] **Krok 2: Przepisz `login()` na weryfikację serwerową:**
```javascript
  async function login() {
    var pwd = document.getElementById('pwd').value;
    try {
      var r = await fetch('admin-orders.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: pwd })
      });
      if (r.status === 200) {
        var data = await r.json();
        sessionStorage.setItem('adminPwd', pwd);
        document.getElementById('loginCard').classList.remove('active');
        document.getElementById('adminPanel').classList.add('active');
        loadPrices();
        loadProducts();
        loadBlockedDates();
        allOrders = (data && data.orders) ? data.orders : [];
        renderOrders();
      } else {
        document.getElementById('loginErr').className = 'msg err';
      }
    } catch (e) {
      document.getElementById('loginErr').className = 'msg err';
    }
  }
```

- [ ] **Krok 3: Napraw `loadBlockedDates`** — usuń pozostałościowy wrapper `sha256(pwd).then(function(hash) {...})`, zostaw samo `fetch`:
```javascript
  function loadBlockedDates() {
    var pwd = sessionStorage.getItem('adminPwd') || '';
    fetch(SAVE_ENDPOINT, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({password: pwd, type: 'blocked-list'})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { renderBlockedTable(data.ranges || []); })
    .catch(function() {});
  }
```

- [ ] **Krok 4: Dodaj sekcję HTML** — kartę po sekcji „Zablokowane terminy" (przed zamknięciem `#adminPanel`):
```html
  <!-- Rezerwacje i zamówienia -->
  <div class="card">
    <h2>Rezerwacje i zamówienia</h2>
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
      <label>Status:
        <select id="ordersFilter" onchange="renderOrders()">
          <option value="all">Wszystkie</option>
          <option value="paid">Opłacone</option>
          <option value="pending">Oczekujące</option>
          <option value="failed">Nieudane</option>
        </select>
      </label>
      <button class="btn-add" onclick="refreshOrders()">Odśwież</button>
      <span id="ordersCount" style="color:#888;font-size:0.9rem;"></span>
    </div>
    <div style="overflow-x:auto;">
      <table class="ranges-table" id="ordersTable">
        <thead>
          <tr>
            <th>Data</th><th>Typ</th><th>Klient</th><th>Kontakt</th>
            <th>Szczegóły</th><th>Kwota</th><th>Status</th>
          </tr>
        </thead>
        <tbody id="ordersTbody"></tbody>
      </table>
    </div>
    <p id="ordersEmpty" style="color:#aaa;font-size:0.9rem;">Brak zamówień.</p>
  </div>
```

- [ ] **Krok 5: Dodaj JS** (przy innych funkcjach, np. po `renderBlockedTable`):
```javascript
  // --- Rezerwacje i zamówienia ---
  var allOrders = [];

  function refreshOrders() {
    var pwd = sessionStorage.getItem('adminPwd') || '';
    fetch('admin-orders.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: pwd })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { allOrders = (data && data.orders) ? data.orders : []; renderOrders(); })
    .catch(function() {});
  }

  function nocWord(n) { return n === 1 ? 'noc' : (n >= 2 && n <= 4 ? 'noce' : 'nocy'); }

  function orderStatusBadge(s) {
    var map = { paid:   ['Opłacone', '#e8f5e9', '#2e7d32'],
                pending:['Oczekuje', '#fff8e1', '#f57c00'],
                failed: ['Nieudane', '#ffebee', '#c62828'] };
    var v = map[s] || [s || '—', '#eee', '#555'];
    return '<span style="background:' + v[1] + ';color:' + v[2] + ';padding:2px 8px;border-radius:10px;font-size:0.8rem;white-space:nowrap;">' + escHtml(v[0]) + '</span>';
  }

  function orderDetails(o) {
    if (o.type === 'booking') {
      var d = escHtml(o.checkin) + '–' + escHtml(o.checkout);
      if (o.noce) d += ' (' + o.noce + ' ' + nocWord(o.noce) + ')';
      if (o.goscie) d += ', ' + o.goscie + ' os.';
      return d;
    }
    var items = (o.items || []).map(function(i) { return escHtml(i.name) + ' ×' + i.qty; }).join(', ');
    var dost = o.delivery === 'kurier' ? ('kurier' + (o.address ? ': ' + escHtml(o.address) : '')) : 'odbiór w domku';
    return items + ' — ' + dost;
  }

  function renderOrders() {
    var filter = document.getElementById('ordersFilter').value;
    var rows = allOrders.filter(function(o) { return filter === 'all' || o.status === filter; });
    var tbody = document.getElementById('ordersTbody');
    var table = document.getElementById('ordersTable');
    var empty = document.getElementById('ordersEmpty');
    document.getElementById('ordersCount').textContent = rows.length + ' z ' + allOrders.length;
    tbody.innerHTML = '';
    if (rows.length === 0) { table.style.display = 'none'; empty.style.display = 'block'; return; }
    table.style.display = ''; empty.style.display = 'none';
    rows.forEach(function(o) {
      var klient = o.type === 'booking' ? (escHtml(o.imie) + ' ' + escHtml(o.nazwisko)) : escHtml(o.name || '');
      var kontakt = escHtml(o.email || '') + (o.telefon ? '<br>' + escHtml(o.telefon) : '');
      var typ = o.type === 'booking' ? 'Nocleg' : 'Sklep';
      var tr = document.createElement('tr');
      tr.innerHTML = '<td style="white-space:nowrap;">' + escHtml((o.created || '').slice(0, 16)) + '</td>'
        + '<td>' + typ + '</td>'
        + '<td>' + klient + '<br><span style="color:#bbb;font-size:0.75rem;">' + escHtml(o.sessionId || '') + '</span></td>'
        + '<td style="font-size:0.85rem;">' + kontakt + '</td>'
        + '<td style="font-size:0.85rem;">' + orderDetails(o) + '</td>'
        + '<td style="white-space:nowrap;">' + (parseInt(o.kwota) || 0) + ' zł</td>'
        + '<td>' + orderStatusBadge(o.status) + '</td>';
      tbody.appendChild(tr);
    });
  }
```

- [ ] **Krok 6: Weryfikacja składni JS** — wyodrębnij inline JS i sprawdź `node --check` (jak przy płatnościach):
```bash
node --version
python3 - <<'PY'
import re,subprocess,tempfile,os
html=open('admin.html',encoding='utf-8').read()
blocks=re.findall(r'<script\b([^>]*)>(.*?)</script>',html,re.S|re.I)
js='\n;\n'.join(b for a,b in blocks if 'src=' not in a.lower() and 'ld+json' not in a.lower())
t=tempfile.NamedTemporaryFile('w',suffix='.js',delete=False,encoding='utf-8'); t.write(js); t.close()
r=subprocess.run(['node','--check',t.name],capture_output=True,text=True); os.unlink(t.name)
print('OK' if r.returncode==0 else 'BLAD: '+r.stderr[:400])
PY
```
Oczekiwane: `OK`. Dodatkowo: `grep -c "PASSWORD_HASH\|sha256" admin.html` → `0`.

- [ ] **Krok 7: Commit**
```bash
git add admin.html
git commit -m "feat(admin): widok rezerwacji/zamowien + login serwerowy (bez klientowego hasha)"
```

---

## Zadanie 5: Lint końcowy + przegląd + bez pushu

- [ ] **Krok 1: Lint PHP**

Run:
```bash
for f in admin-auth.php admin-orders.php save-data.php; do /usr/local/bin/php -l "$f"; done
```
Oczekiwane: „No syntax errors detected" dla każdego.

- [ ] **Krok 2: Testy + brak sekretu**

Run:
```bash
/usr/local/bin/php tests/test-admin.php
grep -rn "brenna2026" save-data.php admin.html admin-auth.php; echo "exit grep: $?"
```
Oczekiwane: `4 PASS, 0 FAIL`; grep nic nie znajduje (exit 1).

- [ ] **Krok 3: Przegląd diffu** — `git diff <poprzedni-main>..HEAD --stat`. Potwierdź: brak `admin-credentials.php` w repo, brak `brenna2026`. Zatrzymaj się — push i go-live = Zadanie 6 z Marcelem.

---

## Zadanie 6: Go-live (Marcel + Claude)

- [ ] **1. Push** zmian na `main` (auto-deploy FTP).
- [ ] **2. Marcel:** utwórz lokalnie `admin-credentials.php` z `define('ADMIN_PASSWORD', '<NOWE_HASLO>');`, wgraj przez FTP do `public_html/`.
- [ ] **3. Claude:** reset opcache (endpoint `payment/_oc.php`: dodaj, push, curl aż `ok`, usuń, push).
- [ ] **4. Marcel:** twardy refresh `admin.html` (Cmd+Shift+R), zaloguj NOWYM hasłem.
- [ ] **5. Sprawdź:** sekcja „Rezerwacje i zamówienia" pokazuje testową rezerwację 1 zł ze statusem „Opłacone"; filtr i „Odśwież" działają.
- [ ] **6. Marcel:** w panelu przywróć realne ceny noclegu (jeśli deploy nie cofnął ich z repo) i rozważ podniesienie minimum z powrotem (osobno).

> Między krokiem 1 a 2 panel jest zablokowany (brak creds → 403) — to oczekiwane.

---

## Self-review planu

- Spec §4.A (auth) → Zadania 1, 2, 4 (login serwerowy) ✅
- Spec §4.B (endpoint) → Zadanie 3 ✅
- Spec §4.C (widok) → Zadanie 4 (sekcja + JS) ✅
- Spec §5 (bezpieczeństwo: hash_equals, puste→odrzucenie, CORS, POST) → Zadania 1, 3 ✅
- Spec §6 (wdrożenie) → Zadanie 6 ✅
- Nazwy spójne: `admin_pwd_check`, `admin_password_valid`, `allOrders`, `renderOrders`, `refreshOrders`, `orderStatusBadge`, `orderDetails`, `nocWord`, `escHtml` (istniejąca). Endpoint `admin-orders.php`, plik `admin-auth.php`, `admin-credentials.php`. Brak placeholderów.
