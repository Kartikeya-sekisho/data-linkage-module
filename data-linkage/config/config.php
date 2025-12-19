
<?php
return [
  // Orion base URL
  'orion_base_url'   => 'http://localhost:1026',

  // Fiware headers (default)
  'fiware_service'   => 'sekisho',
  'fiware_servicepath'=> '/',

  // Directories
  'log_dir'          => __DIR__ . '/../logs',
  'mapping_dir'      => __DIR__ . '/../mappings',

  // HTTP behavior
  'timeout_seconds'  => 15,
  'http_retries'     => 1,
  'retry_sleep_ms'   => 200
];