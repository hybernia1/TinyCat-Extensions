<?php
declare(strict_types=1);

$tinycatRoot = realpath((string) ($argv[1] ?? getenv('TINYCAT_ROOT') ?: ''));
if ($tinycatRoot === false || !is_file($tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/custom-pages-uninstall.php /path/to/tinycat\n");
    exit(2);
}

define('TINYCAT', true);
require_once $tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = (array) config('database', []);
if (($config['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP Custom Pages uninstall: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($config['host'] ?? 'localhost');
$port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
$charset = (string) ($config['charset'] ?? 'utf8mb4');
$databaseName = 'tinycat_custom_pages_uninstall_' . strtolower(bin2hex(random_bytes(5)));
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO(
    sprintf('mysql:host=%s%s;charset=%s', $host, $port, $charset),
    (string) ($config['user'] ?? ''),
    (string) ($config['password'] ?? ''),
    $options
);
$created = false;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    try {
        $server->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    } catch (PDOException $exception) {
        echo 'SKIP Custom Pages uninstall: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    $migration = require dirname(__DIR__) . '/Custom_Pages/migrations/20260806_001_create_custom_pages.php';
    $uninstall = require dirname(__DIR__) . '/Custom_Pages/uninstall.php';

    $prepare = static function () use ($database, $migration): void {
        $database->exec('DROP TABLE IF EXISTS custom_pages');
        $migration($database);
        $database->exec(
            "INSERT INTO custom_pages (slug, title, body_markdown, status, published_at) VALUES
                ('draft-page', 'Draft page', 'Draft body', 'draft', NULL),
                ('published-page', 'Published page', 'Published body', 'published', NOW())"
        );
    };
    $tableExists = static function () use ($database): bool {
        return (int) $database->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'custom_pages'"
        )->fetchColumn() === 1;
    };

    $prepare();
    $result = $uninstall($database, ['mode' => 'keep']);
    $expect($result['data_removed'] === false, 'Keep mode reported removed data.');
    $expect($tableExists(), 'Keep mode removed the custom pages table.');
    $expect((int) $database->query('SELECT COUNT(*) FROM custom_pages')->fetchColumn() === 2, 'Keep mode removed page data.');
    echo "PASS Custom Pages uninstall keeps pages\n";

    $prepare();
    $result = $uninstall($database, ['mode' => 'delete']);
    $expect($result['data_removed'] === true, 'Delete mode did not report removed data.');
    $expect(!$tableExists(), 'Delete mode retained the custom pages table.');
    echo "PASS Custom Pages uninstall deletes pages\n";
} finally {
    if ($created) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
