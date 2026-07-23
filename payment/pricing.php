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
    if ($nights < 2) return $err('Minimalna dlugosc rezerwacji to 2 doby.');

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
