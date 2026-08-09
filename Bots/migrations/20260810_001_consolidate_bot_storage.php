<?php
declare(strict_types=1);

return static function (PDO $database): void {
    $hasTable = static function (string $table) use ($database): bool {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    };
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
    $hasForeignKey = static function (string $table, string $foreignKey) use ($database): bool {
        $statement = $database->prepare(
            'SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = ?'
        );
        $statement->execute([$table, $foreignKey, 'FOREIGN KEY']);

        return (int) $statement->fetchColumn() > 0;
    };

    if ($hasTable('bot_feed_items')) {
        $database->exec(
            'INSERT IGNORE INTO bot_feed_history (bot_user_id, feed_hash, item_hash, created_at)
             SELECT bot_sources.bot_user_id, bot_sources.feed_hash, bot_feed_items.item_hash, NOW()
             FROM bot_feed_items
             INNER JOIN bot_sources ON bot_sources.id = bot_feed_items.source_id'
        );
        $database->exec('DROP TABLE bot_feed_items');
    }

    $pruneHistory = $database->prepare(
        'DELETE FROM bot_feed_history
         WHERE bot_user_id = ? AND feed_hash = ?
            AND item_hash NOT IN (
                SELECT item_hash FROM (
                    SELECT item_hash
                    FROM bot_feed_history
                    WHERE bot_user_id = ? AND feed_hash = ?
                    ORDER BY created_at DESC, item_hash DESC
                    LIMIT 500
                ) retained_items
            )'
    );
    foreach ($database->query('SELECT DISTINCT bot_user_id, feed_hash FROM bot_feed_history')->fetchAll(PDO::FETCH_ASSOC) as $history) {
        $pruneHistory->execute([
            (int) $history['bot_user_id'],
            (string) $history['feed_hash'],
            (int) $history['bot_user_id'],
            (string) $history['feed_hash'],
        ]);
    }

    if ($hasForeignKey('bot_source_runs', 'fk_bot_source_runs_user')) {
        $database->exec('ALTER TABLE bot_source_runs DROP FOREIGN KEY fk_bot_source_runs_user');
    }
    if ($hasForeignKey('bot_source_runs', 'fk_bot_source_runs_content')) {
        $database->exec('ALTER TABLE bot_source_runs DROP FOREIGN KEY fk_bot_source_runs_content');
    }
    if ($hasIndex('bot_source_runs', 'bot_source_runs_bot_index')) {
        $database->exec('ALTER TABLE bot_source_runs DROP INDEX bot_source_runs_bot_index');
    }
    $runColumns = [];
    foreach (['bot_user_id', 'finished_at', 'items_imported', 'content_id', 'http_status', 'created_at'] as $column) {
        if ($hasColumn('bot_source_runs', $column)) {
            $runColumns[] = 'DROP COLUMN ' . $column;
        }
    }
    if ($runColumns !== []) {
        $database->exec('ALTER TABLE bot_source_runs ' . implode(', ', $runColumns));
    }
    if ($hasColumn('bot_sources', 'updated_at')) {
        $database->exec('ALTER TABLE bot_sources DROP COLUMN updated_at');
    }

    $database->exec("DELETE FROM bot_source_runs WHERE status = 'error' AND started_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
    $pruneRuns = $database->prepare(
        "DELETE FROM bot_source_runs
         WHERE source_id = ? AND status <> 'error'
            AND id NOT IN (
                SELECT id FROM (
                    SELECT id
                    FROM bot_source_runs
                    WHERE source_id = ? AND status <> 'error'
                    ORDER BY started_at DESC, id DESC
                    LIMIT 50
                ) retained_runs
            )"
    );
    foreach ($database->query('SELECT id FROM bot_sources ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) as $sourceId) {
        $pruneRuns->execute([(int) $sourceId, (int) $sourceId]);
    }
};
