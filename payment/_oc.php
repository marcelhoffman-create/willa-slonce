<?php
header("Content-Type: text/plain");
@unlink(__DIR__."/orders/_itn_raw.log");
echo function_exists("opcache_reset")?(opcache_reset()?"ok":"fail"):"no-opcache";
