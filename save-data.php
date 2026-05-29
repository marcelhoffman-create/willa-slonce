<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

define('ADMIN_PASSWORD', 'brenna2026');
if (($body['password'] ?? '') !== ADMIN_PASSWORD) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$type = $body['type'] ?? '';

// ===== PRODUKTY =====
if ($type === 'products') {
    $products = [];
    foreach (($body['products'] ?? []) as $p) {
        if (empty($p['name'])) continue;
        $price = intval($p['price'] ?? 0);
        if ($price < 1 || $price > 99999) continue;
        $id = $p['id'] ?? preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower(transliterate($p['name'])));
        $products[] = [
            'id'          => $id,
            'name'        => trim($p['name']),
            'origin'      => trim($p['origin'] ?? ''),
            'description' => trim($p['description'] ?? ''),
            'price'       => $price,
            'image'       => trim($p['image'] ?? ''),
            'available'   => ($p['available'] ?? true) !== false,
        ];
    }
    $data = ['products' => $products, 'updated' => date('Y-m-d')];
    $file = __DIR__ . '/products.json';

// ===== CENY =====
} elseif ($type === 'prices') {
    $byGuests = $body['by_guests'] ?? [];
    for ($i = 1; $i <= 6; $i++) {
        $p = intval($byGuests[(string)$i] ?? 0);
        if ($p < 1 || $p > 9999) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Nieprawidlowa cena dla ' . $i . ' gosci']);
            exit;
        }
        $byGuests[(string)$i] = $p;
    }
    $ranges = [];
    foreach (($body['date_ranges'] ?? []) as $r) {
        if (empty($r['from']) || empty($r['to']) || empty($r['by_guests'])) continue;
        $bg = [];
        $valid = true;
        for ($i = 1; $i <= 6; $i++) {
            $p = intval($r['by_guests'][(string)$i] ?? 0);
            if ($p < 1 || $p > 9999) { $valid = false; break; }
            $bg[(string)$i] = $p;
        }
        if (!$valid) continue;
        $ranges[] = [
            'from'      => $r['from'],
            'to'        => $r['to'],
            'label'     => trim($r['label'] ?? ''),
            'by_guests' => $bg,
        ];
    }
    usort($ranges, fn($a, $b) => strcmp($a['from'], $b['from']));
    $data = ['by_guests' => $byGuests, 'date_ranges' => $ranges, 'updated' => date('Y-m-d')];
    $file = __DIR__ . '/prices.json';

// ===== RĘCZNE BLOKI DAT =====
} elseif ($type === 'blocked-add') {
    $from = trim($body['from'] ?? '');
    $to   = trim($body['to']   ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nieprawidlowy format daty']);
        exit;
    }
    if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }
    $file   = __DIR__ . '/blocked-manual.json';
    $manual = file_exists($file) ? json_decode(file_get_contents($file), true) : ['ranges' => []];
    $id     = bin2hex(random_bytes(6));
    $manual['ranges'][] = ['id' => $id, 'from' => $from, 'to' => $to, 'note' => trim($body['note'] ?? '')];
    $manual['updated']  = date('Y-m-d');
    file_put_contents($file, json_encode($manual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;

} elseif ($type === 'blocked-delete') {
    $id   = trim($body['id'] ?? '');
    $file = __DIR__ . '/blocked-manual.json';
    if (!$id || !file_exists($file)) { echo json_encode(['ok' => true]); exit; }
    $manual = json_decode(file_get_contents($file), true);
    $manual['ranges'] = array_values(array_filter($manual['ranges'] ?? [], fn($r) => $r['id'] !== $id));
    $manual['updated'] = date('Y-m-d');
    file_put_contents($file, json_encode($manual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo json_encode(['ok' => true]);
    exit;

} elseif ($type === 'blocked-list') {
    $file   = __DIR__ . '/blocked-manual.json';
    $manual = file_exists($file) ? json_decode(file_get_contents($file), true) : ['ranges' => []];
    echo json_encode(['ok' => true, 'ranges' => $manual['ranges'] ?? []]);
    exit;

} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nieznany typ: ' . htmlspecialchars($type)]);
    exit;
}

if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n") === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Blad zapisu pliku']);
    exit;
}

echo json_encode(['ok' => true, 'type' => $type, 'updated' => date('Y-m-d H:i:s')]);

function transliterate(string $s): string {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z'];
    return strtr($s, $map);
}
