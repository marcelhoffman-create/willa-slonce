<?php
header("Content-Type: text/plain");
echo function_exists("opcache_reset") ? (opcache_reset() ? "ok" : "fail") : "no-opcache";
