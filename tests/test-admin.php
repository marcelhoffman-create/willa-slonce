<?php
// Uruchom: php tests/test-admin.php
require __DIR__ . '/../admin-auth.php';
$pass = 0; $fail = 0;
function check(string $n, $c): void { global $pass,$fail; if ($c) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n\n"; } }

check('puste oczekiwane haslo = false', admin_pwd_check('', 'cokolwiek') === false);
check('puste oba = false',              admin_pwd_check('', '') === false);
check('poprawne haslo = true',          admin_pwd_check('tajne123', 'tajne123') === true);
check('zle haslo = false',              admin_pwd_check('tajne123', 'inne') === false);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
