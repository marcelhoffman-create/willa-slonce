<?php
// Tymczasowy reset opcache. Do usuniecia zaraz po wywolaniu.
header('Content-Type: text/plain; charset=utf-8');
echo function_exists('opcache_reset') ? (opcache_reset() ? 'ok' : 'fail') : 'no-opcache';
