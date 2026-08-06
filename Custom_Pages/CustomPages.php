<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CustomPages
{
    private function __construct()
    {
    }

    public static function register(): void
    {
        Registry::register('custom_pages', [
            'root' => __DIR__,
            'views' => __DIR__ . '/Views',
            'translations' => __DIR__ . '/lang',
            'required_tables' => ['custom_pages'],
            'routes' => [self::class, 'registerRoutes'],
            'admin_navigation' => [self::class, 'adminNavigation'],
            'sitemap' => [
                'count' => [self::class, 'publishedCount'],
                'entries' => [self::class, 'sitemapEntries'],
            ],
        ]);
    }

    public static function registerRoutes(): void
    {
        route('GET', '/page/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', static function (string $slug): void {
            $customPageSlug = $slug;
            require Registry::file('custom_pages', 'Controllers/public-page.php');
        });

        CustomPagesAdmin::registerRoutes();
    }

    public static function adminNavigation(): array
    {
        return [
            'href' => '/admin/custom-pages',
            'icon' => 'file-text',
            'label' => t('custom_pages.title'),
        ];
    }

    public static function publishedCount(): int
    {
        return max(0, (int) val("SELECT COUNT(*) FROM custom_pages WHERE status = 'published'"));
    }

    /** @return list<array{url: string, last_modified: string}> */
    public static function sitemapEntries(int $limit, int $offset): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $pages = all(
            "SELECT slug, updated_at
             FROM custom_pages
             WHERE status = 'published'
             ORDER BY updated_at DESC, id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );

        return array_map(static fn (array $page): array => [
            'url' => '/page/' . (string) ($page['slug'] ?? ''),
            'last_modified' => (string) ($page['updated_at'] ?? ''),
        ], $pages);
    }

    public static function publishedBySlug(string $slug): ?array
    {
        return one(
            "SELECT id, slug, title, body_markdown, published_at, updated_at
             FROM custom_pages
             WHERE slug = ? AND status = 'published'
             LIMIT 1",
            [$slug]
        );
    }

    public static function byId(int $id): ?array
    {
        return one('SELECT * FROM custom_pages WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        return all(
            "SELECT id, slug, title, status, published_at, updated_at
             FROM custom_pages
             ORDER BY CASE WHEN status = 'published' THEN 0 ELSE 1 END, updated_at DESC, id DESC"
        );
    }

    public static function normalizeSlug(string $value): string
    {
        $value = trim(slug($value));
        $value = trim(substr($value, 0, 80), '-');

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1 ? $value : '';
    }

    public static function renderMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $markdown)) ?: [];
        $html = [];
        $code = null;
        $listType = '';

        $closeList = static function () use (&$html, &$listType): void {
            if ($listType !== '') {
                $html[] = '</' . $listType . '>';
                $listType = '';
            }
        };

        foreach ($lines as $line) {
            if (is_array($code)) {
                if (trim($line) === '```') {
                    $html[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
                    $code = null;
                } else {
                    $code[] = $line;
                }
                continue;
            }

            if (trim($line) === '```') {
                $closeList();
                $code = [];
                continue;
            }
            if (trim($line) === '') {
                $closeList();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $match) === 1) {
                $closeList();
                $level = min(6, strlen($match[1]) + 1);
                $html[] = '<h' . $level . '>' . self::renderInline($match[2]) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^[-*+]\s+(.+)$/u', $line, $match) === 1) {
                if ($listType !== 'ul') {
                    $closeList();
                    $listType = 'ul';
                    $html[] = '<ul>';
                }
                $html[] = '<li>' . self::renderInline($match[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $line, $match) === 1) {
                if ($listType !== 'ol') {
                    $closeList();
                    $listType = 'ol';
                    $html[] = '<ol>';
                }
                $html[] = '<li>' . self::renderInline($match[1]) . '</li>';
                continue;
            }

            $closeList();
            if (preg_match('/^>\s?(.+)$/u', $line, $match) === 1) {
                $html[] = '<blockquote><p>' . self::renderInline($match[1]) . '</p></blockquote>';
            } elseif (preg_match('/^[-*_]{3,}$/', trim($line)) === 1) {
                $html[] = '<hr>';
            } else {
                $html[] = '<p>' . self::renderInline($line) . '</p>';
            }
        }

        if (is_array($code)) {
            $html[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
        }
        $closeList();

        return implode("\n", $html);
    }

    private static function renderInline(string $text): string
    {
        $text = e($text);
        $text = preg_replace('~`([^`]+)`~u', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('~\*\*(.+?)\*\*~u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('~(?<!\*)\*([^*\n]+)\*(?!\*)~u', '<em>$1</em>', $text) ?? $text;

        return preg_replace_callback(
            '~\[([^\]]+)\]\(([^)\s]+)\)~u',
            static function (array $match): string {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $isInternal = str_starts_with($url, '/') && !str_starts_with($url, '//');
                $parts = parse_url($url);
                $isExternal = is_array($parts)
                    && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                    && (string) ($parts['host'] ?? '') !== '';

                if (!$isInternal && !$isExternal) {
                    return $match[1];
                }

                return '<a href="' . e($url) . '"'
                    . ($isExternal ? ' target="_blank" rel="noopener noreferrer"' : '')
                    . '>' . $match[1] . '</a>';
            },
            $text
        ) ?? $text;
    }
}
