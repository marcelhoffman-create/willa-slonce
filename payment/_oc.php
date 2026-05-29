<?php
// Tymczasowy reset opcache. Do usuniecia zaraz po wywolaniu.
header('Content-Type: text/plain; charset=utf-8');
if (function_exists('opcache_reset')) {
    echo opcache_reset() ? 'ok' : 'fail';
} else {
    echo 'no-opcache';
}
