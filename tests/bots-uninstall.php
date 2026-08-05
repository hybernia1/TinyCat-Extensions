<?php
declare(strict_types=1);

$tinycatRoot = realpath((string) ($argv[1] ?? getenv('TINYCAT_ROOT') ?: ''));
if ($tinycatRoot === false || !is_file($tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/bots-uninstall.php /path/to/tinycat\n");
    exit(2);
}

define('TINYCAT', true);
require_once $tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = (array) config('database', []);
if (($config['driver'] ?? 'mysql') !== 'mysql') {
    echo "SKIP Bots uninstall: a local MySQL database is required.\n";
    exit(0);
}

$host = (string) ($config['host'] ?? 'localhost');
$port = isset($config['port']) ? ';port=' . (int) $config['port'] : '';
$charset = (string) ($config['charset'] ?? 'utf8mb4');
$databaseName = 'tinycat_bots_uninstall_' . strtolower(bin2hex(random_bytes(5)));
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
$passed = 0;

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
        echo 'SKIP Bots uninstall: unable to create an isolated database (' . $exception->getMessage() . ").\n";
        exit(0);
    }

    $database = new PDO(
        sprintf('mysql:host=%s%s;dbname=%s;charset=%s', $host, $port, $databaseName, $charset),
        (string) ($config['user'] ?? ''),
        (string) ($config['password'] ?? ''),
        $options
    );
    $migration = require dirname(__DIR__) . '/Bots/migrations/20260805_001_install_bots.php';
    $uninstall = require dirname(__DIR__) . '/Bots/uninstall.php';

    $prepare = static function () use ($database, $migration): void {
        $database->exec('SET FOREIGN_KEY_CHECKS = 0');
        $database->exec(
            'DROP TABLE IF EXISTS bot_source_runs, bot_feed_items, bot_feed_history, bot_sources, content, users'
        );
        $database->exec('SET FOREIGN_KEY_CHECKS = 1');
        $database->exec(
            "CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(32) NOT NULL,
                role VARCHAR(40) NOT NULL DEFAULT 'user',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $database->exec(
            "CREATE TABLE content (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                author_id INT UNSIGNED NOT NULL,
                body VARCHAR(2000) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_test_content_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $database->exec("INSERT INTO users (id, username, role) VALUES (1, 'reader', 'user'), (2, 'feedbot', 'bot')");
        $database->exec(
            "INSERT INTO content (author_id, body) VALUES (1, 'reader post'), (2, 'bot post one'), (2, 'bot post two')"
        );
        $migration($database);
    };

    $tableExists = static function (string $table) use ($database): bool {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    };

    $prepare();
    $result = $uninstall($database, ['mode' => 'keep']);
    $expect($result['data_removed'] === false, 'Keep mode reported removed data.');
    $expect($tableExists('bot_sources'), 'Keep mode removed extension tables.');
    $expect((int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'bot'")->fetchColumn() === 1, 'Keep mode changed bot accounts.');
    $expect((int) $database->query('SELECT COUNT(*) FROM content')->fetchColumn() === 3, 'Keep mode changed content.');
    echo "PASS Bots uninstall keeps data\n";
    $passed++;

    $prepare();
    $result = $uninstall($database, ['mode' => 'convert']);
    $expect($result['data_removed'] === true, 'Convert mode did not report removed data.');
    $expect(!$tableExists('bot_sources'), 'Convert mode retained extension tables.');
    $expect((int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'bot'")->fetchColumn() === 0, 'Convert mode retained the bot role.');
    $expect((int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn() === 2, 'Convert mode did not convert the bot account.');
    $expect((int) $database->query('SELECT COUNT(*) FROM content')->fetchColumn() === 3, 'Convert mode removed content.');
    echo "PASS Bots uninstall converts accounts\n";
    $passed++;

    $prepare();
    $result = $uninstall($database, ['mode' => 'delete']);
    $expect($result['data_removed'] === true, 'Delete mode did not report removed data.');
    $expect(!$tableExists('bot_sources'), 'Delete mode retained extension tables.');
    $expect((int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'Delete mode retained a bot account.');
    $expect((int) $database->query('SELECT COUNT(*) FROM content')->fetchColumn() === 1, 'Delete mode did not cascade bot content.');
    echo "PASS Bots uninstall deletes accounts and content\n";
    $passed++;
} finally {
    if ($created) {
        $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
    }
}

echo "\nBots uninstall tests: {$passed} passed.\n";
