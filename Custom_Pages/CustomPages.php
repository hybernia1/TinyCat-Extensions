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
            'assets' => static fn (string $path): array => str_starts_with($path, '/admin/custom-pages')
                ? ['scripts' => ['assets/html-editor.js']]
                : [],
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
            'icon' => 'file',
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
            "SELECT id, slug, title, body_html, published_at, updated_at
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

}
