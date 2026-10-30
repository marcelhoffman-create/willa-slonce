<?php
/** Liczenie cen po stronie serwera. Bez efektow ubocznych. */

/** Cena za jedna noc: zakres dat (od i do wlacznie, jak w rezerwacje.html) ma pierwszenstwo przed cena bazowa. */
function price_for_night(array $prices, string $date, int $guests): ?int
{
    $g = (string) $guests;
    foreach (($prices['date_ranges'] ?? []) as $r) {
        if (!isset($r['from'], $r['to']) || $date < $r['from'] || $date > $r['to']) continue;
        if (isset($r['by_guests'])) $p = $r['by_guests'][$g] ?? $r['by_guests']['2'] ?? null;
        else $p = $r['price'] ?? null;
        return is_numeric($p) ? (int) $p : null;
    }
    $p = $prices['by_guests'][$g] ?? null;
    return is_numeric($p) ? (int) $p : null;
}

/** Noce zajete wg zrodel, ktore widzi strona: RoomAdmin (prices.json: zajete), cache iCal Booking.com
 *  i reczne blokady (blocked-manual.json) - te same pliki co availability.php. */
function blocked_nights(array $prices, string $root): array
{
    $out = array_flip($prices['zajete'] ?? []);
    $cache = json_decode(@file_get_contents($root . '/availability_cache.json'), true);
    foreach (($cache['blocked'] ?? []) as $d) $out[$d] = true;
    $manual = json_decode(@file_get_contents($root . '/blocked-manual.json'), true);
    foreach (($manual['ranges'] ?? []) as $r) {
        try {
            $cur = new DateTime($r['from']); $end = new DateTime($r['to']);
            for (; $cur <= $end; $cur->modify('+1 day')) $out[$cur->format('Y-m-d')] = true;
        } catch (Exception $e) {}
    }
    return $out;
}

/** Cena rezerwacji = suma cen kolejnych nocy (wg liczby gosci). Noc bez ceny (bez_ceny) = termin niedostepny. */
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
    if ($nights < 2) return $err('Minimalna dlugosc rezerwacji to 2 doby.');
    if ($nights > 60) return $err('Najdluzszy pobyt online to 60 nocy.');
    $dzis = (new DateTime('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    if ($checkin < $dzis) return $err('Data przyjazdu jest w przeszlosci.');

    $prices = json_decode(@file_get_contents($pricesPath), true);
    if (!is_array($prices)) return $err('Brak cennika.');
    $bezCeny = array_flip($prices['bez_ceny'] ?? []);
    $zajete = blocked_nights($prices, dirname($pricesPath));
    $cennikDo = $prices['cennik_do'] ?? null;

    $amount = 0;
    $d = clone $in;
    for ($i = 0; $i < $nights; $i++, $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        if (isset($bezCeny[$date]) || isset($zajete[$date])) return $err('Termin ' . $date . ' jest niedostepny.');
        if ($cennikDo !== null && $date > $cennikDo) return $err('Rezerwacje online sa mozliwe do ' . $cennikDo . '.');
        $p = price_for_night($prices, $date, $guests);
        if ($p === null || $p < 1) return $err('Brak ceny dla podanej liczby gosci.');
        $amount += $p;
    }

    return ['ok' => true, 'amount' => $amount, 'nights' => $nights, 'error' => null];
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
