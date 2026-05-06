<?php
/**
 * Zwraca zablokowane daty jako JSON.
 * Źródła: (1) iCal Booking.com  (2) ręczne bloki z blocked-manual.json
 * Cache iCal: 1 godzina.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

define('ICAL_URL',   'https://ical.booking.com/v1/export?t=fa532493-1bdd-43f2-ac70-b148ec4296e3');
define('CACHE_FILE', __DIR__ . '/availability_cache.json');
define('MANUAL_FILE',__DIR__ . '/blocked-manual.json');
define('CACHE_TTL',  900); // 15 minut

// Wymuś odswiezenie cache (tylko z adminem)
if (isset($_GET['purge']) && $_GET['purge'] === 'brenna2026') {
    @unlink(CACHE_FILE);
}

// --- 1. Daty z iCal (z cache 1h) ---
$icalBlocked = [];

if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    $cached = json_decode(file_get_contents(CACHE_FILE), true);
    $icalBlocked = $cached['blocked'] ?? [];
} else {
    $ctx  = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'WillaSlonce/1.0']]);
    $ical = @file_get_contents(ICAL_URL, false, $ctx);

    if ($ical !== false) {
        preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $ical, $events);
        foreach ($events[0] as $event) {
            $start = parseIcalDate($event, 'DTSTART');
            $end   = parseIcalDate($event, 'DTEND');
            if (!$start || !$end) continue;
            $cur = clone $start;
            while ($cur < $end) {
                $icalBlocked[] = $cur->format('Y-m-d');
                $cur->modify('+1 day');
            }
        }
        $icalBlocked = array_values(array_unique($icalBlocked));
        file_put_contents(CACHE_FILE, json_encode(['blocked' => $icalBlocked]));
    } elseif (file_exists(CACHE_FILE)) {
        $cached = json_decode(file_get_contents(CACHE_FILE), true);
        $icalBlocked = $cached['blocked'] ?? [];
    }
}

// --- 2. Ręczne bloki z blocked-manual.json ---
$manualBlocked = [];

if (file_exists(MANUAL_FILE)) {
    $manual = json_decode(file_get_contents(MANUAL_FILE), true);
    foreach ($manual['ranges'] ?? [] as $range) {
        try {
            $start = new DateTime($range['from']);
            $end   = new DateTime($range['to']);
            $cur   = clone $start;
            while ($cur <= $end) {
                $manualBlocked[] = $cur->format('Y-m-d');
                $cur->modify('+1 day');
            }
        } catch (Exception $e) {}
    }
}

// --- 3. Scal i zwróć ---
$all = array_values(array_unique(array_merge($icalBlocked, $manualBlocked)));
sort($all);

echo json_encode(['blocked' => $all], JSON_UNESCAPED_UNICODE);

function parseIcalDate(string $event, string $prop): ?DateTime
{
    if (!preg_match('/' . $prop . '[^:]*:(\d{8})(T\d{6}Z?)?/', $event, $m)) return null;
    $d = DateTime::createFromFormat('Ymd', $m[1], new DateTimeZone('UTC'));
    return $d ?: null;
}
