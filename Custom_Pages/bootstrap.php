<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/CustomPages.php';
require_once __DIR__ . '/CustomPagesAdmin.php';

CustomPages::register();
