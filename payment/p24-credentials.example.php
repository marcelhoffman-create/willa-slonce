<?php
/**
 * SZABLON pliku p24-credentials.php
 *
 * Skopiuj ten plik jako p24-credentials.php, uzupelnij wartosci
 * i wgraj recznie przez FTP na serwer.
 * Plik p24-credentials.php jest w .gitignore — NIE trafia do repozytorium.
 *
 * Panel Przelewy24: https://panel.przelewy24.pl → Moje konto → API
 */

define('P24_MERCHANT_ID', '');   // np. '123456'
define('P24_POS_ID',      '');   // zwykle = MERCHANT_ID
define('P24_CRC',         '');   // klucz CRC
define('P24_API_KEY',     '');   // API key (haslo do REST API)
define('P24_SANDBOX',     true); // zmien na false na produkcji!
