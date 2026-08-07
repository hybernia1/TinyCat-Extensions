<?php
declare(strict_types=1);

return static function (PDO $database): void {
    $hasColumn = static function (string $table, string $column) use ($database): bool {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$table, $column]);
        return (int) $statement->fetchColumn() > 0;
    };
    $hasIndex = static function (string $table, string $index) use ($database): bool {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $statement->execute([$table, $index]);
        return (int) $statement->fetchColumn() > 0;
    };
    $hasForeignKey = static function (string $table, string $constraint) use ($database): bool {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?'
        );
        $statement->execute([$table, $constraint, 'FOREIGN KEY']);
        return (int) $statement->fetchColumn() > 0;
    };

    if ($hasForeignKey('bot_feed_items', 'fk_bot_feed_items_content')) {
        $database->exec('ALTER TABLE bot_feed_items DROP FOREIGN KEY fk_bot_feed_items_content');
    }
    if ($hasIndex('bot_feed_items', 'bot_feed_items_content_index')) {
        $database->exec('ALTER TABLE bot_feed_items DROP INDEX bot_feed_items_content_index');
    }
    if ($hasIndex('bot_feed_items', 'bot_feed_items_created_index')) {
        $database->exec('ALTER TABLE bot_feed_items DROP INDEX bot_feed_items_created_index');
    }

    $itemColumns = [];
    foreach (['content_id', 'item_guid', 'item_published_at', 'created_at'] as $column) {
        if ($hasColumn('bot_feed_items', $column)) {
            $itemColumns[] = 'DROP COLUMN ' . $column;
        }
    }
    if ($itemColumns !== []) {
        $database->exec('ALTER TABLE bot_feed_items ' . implode(', ', $itemColumns));
    }

    if ($hasForeignKey('bot_feed_history', 'fk_bot_feed_history_content')) {
        $database->exec('ALTER TABLE bot_feed_history DROP FOREIGN KEY fk_bot_feed_history_content');
    }
    if ($hasIndex('bot_feed_history', 'bot_feed_history_content_index')) {
        $database->exec('ALTER TABLE bot_feed_history DROP INDEX bot_feed_history_content_index');
    }

    $historyColumns = [];
    foreach (['content_id', 'item_guid', 'item_published_at'] as $column) {
        if ($hasColumn('bot_feed_history', $column)) {
            $historyColumns[] = 'DROP COLUMN ' . $column;
        }
    }
    if ($historyColumns !== []) {
        $database->exec('ALTER TABLE bot_feed_history ' . implode(', ', $historyColumns));
    }

    if (!$hasIndex('bot_source_runs', 'bot_source_runs_started_index')) {
        $database->exec('ALTER TABLE bot_source_runs ADD KEY bot_source_runs_started_index (started_at, id)');
    }

    $database->exec("DELETE FROM bot_source_runs WHERE status = 'current' AND started_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    $database->exec('DELETE FROM bot_source_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 14 DAY)');
    $database->exec(
        'DELETE FROM bot_feed_history
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
            AND NOT EXISTS (
                SELECT 1 FROM bot_sources
                WHERE bot_sources.bot_user_id = bot_feed_history.bot_user_id
                    AND bot_sources.feed_hash = bot_feed_history.feed_hash
            )'
    );

    $historyFeeds = $database->query(
        'SELECT DISTINCT bot_user_id, feed_hash
         FROM bot_feed_history
         ORDER BY bot_user_id ASC, feed_hash ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $pruneFeedHistory = $database->prepare(
        "DELETE FROM bot_feed_history
         WHERE bot_user_id = ? AND feed_hash = ?
            AND item_hash NOT IN (
                SELECT item_hash FROM (
                    SELECT item_hash
                    FROM bot_feed_history
                    WHERE bot_user_id = ? AND feed_hash = ?
                    ORDER BY created_at DESC, item_hash DESC
                    LIMIT 100
                ) retained_items
            )"
    );
    foreach ($historyFeeds as $historyFeed) {
        $botUserId = (int) ($historyFeed['bot_user_id'] ?? 0);
        $feedHash = (string) ($historyFeed['feed_hash'] ?? '');
        if ($botUserId > 0 && preg_match('/^[a-f0-9]{64}$/', $feedHash)) {
            $pruneFeedHistory->execute([$botUserId, $feedHash, $botUserId, $feedHash]);
        }
    }

    $sourceIds = $database->query('SELECT id FROM bot_sources ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $pruneCurrentRuns = $database->prepare(
        "DELETE FROM bot_source_runs
         WHERE source_id = ? AND status = 'current'
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id
                    FROM bot_source_runs
                    WHERE source_id = ? AND status = 'current'
                    ORDER BY started_at DESC, id DESC
                    LIMIT 250
                ) retained_runs
            )"
    );
    foreach ($sourceIds as $sourceId) {
        $pruneCurrentRuns->execute([(int) $sourceId, (int) $sourceId]);
    }
};
