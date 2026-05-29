<?php
/**
 * SZABLON. Skopiuj jako payment/autopay-credentials.php (plik poza git, wgraj przez FTP).
 * Wartosci z panel.autopay.eu -> usluga -> dane integracji.
 */
define('AUTOPAY_SERVICE_ID', '');   // np. 211642
define('AUTOPAY_HASH_KEY',   '');   // WYROTOWANY klucz wspoldzielony
define('AUTOPAY_HASH_ALGO',  'sha256'); // 'sha256' lub 'sha512' — wg ustawien uslugi
define('AUTOPAY_HASH_SEP',   '|');      // separator hasha — wg ustawien uslugi
define('AUTOPAY_TEST_MODE',  false);    // true = testpay.autopay.eu
