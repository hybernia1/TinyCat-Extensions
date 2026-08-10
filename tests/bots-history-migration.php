<?php
declare(strict_types=1);

$tinycatRoot = realpath((string) ($argv[1] ?? getenv('TINYCAT_ROOT') ?: ''));
if ($tinycatRoot === false || !is_file($tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/bots-history-migration.php /path/to/tinycat\n");
    exit(2);
}

define('TINYCAT', true);
require_once $tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = (array) config('database', []);
if (($config['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP Bots history migration: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($config['host'] ?? 'localhost');
$port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
$charset = (string) ($config['charset'] ?? 'utf8mb4');
$databaseName = 'tinycat_bots_history_' . strtolower(bin2hex(random_bytes(5)));
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
        echo 'SKIP Bots history migration: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    $database->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL, username VARCHAR(32) NOT NULL, role VARCHAR(40) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
    $database->exec('CREATE TABLE content (id BIGINT UNSIGNED NOT NULL, author_id INT UNSIGNED NOT NULL, body VARCHAR(2000) NOT NULL, PRIMARY KEY (id), CONSTRAINT fk_test_content_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE) ENGINE=InnoDB');
    $database->exec("INSERT INTO users (id, username, role) VALUES (2, 'feedbot', 'bot')");
    $database->exec("INSERT INTO content (id, author_id, body) VALUES (10, 2, 'imported post')");

    $install = require dirname(__DIR__) . '/Bots/migrations/20260805_001_install_bots.php';
    $compact = require dirname(__DIR__) . '/Bots/migrations/20260807_001_compact_bot_history.php';
    $consolidate = require dirname(__DIR__) . '/Bots/migrations/20260810_001_consolidate_bot_storage.php';
    $install($database);

    $activeHash = hash('sha256', 'https://example.test/feed');
    $orphanHash = hash('sha256', 'https://example.test/removed-feed');
    $itemHash = str_repeat('f', 64);
    $database->prepare('INSERT INTO bot_sources (id, bot_user_id, name, feed_url, feed_hash, post_template) VALUES (1, 2, ?, ?, ?, ?)')->execute([
        'Example',
        'https://example.test/feed',
        $activeHash,
        '{{title}}',
    ]);
    $database->prepare('INSERT INTO bot_feed_items (source_id, item_hash, content_id, item_guid, item_published_at, created_at) VALUES (1, ?, 10, ?, NOW(), NOW())')->execute([$itemHash, str_repeat('g', 1800)]);
    $insertItem = $database->prepare('INSERT INTO bot_feed_items (source_id, item_hash, content_id, item_guid, item_published_at, created_at) VALUES (1, ?, 10, ?, NOW(), NOW())');
    for ($index = 0; $index < 550; $index++) {
        $insertItem->execute([hash('sha256', 'source-item-' . $index), 'source-item-' . $index]);
    }
    $database->prepare('INSERT INTO bot_feed_history (bot_user_id, feed_hash, item_hash, content_id, item_guid, item_published_at, created_at) VALUES (2, ?, ?, 10, ?, NOW(), DATE_SUB(NOW(), INTERVAL 30 DAY))')->execute([$activeHash, $itemHash, str_repeat('g', 1800)]);
    $database->prepare('INSERT INTO bot_feed_history (bot_user_id, feed_hash, item_hash, content_id, item_guid, item_published_at, created_at) VALUES (2, ?, ?, 10, ?, NOW(), DATE_SUB(NOW(), INTERVAL 30 DAY))')->execute([$orphanHash, hash('sha256', 'removed-item'), str_repeat('g', 1800)]);
    $insertHistory = $database->prepare('INSERT INTO bot_feed_history (bot_user_id, feed_hash, item_hash, content_id, item_guid, item_published_at, created_at) VALUES (2, ?, ?, 10, ?, NOW(), ?)');
    for ($index = 0; $index < 104; $index++) {
        $insertHistory->execute([
            $activeHash,
            hash('sha256', 'active-item-' . $index),
            str_repeat('g', 1800),
            date('Y-m-d H:i:s', time() - $index * 60),
        ]);
    }

    $insertRun = $database->prepare("INSERT INTO bot_source_runs (source_id, bot_user_id, status, started_at, finished_at) VALUES (1, 2, 'current', ?, ?)");
    for ($index = 0; $index < 300; $index++) {
        $time = date('Y-m-d H:i:s', time() - $index * 60);
        $insertRun->execute([$time, $time]);
    }
    $database->exec("INSERT INTO bot_source_runs (source_id, bot_user_id, status, started_at, finished_at) VALUES (1, 2, 'error', DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY))");

    $compact($database);

    $columns = static function (string $table) use ($database): array {
        return array_column($database->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
    };
    $expect($columns('bot_feed_items') === ['source_id', 'item_hash'], 'Feed item history was not compacted to hash keys.');
    $expect($columns('bot_feed_history') === ['bot_user_id', 'feed_hash', 'item_hash', 'created_at'], 'Global feed history was not compacted.');
    $expect((int) $database->query('SELECT COUNT(*) FROM bot_feed_items')->fetchColumn() === 551, 'Feed item keys were not preserved.');
    $expect((int) $database->query('SELECT COUNT(*) FROM bot_feed_history')->fetchColumn() === 100, 'Active feed history was not capped or orphan history was retained.');
    $expect((int) $database->query("SELECT COUNT(*) FROM bot_source_runs WHERE status = 'current'")->fetchColumn() === 250, 'Current run history was not capped.');
    $expect((int) $database->query("SELECT COUNT(*) FROM bot_source_runs WHERE status = 'error'")->fetchColumn() === 0, 'Old diagnostic runs were retained.');
    $indexes = array_column($database->query('SHOW INDEX FROM bot_source_runs')->fetchAll(), 'Key_name');
    $expect(in_array('bot_source_runs_started_index', $indexes, true), 'Run timestamp index was not created.');

    $consolidate($database);

    $tables = array_column($database->query("SHOW TABLES LIKE 'bot_feed_items'")->fetchAll(), 0);
    $expect($tables === [], 'Redundant per-source feed item table was retained.');
    $expect((int) $database->query('SELECT COUNT(*) FROM bot_feed_history')->fetchColumn() === 500, 'Feed item keys were not consolidated into bounded history.');
    $migratedItem = $database->prepare('SELECT COUNT(*) FROM bot_feed_history WHERE bot_user_id = 2 AND feed_hash = ? AND item_hash = ?');
    $migratedItem->execute([$activeHash, $itemHash]);
    $expect((int) $migratedItem->fetchColumn() === 1, 'Migrated feed item key was not retained.');
    $expect($columns('bot_source_runs') === ['id', 'source_id', 'status', 'started_at', 'items_seen', 'error'], 'Run history was not compacted.');
    $expect(!in_array('updated_at', $columns('bot_sources'), true), 'Unused source update timestamp was retained.');
    $expect((int) $database->query("SELECT COUNT(*) FROM bot_source_runs WHERE status <> 'error'")->fetchColumn() === 50, 'Successful run history was not capped.');
    $indexes = array_column($database->query('SHOW INDEX FROM bot_source_runs')->fetchAll(), 'Key_name');
    $expect(!in_array('bot_source_runs_bot_index', $indexes, true), 'Redundant bot run index was retained.');

    echo "PASS Bots history migrations consolidate storage and prune obsolete history.\n";
} finally {
    if ($created) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}
