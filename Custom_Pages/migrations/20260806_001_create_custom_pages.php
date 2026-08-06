<?php
declare(strict_types=1);

return static function (PDO $database): void {
    $database->exec(
        "CREATE TABLE IF NOT EXISTS custom_pages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(80) NOT NULL,
            title VARCHAR(180) NOT NULL,
            body_markdown MEDIUMTEXT NOT NULL,
            status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY custom_pages_slug_unique (slug),
            KEY custom_pages_public_index (status, updated_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
