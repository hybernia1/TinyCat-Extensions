<?php
declare(strict_types=1);

return static function (PDO $database): void {
    $columns = $database->query('SHOW COLUMNS FROM custom_pages')->fetchAll(PDO::FETCH_COLUMN);
    $hasMarkdown = in_array('body_markdown', $columns, true);
    $hasHtml = in_array('body_html', $columns, true);

    if (!$hasHtml) {
        $database->exec(
            'ALTER TABLE custom_pages ADD COLUMN body_html MEDIUMTEXT NOT NULL'
            . ($hasMarkdown ? ' AFTER body_markdown' : '')
        );
        $hasHtml = true;
    }

    if ($hasMarkdown) {
        $select = $database->query('SELECT id, body_markdown FROM custom_pages');
        $update = $database->prepare('UPDATE custom_pages SET body_html = ? WHERE id = ?');

        while ($page = $select->fetch(PDO::FETCH_ASSOC)) {
            $source = str_replace(["\r\n", "\r"], "\n", (string) ($page['body_markdown'] ?? ''));
            $paragraphs = preg_split('/\n{2,}/', trim($source)) ?: [];
            $html = [];

            foreach ($paragraphs as $paragraph) {
                $lines = preg_split('/\n/', $paragraph) ?: [];
                $html[] = '<p>' . implode('<br>', array_map(
                    static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $lines
                )) . '</p>';
            }

            $update->execute([implode("\n", $html), (int) ($page['id'] ?? 0)]);
        }

        $database->exec('ALTER TABLE custom_pages DROP COLUMN body_markdown');
    }
};
