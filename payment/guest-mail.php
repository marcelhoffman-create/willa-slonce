<?php
/**
 * Maile do GOSCIA (nie do admina).
 *
 * Do 08.2026 serwis wysylal maila wylacznie na skrzynke wlasciciela, a gosc
 * widzial dane do przelewu tylko na ekranie po rezerwacji. Zamkniecie karty
 * = brak numeru konta i brak mozliwosci zaplaty (przypadek z 15.08.2026).
 *
 * Wysylka przez mail() na Zenboxie - koszt zero, bez zaleznosci od n8n.
 */

const WILLA_BANK_ACCOUNT   = '13 1050 1214 1000 0023 1765 9577';
const WILLA_BANK_RECIPIENT = 'Willa Słońce - właściciel';
const WILLA_CONTACT_EMAIL  = 'marcelhoffman@gmail.com';
const WILLA_CONTACT_PHONE  = '+48 690 300 359';
const WILLA_OWNER          = 'Marcel Hoffman';
const WILLA_SITE           = 'https://willaslonce.pl';
// Godziny zgodne z regulaminem (regulamin.html): przyjazd od 15:00, wyjazd do 12:00.
const WILLA_CHECKIN_TIME   = '15:00';
const WILLA_CHECKOUT_TIME  = '12:00';
const WILLA_FROM           = 'Willa Slonce <noreply@willaslonce.pl>';

/** Adres nadaje sie do wysylki (odrzuca tez proby wstrzykniecia naglowkow). */
function guest_email_valid(string $email): bool
{
    $email = trim($email);
    if ($email === '') return false;
    if (preg_match('/[\r\n]/', $email)) return false;
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** 2026-08-23 -> 23.08.2026 */
function guest_date_pl(string $iso): string
{
    $p = explode('-', trim($iso));
    return count($p) === 3 ? $p[2] . '.' . $p[1] . '.' . $p[0] : '';
}

/**
 * Tytul przelewu. MUSI byc identyczny z ekranem w rezerwacje.html (showSuccess),
 * inaczej ta sama rezerwacja wraca z dwoma roznymi tytulami i nie da sie
 * dopasowac wplaty. Celowo bez polskich znakow - wymog systemow bankowych.
 */
function guest_transfer_title(array $o): string
{
    $ascii = static function (string $s): string {
        $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
        return strtr($s, $map);
    };

    return 'Willa Slonce '
        . guest_date_pl((string) ($o['checkin'] ?? '')) . '-' . guest_date_pl((string) ($o['checkout'] ?? ''))
        . ' ' . $ascii((string) ($o['imie'] ?? '')) . ' ' . $ascii((string) ($o['nazwisko'] ?? ''));
}

function guest_booking_subject(array $o): string
{
    return 'Rezerwacja przyjęta - Willa Słońce, ' . guest_date_pl((string) ($o['checkin'] ?? ''));
}

function guest_paid_subject(array $o): string
{
    return 'Potwierdzenie rezerwacji - Willa Słońce, ' . guest_date_pl((string) ($o['checkin'] ?? ''));
}

/**
 * Wspolna ramka HTML maila. Stopka to podpis wlasciciela, nie formulka automatu.
 * Dokladnego adresu NIE podajemy w mailach - idzie osobno, w dniu przyjazdu.
 */
function guest_mail_shell(string $title, string $inner): string
{
    $where = 'Willa Słońce, Brenna';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
        . '<body style="font-family:sans-serif;color:#222;max-width:600px;margin:0 auto;padding:20px;line-height:1.6;">'
        . '<h2 style="color:#C17817;margin-bottom:16px;">' . $title . '</h2>'
        . $inner
        . '<div style="margin-top:28px;padding-top:16px;border-top:1px solid #eee;color:#666;font-size:.9em;">'
        . '<p style="margin:0 0 4px;"><strong>' . WILLA_OWNER . '</strong><br>' . $where . '</p>'
        . '<p style="margin:0;">'
        . 'tel. <a href="tel:' . str_replace(' ', '', WILLA_CONTACT_PHONE) . '" style="color:#C17817;">' . WILLA_CONTACT_PHONE . '</a><br>'
        . '<a href="' . WILLA_SITE . '" style="color:#C17817;">' . WILLA_SITE . '</a>'
        . '</p>'
        . '</div></body></html>';
}

/** Wiersz tabelki. */
function guest_row(string $label, string $value, bool $shaded = false, bool $strong = false): string
{
    $bg = $shaded ? 'background:#f5f0e8;' : '';
    $st = $strong ? 'font-size:1.1em;color:#C17817;font-weight:bold;' : '';
    return '<tr><td style="padding:6px 12px;' . $bg . 'font-weight:bold;width:40%;">' . $label . '</td>'
        . '<td style="padding:6px 12px;' . $bg . $st . '">' . $value . '</td></tr>';
}

/** Mail wysylany od razu po zlozeniu rezerwacji - z danymi do przelewu. */
function guest_booking_body(array $o): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $inner = '<p>Dziękujemy za rezerwację. Termin jest wstępnie zarezerwowany dla Państwa.</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:16px 0;">'
        . guest_row('Gość', $e(($o['imie'] ?? '') . ' ' . ($o['nazwisko'] ?? '')), true)
        . guest_row('Przyjazd', $e(guest_date_pl((string) ($o['checkin'] ?? ''))))
        . guest_row('Wyjazd', $e(guest_date_pl((string) ($o['checkout'] ?? ''))), true)
        . guest_row('Liczba nocy', $e($o['noce'] ?? ''))
        . guest_row('Numer rezerwacji', $e($o['bookingId'] ?? ''), true)
        . '</table>'
        . '<h3 style="margin-bottom:8px;">Dane do przelewu</h3>'
        . '<table style="border-collapse:collapse;width:100%;margin-bottom:16px;">'
        . guest_row('Odbiorca', WILLA_BANK_RECIPIENT, true)
        . guest_row('Numer konta', '<strong>' . WILLA_BANK_ACCOUNT . '</strong>')
        . guest_row('Kwota', intval($o['kwota'] ?? 0) . ' zł', true, true)
        . guest_row('Tytuł przelewu', '<strong>' . $e(guest_transfer_title($o)) . '</strong>')
        . '</table>'
        . '<p>Prosimy o wpłatę w ciągu <strong>48 godzin</strong>. Po zaksięgowaniu wpłaty wyślemy '
        . 'potwierdzenie rezerwacji.</p>';

    return guest_mail_shell('Rezerwacja przyjęta', $inner);
}

