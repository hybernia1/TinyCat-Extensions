<?php
declare(strict_types=1);

return static function (PDO $database): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS bot_sources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            feed_url VARCHAR(2048) NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            post_template VARCHAR(2000) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_checked_at DATETIME NULL,
            last_imported_at DATETIME NULL,
            next_run_at DATETIME NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bot_sources_feed_hash_unique (feed_hash),
            KEY bot_sources_due_index (enabled, next_run_at, id),
            KEY bot_sources_user_index (bot_user_id, id),
            CONSTRAINT fk_bot_sources_user FOREIGN KEY (bot_user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS bot_feed_history (
            bot_user_id INT UNSIGNED NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            item_hash CHAR(64) NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            item_guid VARCHAR(2048) NOT NULL,
            item_published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bot_user_id, feed_hash, item_hash),
            KEY bot_feed_history_content_index (content_id),
            KEY bot_feed_history_created_index (created_at),
            CONSTRAINT fk_bot_feed_history_user FOREIGN KEY (bot_user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_bot_feed_history_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS bot_source_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,
            started_at DATETIME NOT NULL,
            items_seen INT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(500) NULL,
            PRIMARY KEY (id),
            KEY bot_source_runs_source_index (source_id, started_at, id),
            KEY bot_source_runs_status_index (status, started_at),
            CONSTRAINT fk_bot_source_runs_source FOREIGN KEY (source_id) REFERENCES bot_sources (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $statement) {
        $database->exec($statement);
    }
};
