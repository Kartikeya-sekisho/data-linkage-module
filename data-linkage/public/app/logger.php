<?php

function app_log(string $message, string $level = 'INFO'): void {
    $cfg = require __DIR__ . '/../../config/config.php';
    $dir = $cfg['log_dir'];
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    // Always append to a singe file
    $file = rtrim($dir, '/\\') . '/app.log';
    $entry = sprintf(
        "[%s] [%s] %s%s",
        date('Y-m-d H:i:s'),
        $level,
        $message,
        PHP_EOL
    );
    file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}