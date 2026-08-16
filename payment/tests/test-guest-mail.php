<?php
// Harness testowy maili do goscia. Uruchom: php payment/tests/test-guest-mail.php
require __DIR__ . '/../guest-mail.php';

$pass = 0; $fail = 0;
function check(string $name, $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $name\n"; }
    else       { $fail++; echo "FAIL  $name\n"; }
}

$order = [
    'bookingId' => 'BBOOK-20260815-101001-7582175e',
    'imie'      => 'Krzysztof',
    'nazwisko'  => 'Grajcarek',
    'email'     => 'krzysiekk22@vp.pl',
    'checkin'   => '2026-08-23',
    'checkout'  => '2026-08-26',
    'noce'      => 3,
    'kwota'     => 1800,
];

// --- guest_email_valid ---
check('email: poprawny przechodzi',        guest_email_valid('krzysiekk22@vp.pl') === true);
check('email: pusty odrzucony',            guest_email_valid('') === false);
check('email: bez malpy odrzucony',        guest_email_valid('krzysiek.vp.pl') === false);
check('email: same spacje odrzucone',      guest_email_valid('   ') === false);
check('email: naglowkowy wstrzyk odrzucony', guest_email_valid("a@b.pl\r\nBcc: zly@x.pl") === false);

// --- guest_transfer_title: MUSI byc identyczny z ekranem w rezerwacje.html ---
// Frontend: 'Willa Slonce ' + dd.mm.yyyy + '-' + dd.mm.yyyy + ' ' + imie + ' ' + nazwisko
check('tytul przelewu zgodny z ekranem',
    guest_transfer_title($order) === 'Willa Slonce 23.08.2026-26.08.2026 Krzysztof Grajcarek');
check('tytul przelewu bez polskich znakow (wymog bankow)',
    preg_match('/^[\x20-\x7E]+$/', guest_transfer_title(['imie'=>'Zaneta','nazwisko'=>'Wojcik','checkin'=>'2026-08-23','checkout'=>'2026-08-26'])) === 1);

// --- data pl ---
check('data: 2026-08-23 -> 23.08.2026', guest_date_pl('2026-08-23') === '23.08.2026');
check('data: pusta -> pusty string',    guest_date_pl('') === '');

// --- temat maila ---
check('temat rezerwacji zawiera termin', str_contains(guest_booking_subject($order), '23.08.2026'));
check('temat platnosci mowi o potwierdzeniu', str_contains(guest_paid_subject($order), 'Potwierdzenie'));

// --- tresc maila o rezerwacji ---
$b = guest_booking_body($order);
check('tresc: numer konta',        str_contains($b, WILLA_BANK_ACCOUNT));
check('tresc: kwota',              str_contains($b, '1800 z'));
check('tresc: tytul przelewu',     str_contains($b, guest_transfer_title($order)));
check('tresc: id rezerwacji',      str_contains($b, $order['bookingId']));
check('tresc: kontakt zwrotny',    str_contains($b, WILLA_CONTACT_PHONE));
check('tresc: polskie znaki',      str_contains($b, 'Rezerwacja przyjęta'));
check('tresc: brak dlugiej pauzy', !str_contains($b, '—') && !str_contains($b, '–'));

// Adres podajemy dopiero po zaksiegowaniu wplaty, nie w dniu rezerwacji.
check('tresc: BEZ dokladnego adresu', !str_contains($b, 'Markówka'));
check('tresc: miejscowosc moze byc',  str_contains($b, 'Brenn'));

// Podpis: imie i nazwisko + strona + telefon.
check('podpis: imie i nazwisko', str_contains($b, WILLA_OWNER));
check('podpis: link do strony',  str_contains($b, WILLA_SITE));
check('podpis: telefon',         str_contains($b, WILLA_CONTACT_PHONE));

// --- tresc maila po wplacie ---
$p = guest_paid_body($order);
check('platnosc: potwierdza termin',    str_contains($p, '23.08.2026'));
check('platnosc: bez danych do przelewu', !str_contains($p, WILLA_BANK_ACCOUNT));
check('platnosc: PELNY adres obiektu',  str_contains($p, 'Markówka'));
check('platnosc: brak dlugiej pauzy',   !str_contains($p, '—') && !str_contains($p, '–'));
check('platnosc: podpis wlasciciela',   str_contains($p, WILLA_OWNER));

// --- kopia do wlasciciela (podglad tego, co dostal gosc) ---
check('kopia: temat oznaczony prefiksem', str_starts_with(guest_owner_copy_subject('Rezerwacja przyjęta'), '[kopia]'));
check('kopia: zawiera oryginalny temat',  str_contains(guest_owner_copy_subject('Rezerwacja przyjęta'), 'Rezerwacja przyjęta'));
check('kopia: naglowek z adresem goscia', str_contains(guest_owner_copy_body($order, 'TRESC'), 'krzysiekk22@vp.pl'));
check('kopia: zawiera tresc goscia',      str_contains(guest_owner_copy_body($order, 'TRESC'), 'TRESC'));

// --- XSS / wstrzykniecie HTML z formularza ---
$evil = array_merge($order, ['imie' => '<script>alert(1)</script>', 'nazwisko' => '"><b>x']);
check('tresc: dane goscia escapowane', !str_contains(guest_booking_body($evil), '<script>'));

// --- brak wysylki bez poprawnego adresu (nie wywala requestu) ---
check('wysylka: pusty email = false, bez bledu', guest_send_booking_created(array_merge($order, ['email' => ''])) === false);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
