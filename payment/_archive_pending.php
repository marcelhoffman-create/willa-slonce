<?php
// JEDNORAZOWY skrypt: archiwizuje zamowienia o statusie pending/awaiting_payment.
// Chroniony tokenem. Do usuniecia zaraz po wykonaniu.
header('Content-Type: application/json; charset=utf-8');

if (($_GET['token'] ?? '') !== 'c31f13bf409a076d74e83314649c5582') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$archiveDir = __DIR__ . '/orders/_archive';
if (!is_dir($archiveDir)) { @mkdir($archiveDir, 0755, true); }

$moved = [];
$skipped = [];
foreach (glob(__DIR__ . '/orders/*.json') ?: [] as $file) {
    $o = json_decode(@file_get_contents($file), true);
    if (!is_array($o)) continue;
    $id = basename($file, '.json');
    $st = $o['status'] ?? '';
    if ($st === 'pending' || $st === 'awaiting_payment') {
        if (@rename($file, $archiveDir . '/' . $id . '.json')) {
            $moved[] = $id;
        }
    } else {
        $skipped[] = $id . ':' . $st;
    }
}

echo json_encode(['ok' => true, 'archived' => count($moved), 'ids' => $moved, 'kept' => $skipped], JSON_UNESCAPED_UNICODE);
