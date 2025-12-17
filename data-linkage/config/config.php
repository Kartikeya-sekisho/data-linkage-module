<?php

return [
    // base
    'orion_base_url' => 'http://localhost:1026',
    // default headers
    'fiware_service' => 'sekisho',
    'fiware_servicepath' => '/',
    // directories
    'log_dir' => __DIR__ . '/../logs',
    'mapping_dir' => __DIR__ . '/../mappings',
    // retry settings
    'timeout_seconds' => 15,
    'http_retries' => 1,
    'retry_sleep_ms' => 200
];