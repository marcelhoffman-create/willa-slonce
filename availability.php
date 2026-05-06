<?php
/**
 * Pobiera rezerwacje z Booking.com (iCal) i zwraca zablokowane daty jako JSON.
 * Cache: 1 godzina.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

define('ICAL_URL', 'https://ical.booking.com/v1/export?t=fa532493-1bdd-43f2-ac70-b148ec4296e3');
define('CACHE_FILE', __DIR__ . '/availability_cache.json');
define('CACHE_TTL', 3600);

// Zwroc z cache jesli swiezy
if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    echo file_get_contents(CACHE_FILE);
    exit;
}

// Pobierz iCal z Booking.com
$ctx = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'Mozilla/5.0 (compatible; WillaSlonce/1.0)',
    ]
]);

$ical = @file_get_contents(ICAL_URL, false, $ctx);

if ($ical === false) {
    // Jesli nie mozna pobrac, zwroc stary cache lub pusty wynik
    if (file_exists(CACHE_FILE)) {
        echo file_get_contents(CACHE_FILE);
    } else {
        echo json_encode(['blocked' => []]);
    }
    exit;
}

// Parsuj VEVENT — wyciagnij DTSTART i DTEND
$blocked = [];
preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $ical, $events);

foreach ($events[0] as $event) {
    $start = parseIcalDate($event, 'DTSTART');
    $end   = parseIcalDate($event, 'DTEND');

    if (!$start || !$end) continue;

    // Zablokuj kazdy dzien od DTSTART do DTEND-1
    // (dzien wymeldowania jest wolny pod nowy check-in)
    $current = clone $start;
    while ($current < $end) {
        $blocked[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }
}

$blocked = array_values(array_unique($blocked));
sort($blocked);

$result = json_encode(['blocked' => $blocked], JSON_UNESCAPED_UNICODE);

// Zapisz cache
file_put_contents(CACHE_FILE, $result);

echo $result;

// --- Pomocnicze ---

function parseIcalDate(string $event, string $prop): ?DateTime
{
    // Obsluguje formaty: DTSTART;VALUE=DATE:20260510 i DTSTART:20260510T150000Z
    if (!preg_match('/' . $prop . '[^:]*:(\d{8})(T\d{6}Z?)?/', $event, $m)) {
        return null;
    }
    $date = DateTime::createFromFormat('Ymd', $m[1], new DateTimeZone('UTC'));
    return $date ?: null;
}
