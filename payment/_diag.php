<?php
// === TEMP DIAGNOSTYKA ITN (usunac po diagnozie) ===
// Chronione haslem admina. Pokazuje surowy log ITN + stan zamowienia.
require_once __DIR__ . '/../admin-auth.php';

$pwd = $_POST['password'] ?? '';

if (!admin_password_valid($pwd)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><form method="post" style="font-family:sans-serif;max-width:400px;margin:40px auto;">'
           . '<h3>Diagnostyka ITN</h3><p>Haslo admina:</p>'
           . '<input type="password" name="password" style="width:100%;padding:8px;">'
           . '<button style="margin-top:10px;padding:8px 16px;">Pokaz</button></form>';
        exit;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "ini error_log: " . (ini_get('error_log') ?: '(puste)') . "\n\n";

// 1. Surowy log ITN (z naszej instrumentacji)
$rawLog = __DIR__ . '/orders/_itn_raw.log';
echo "=== _itn_raw.log ===\n";
if (is_file($rawLog)) {
    $lines = file($rawLog, FILE_IGNORE_NEW_LINES) ?: [];
    echo implode("\n", array_slice($lines, -300)) . "\n";
} else {
    echo "(brak — zaden ITN jeszcze nie trafil tu od czasu wdrozenia)\n";
}
echo "\n";

// 2. Linie "Autopay" z mozliwych error_log
$cands = array_filter([
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    ini_get('error_log') ?: null,
]);
foreach ($cands as $f) {
    echo "=== $f (linie Autopay) ===\n";
    if (!is_file($f)) { echo "(brak pliku)\n\n"; continue; }
    $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
    $a = array_values(array_filter($lines, fn($l) => stripos($l, 'Autopay') !== false));
    echo ($a ? implode("\n", array_slice($a, -50)) : '(brak linii Autopay)') . "\n\n";
}

// 3. Stan zamowienia testowego
$oid = preg_replace('/[^A-Za-z0-9_-]/', '_', $_POST['order'] ?? 'BOOK-20260601-185829-b5f26f9d');
echo "=== ORDER $oid ===\n";
$of = __DIR__ . '/orders/' . $oid . '.json';
if (is_file($of)) {
    $o = json_decode(file_get_contents($of), true) ?: [];
    echo "status: " . ($o['status'] ?? '?') . "\n";
    echo "kwota: " . ($o['kwota'] ?? $o['total'] ?? '?') . "\n";
    echo "ma autopayData: " . (isset($o['autopayData']) ? 'TAK' : 'NIE') . "\n";
    echo "klucze: " . implode(',', array_keys($o)) . "\n";
} else {
    echo "(brak pliku zamowienia)\n";
}
