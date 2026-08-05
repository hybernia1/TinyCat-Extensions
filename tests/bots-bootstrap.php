<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

$tinycatRoot = realpath((string) ($argv[1] ?? getenv('TINYCAT_ROOT') ?: ''));
if ($tinycatRoot === false || !is_file($tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/bots-bootstrap.php /path/to/tinycat\n");
    exit(2);
}

define('TINYCAT', true);
require_once $tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (class_exists('ExtensionRegistry', false)) {
    throw new RuntimeException('TinyCat still exposes the global ExtensionRegistry compatibility name.');
}
if (Registry::has('bots')) {
    throw new RuntimeException('The test TinyCat installation already loaded Bots.');
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Bots' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!Registry::has('bots')) {
    throw new RuntimeException('Bots did not register through TinyCat\\Extension\\Registry.');
}
if (Registry::requiredTables() !== ['bot_sources', 'bot_feed_items', 'bot_feed_history', 'bot_source_runs']) {
    throw new RuntimeException('Bots registered an unexpected database contract.');
}
if (UserRoles::allowsLogin('bot') || UserRoles::profileSchemaType('bot') !== 'Organization') {
    throw new RuntimeException('Bots did not register its account role contract.');
}

echo "PASS Bots boots without the global ExtensionRegistry alias.\n";
