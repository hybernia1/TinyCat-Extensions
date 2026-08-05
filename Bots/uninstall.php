<?php
declare(strict_types=1);

return static function (PDO $database, array $context): array {
    $mode = strtolower(trim((string) ($context['mode'] ?? '')));
    if (!in_array($mode, ['keep', 'convert', 'delete'], true)) {
        throw new RuntimeException('Unsupported Bots uninstall mode.');
    }

    $botCount = (int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'bot'")->fetchColumn();
    $contentCount = (int) $database->query(
        "SELECT COUNT(*)
         FROM content c
         INNER JOIN users u ON u.id = c.author_id
         WHERE u.role = 'bot'"
    )->fetchColumn();

    if ($mode === 'keep') {
        return [
            'data_removed' => false,
            'bot_accounts' => $botCount,
            'authored_content' => $contentCount,
        ];
    }

    $database->beginTransaction();
    try {
        if ($mode === 'convert') {
            $database->exec("UPDATE users SET role = 'user' WHERE role = 'bot'");
        } else {
            $database->exec("DELETE FROM users WHERE role = 'bot'");
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    $database->exec(
        'DROP TABLE IF EXISTS bot_source_runs, bot_feed_items, bot_feed_history, bot_sources'
    );

    return [
        'data_removed' => true,
        'bot_accounts' => $botCount,
        'authored_content' => $contentCount,
        'accounts_converted' => $mode === 'convert' ? $botCount : 0,
        'accounts_deleted' => $mode === 'delete' ? $botCount : 0,
    ];
};
