<?php declare(strict_types=1);

// Copy this file to config.php and edit it.

const RESET_LIST_TTL_MINUTES   = 5; // Number of minutes before reset_list status should be cleared.

// APCu settings.
const APCU_ENABLE              = true;
const APCU_DEFAULT_TTL         = 86400;
const APCU_FRAG_SIZE_LIMIT     = 65536; // If the largest available block is smaller than this, clear the cache.
const APCU_FRAG_CHECK_RATE     = 0.01;  // Check for fragmentation this many times relative to the number of page loads.

// SQL settings.
const SQL_CONNECTOR            = 'PDO';       // "PDO" or "MySQLi" (PDO preferred).
const SQL_DRIVER               = 'mysql';     // Only used by PDO.
const SQL_HOST                 = 'localhost';
const SQL_DATABASE             = '';
const SQL_USERNAME             = '';
const SQL_PASSWORD             = '';

