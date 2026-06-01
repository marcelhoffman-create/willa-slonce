<?php
/**
 * Wspolne uwierzytelnianie panelu admina.
 * Haslo poza repo: admin-credentials.php (gitignore). Fallback '' = panel zablokowany.
 */
$adminCred = __DIR__ . '/admin-credentials.php';
if (file_exists($adminCred)) {
    require_once $adminCred;
}
if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', '');
}

/** Czysta weryfikacja: puste oczekiwane haslo NIGDY nie przechodzi. Timing-safe. */
function admin_pwd_check(string $expected, string $provided): bool
{
    return $expected !== '' && hash_equals($expected, $provided);
}

function admin_password_valid(string $provided): bool
{
    return admin_pwd_check(ADMIN_PASSWORD, $provided);
}

/**
 * Sanityzuje identyfikator zamowienia do bezpiecznej nazwy pliku.
 * Blokuje path traversal — dozwolone tylko [A-Za-z0-9_-], reszta -> '_'.
 */
function safe_order_id(string $id): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $id);
}
