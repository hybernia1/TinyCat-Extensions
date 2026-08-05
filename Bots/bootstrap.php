<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/Bots.php';
require_once __DIR__ . '/BotAdmin.php';

Bots::register();