/** Mail wysylany po zaksiegowaniu wplaty - bez danych do przelewu. */
function guest_paid_body(array $o): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $inner = '<p>Wpłata dotarła. Pobyt Państwa w Willi Słońce jest potwierdzony.</p>'
        . '<table style="border-collapse:collapse;width:100%;margin:16px 0;">'
        . guest_row('Gość', $e(($o['imie'] ?? '') . ' ' . ($o['nazwisko'] ?? '')), true)
        . guest_row('Przyjazd', $e(guest_date_pl((string) ($o['checkin'] ?? ''))) . ', od ' . WILLA_CHECKIN_TIME)
        . guest_row('Wyjazd', $e(guest_date_pl((string) ($o['checkout'] ?? ''))) . ', do ' . WILLA_CHECKOUT_TIME, true)
        . guest_row('Numer rezerwacji', $e($o['bookingId'] ?? ''), true)
        . '</table>'
        . '<p>Szczegóły dojazdu i odbioru kluczy prześlemy w dniu Państwa przyjazdu. '
        . 'Gdyby coś się zmieniło, prosimy o wiadomość.</p>'
        . '<p>Do zobaczenia w Brennej.</p>';

    return guest_mail_shell('Rezerwacja potwierdzona', $inner);
}

/** Temat kopii dla wlasciciela - prefiks, zeby dalo sie filtrowac w skrzynce. */
function guest_owner_copy_subject(string $subject): string
{
    return '[kopia] ' . $subject;
}

/** Kopia maila goscia z naglowkiem: do kogo poszlo. */
function guest_owner_copy_body(array $o, string $guestBody): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $head = '<div style="max-width:600px;margin:0 auto;padding:12px 20px;background:#f5f0e8;'
        . 'border-left:4px solid #C17817;font-family:sans-serif;font-size:.9em;color:#555;">'
        . 'Kopia wiadomości wysłanej do gościa: <strong>' . $e($o['email'] ?? '') . '</strong>'
        . ' (' . $e(($o['imie'] ?? '') . ' ' . ($o['nazwisko'] ?? '')) . ', tel. ' . $e($o['telefon'] ?? 'brak') . ')'
        . '</div>';

    return $head . $guestBody;
}

/**
 * Wspolna wysylka. Zwraca false gdy brak poprawnego adresu - nigdy nie przerywa requestu.
 * Kopia leci do wlasciciela, zeby bylo widac co dostal gosc i czy w ogole poszlo.
 */
function guest_send(array $o, string $subject, string $body): bool
{
    if (!guest_email_valid((string) ($o['email'] ?? ''))) return false;

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . WILLA_FROM . "\r\n";
    $headers .= 'Reply-To: ' . WILLA_CONTACT_EMAIL . "\r\n";

    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $sent    = @mail(trim((string) $o['email']), $encoded, $body, $headers);

    // Kopia dla wlasciciela. Nie wplywa na wynik - liczy sie dostarczenie do goscia.
    $copyHeaders  = 'MIME-Version: 1.0' . "\r\n";
    $copyHeaders .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $copyHeaders .= 'From: ' . WILLA_FROM . "\r\n";
    $copyHeaders .= 'Reply-To: ' . trim((string) $o['email']) . "\r\n"; // odpowiedz idzie wprost do goscia
    @mail(
        WILLA_CONTACT_EMAIL,
        '=?UTF-8?B?' . base64_encode(guest_owner_copy_subject($subject)) . '?=',
        guest_owner_copy_body($o, $body),
        $copyHeaders
    );

    return $sent;
}

function guest_send_booking_created(array $o): bool
{
    return guest_send($o, guest_booking_subject($o), guest_booking_body($o));
}

function guest_send_booking_paid(array $o): bool
{
    return guest_send($o, guest_paid_subject($o), guest_paid_body($o));
}
