<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Bots
{
    private const FEED_HISTORY_KEEP = 100;
    private const CLEANUP_INTERVAL = 3600;
    private const CLEANUP_BATCH = 1000;
    private const CURRENT_RUN_RETENTION_DAYS = 2;
    private const RUN_RETENTION_DAYS = 14;
    private const ORPHAN_HISTORY_RETENTION_DAYS = 14;
    private const CURRENT_RUNS_PER_SOURCE_KEEP = 250;

    private static ?bool $compactFeedItems = null;
    private static ?bool $compactFeedHistory = null;

    private function __construct()
    {
    }

    public static function register(): void
    {
        UserRoles::register('bot', [
            'label' => 'users.roles.bot',
            'allows_login' => false,
            'receives_notifications' => false,
            'managed_by_core_admin' => false,
            'can_be_muted' => false,
            'appears_in_people_rankings' => false,
            'profile_schema_type' => 'Organization',
        ]);

        Registry::register('bots', [
            'root' => __DIR__,
            'views' => __DIR__ . '/Views',
            'translations' => __DIR__ . '/lang',
            'required_tables' => self::requiredTables(),
            'routes' => [self::class, 'registerRoutes'],
            'api_routes' => [self::class, 'registerApiRoutes'],
            'admin_navigation' => [self::class, 'adminNavigation'],
            'scheduled_tasks' => [
                'feeds' => [
                    'runner' => static fn (array $context): array => self::runScheduledTask($context),
                    'options' => ['bot_limit' => 20],
                    'admin' => [
                        'icon' => 'rss',
                        'title' => 'cron.tasks.feeds',
                        'help' => 'cron.tasks.feeds_help',
                        'schedule' => 'cron.tasks.feeds_schedule',
                    ],
                ],
            ],
        ]);
    }

    public static function requiredTables(): array
    {
        return [
            'bot_sources',
            'bot_feed_items',
            'bot_feed_history',
            'bot_source_runs',
        ];
    }

    public static function registerRoutes(): void
    {
        route('GET', '/admin/bots', static function (): void {
            $botId = max(0, (int) get('bot', 0));
            redirect($botId > 0
                ? '/admin/bots/list?' . http_build_query(['bot' => $botId])
                : '/admin/bots/accounts');
        });

        route('GET', '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
            $_GET['id'] = (string) max(0, (int) $bot_id);
            require Registry::file('bots', 'Controllers/detail.php');
        });

        route('GET', '/admin/bots/accounts', static function (): void {
            require Registry::file('bots', 'Controllers/accounts-page.php');
        });

        route('GET', '/admin/bots/list', static function (): void {
            require Registry::file('bots', 'Controllers/sources-page.php');
        });
    }

    public static function registerApiRoutes(): void
    {
        api_route('ANY', '/admin/bots', static function (): void {
            require Registry::file('bots', 'Controllers/sources-api.php');
        });

        api_route('POST', '/admin/bots/{action:run|toggle}', static function (string $action): void {
            $botAdminAction = $action;
            require Registry::file('bots', 'Controllers/sources-api.php');
        });

        api_route('ANY', '/admin/bot-accounts', static function (): void {
            require Registry::file('bots', 'Controllers/accounts-api.php');
        });
    }

    public static function adminNavigation(): array
    {
        return [
            'icon' => 'rss',
            'label' => t('bots.title'),
            'children' => [
                ['href' => '/admin/bots/accounts', 'icon' => 'users', 'label' => t('bots.accounts_title')],
                ['href' => '/admin/bots/list', 'icon' => 'list-unordered', 'label' => t('bots.sources_title')],
            ],
        ];
    }

    private static function runScheduledTask(array $context): array
    {
        $limit = max(1, min(100, (int) ($context['options']['bot_limit'] ?? 20)));
        $results = self::runDueSources($limit);
        $cleanup = self::cleanupDatabase();
        $summary = [];

        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            $summary[$status] = (int) ($summary[$status] ?? 0) + 1;
        }

        return [
            'ok' => true,
            'status' => 'completed',
            'limit' => $limit,
            'count' => count($results),
            'summary' => $summary,
            'results' => $results,
            'cleanup' => $cleanup,
        ];
    }

    private static function normalizeFeedUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $portNumber = isset($parts['port']) ? (int) $parts['port'] : 0;
        $isDefaultPort = ($scheme === 'http' && $portNumber === 80)
            || ($scheme === 'https' && $portNumber === 443);
        $port = $portNumber > 0 && !$isDefaultPort ? ':' . $portNumber : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public static function feedSourceHash(string $url): string
    {
        $url = self::normalizeFeedUrl($url);
        return $url !== '' ? hash('sha256', $url) : '';
    }

    public static function sourceDuplicateExists(string $feedUrl, int $excludeId = 0): bool
    {
        $feedHash = self::feedSourceHash($feedUrl);

        if ($feedHash === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM bot_sources WHERE feed_hash = ?';
        $params = [$feedHash];

        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        return (int) val($sql, $params) > 0;
    }

    public static function isDuplicateSourceException(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private static function feedItemHashes(int $sourceId, array $itemHashes): array
    {
        $itemHashes = array_values(array_unique(array_filter($itemHashes, static fn (mixed $hash): bool => preg_match('/^[a-f0-9]{64}$/', (string) $hash) === 1)));
        if ($sourceId < 1 || $itemHashes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemHashes), '?'));
        $rows = all(
            'SELECT item_hash FROM bot_feed_items WHERE source_id = ? AND item_hash IN (' . $placeholders . ')',
            array_merge([$sourceId], $itemHashes)
        );

        return array_fill_keys(array_column($rows, 'item_hash'), true);
    }

    private static function feedHistoryHashes(int $botUserId, string $feedHash, array $itemHashes): array
    {
        $itemHashes = array_values(array_unique(array_filter($itemHashes, static fn (mixed $hash): bool => preg_match('/^[a-f0-9]{64}$/', (string) $hash) === 1)));
        if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash) || $itemHashes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemHashes), '?'));
        $rows = all(
            'SELECT item_hash
             FROM bot_feed_history
             WHERE bot_user_id = ? AND feed_hash = ? AND item_hash IN (' . $placeholders . ')',
            array_merge([$botUserId, $feedHash], $itemHashes)
        );

        return array_fill_keys(array_column($rows, 'item_hash'), true);
    }

    private static function hasCompactFeedItems(): bool
    {
        return self::$compactFeedItems ??= (int) val(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['bot_feed_items', 'item_guid']
        ) === 0;
    }

    private static function hasCompactFeedHistory(): bool
    {
        return self::$compactFeedHistory ??= (int) val(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['bot_feed_history', 'item_guid']
        ) === 0;
    }

    private static function recordFeedHistory(
        int $botUserId,
        string $feedHash,
        string $itemHash,
        string $itemGuid = '',
        int $contentId = 0,
        string $publishedAt = '',
        string $createdAt = ''
    ): void
    {
        if ($botUserId < 1 || $feedHash === '' || $itemHash === '') {
            return;
        }

        try {
            $data = [
                'bot_user_id' => $botUserId,
                'feed_hash' => $feedHash,
                'item_hash' => $itemHash,
                'created_at' => $createdAt !== '' ? $createdAt : date_db(),
            ];
            if (!self::hasCompactFeedHistory()) {
                $data += [
                    'content_id' => $contentId > 0 ? $contentId : null,
                    'item_guid' => $itemGuid,
                    'item_published_at' => $publishedAt !== '' ? $publishedAt : null,
                ];
            }
            insert('bot_feed_history', $data);
        } catch (Throwable) {
            // The global history key is intentionally immutable and race-safe.
        }

        self::pruneFeedHistory($botUserId, $feedHash);
    }

    private static function pruneFeedHistory(int $botUserId, string $feedHash, int $keep = self::FEED_HISTORY_KEEP): void
    {
        if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash)) {
            return;
        }

        $keep = max(10, min(500, $keep));
        run(
            'DELETE FROM bot_feed_history
                WHERE bot_user_id = ? AND feed_hash = ?
                    AND item_hash NOT IN (
                        SELECT item_hash FROM (
                            SELECT item_hash
                            FROM bot_feed_history
                            WHERE bot_user_id = ? AND feed_hash = ?
                            ORDER BY created_at DESC, item_hash DESC
                            LIMIT ' . $keep . '
                        ) recent_items
                    )',
            [$botUserId, $feedHash, $botUserId, $feedHash]
        );
    }

    private static function cleanupDatabase(): array
    {
        $now = time();
        $lastRun = max(0, (int) setting('bots.cleanup_last_run', 0));
        if ($lastRun > $now - self::CLEANUP_INTERVAL) {
            return ['due' => false, 'changed' => 0, 'has_more' => false];
        }

        $batch = self::CLEANUP_BATCH;
        $currentBefore = date('Y-m-d H:i:s', $now - self::CURRENT_RUN_RETENTION_DAYS * 86400);
        $runsBefore = date('Y-m-d H:i:s', $now - self::RUN_RETENTION_DAYS * 86400);
        $historyBefore = date('Y-m-d H:i:s', $now - self::ORPHAN_HISTORY_RETENTION_DAYS * 86400);
        $results = [];

        try {
            $results['current_runs'] = run(
                'DELETE FROM bot_source_runs WHERE status = ? AND started_at < ? LIMIT ' . $batch,
                ['current', $currentBefore]
            );
            $results['old_runs'] = run(
                'DELETE FROM bot_source_runs WHERE started_at < ? LIMIT ' . $batch,
                [$runsBefore]
            );
            $results['orphan_history'] = run(
                'DELETE FROM bot_feed_history
                 WHERE created_at < ?
                    AND NOT EXISTS (
                        SELECT 1 FROM bot_sources
                        WHERE bot_sources.bot_user_id = bot_feed_history.bot_user_id
                            AND bot_sources.feed_hash = bot_feed_history.feed_hash
                    )
                 LIMIT ' . $batch,
                [$historyBefore]
            );
            $results['current_run_cap'] = self::pruneCurrentRunsPerSource($batch);
        } catch (Throwable $exception) {
            return [
                'due' => true,
                'changed' => array_sum($results),
                'has_more' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $changed = array_sum($results);
        $hasMore = array_any($results, static fn (int $count): bool => $count >= $batch);
        if (!$hasMore) {
            setting_set('bots.cleanup_last_run', $now, 'int', 'bots');
        }

        return [
            'due' => true,
            'changed' => $changed,
            'has_more' => $hasMore,
            'results' => $results,
        ];
    }

    private static function pruneCurrentRunsPerSource(int $batch): int
    {
        $changed = 0;
        $keep = self::CURRENT_RUNS_PER_SOURCE_KEEP;

        foreach (all('SELECT id FROM bot_sources ORDER BY id ASC') as $source) {
            $sourceId = (int) ($source['id'] ?? 0);
            if ($sourceId < 1 || $changed >= $batch) {
                break;
            }

            $remaining = max(1, $batch - $changed);
            $changed += run(
                'DELETE FROM bot_source_runs
                 WHERE source_id = ? AND status = ?
                    AND id NOT IN (
                        SELECT id FROM (
                            SELECT id
                            FROM bot_source_runs
                            WHERE source_id = ? AND status = ?
                            ORDER BY started_at DESC, id DESC
                            LIMIT ' . $keep . '
                        ) retained_runs
                    )
                 LIMIT ' . $remaining,
                [$sourceId, 'current', $sourceId, 'current']
            );
        }

        return $changed;
    }

    public static function defaultSourceTemplate(): string
    {
        return "{{title}}\n\n{{description}}\n\n{{url}}";
    }

    public static function findSource(int $id): ?array
    {
        return $id > 0
            ? one(
                'SELECT bs.*, u.username
                 FROM bot_sources bs
                 LEFT JOIN users u ON u.id = bs.bot_user_id AND u.role = ?
                 WHERE bs.id = ?
                 LIMIT 1',
                ['bot', $id]
            )
            : null;
    }

    public static function sources(?int $botUserId = null): array
    {
        $sql = 'SELECT bs.*, u.username FROM bot_sources bs INNER JOIN users u ON u.id = bs.bot_user_id WHERE u.role = ?';
        $params = ['bot'];

        if ($botUserId !== null && $botUserId > 0) {
            $sql .= ' AND bs.bot_user_id = ?';
            $params[] = $botUserId;
        }

        return all($sql . ' ORDER BY u.username ASC, bs.name ASC, bs.id ASC', $params);
    }

    public static function sourceResource(array $source): array
    {
        return [
            'id' => (int) ($source['id'] ?? 0),
            'bot_user_id' => (int) ($source['bot_user_id'] ?? 0),
            'bot_username' => (string) ($source['username'] ?? ''),
            'name' => (string) ($source['name'] ?? ''),
            'feed_url' => (string) ($source['feed_url'] ?? ''),
            'interval_minutes' => (int) ($source['interval_minutes'] ?? 60),
            'post_template' => (string) ($source['post_template'] ?? ''),
            'enabled' => (bool) ($source['enabled'] ?? false),
            'last_checked_at' => (string) ($source['last_checked_at'] ?? ''),
            'last_imported_at' => (string) ($source['last_imported_at'] ?? ''),
            'next_run_at' => (string) ($source['next_run_at'] ?? ''),
            'last_error' => (string) ($source['last_error'] ?? ''),
        ];
    }

    private static function createSourceRun(int $sourceId, int $botUserId): int
    {
        if ($sourceId < 1 || $botUserId < 1) {
            return 0;
        }

        try {
            return (int) insert('bot_source_runs', [
                'source_id' => $sourceId,
                'bot_user_id' => $botUserId,
                'status' => 'running',
                'started_at' => date_db(),
            ]);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function finishSourceRun(int $runId, string $status, int $itemsSeen = 0, int $itemsImported = 0, int $contentId = 0, ?int $httpStatus = null, string $error = ''): void
    {
        if ($runId < 1) {
            return;
        }

        try {
            update('bot_source_runs', [
                'status' => $status,
                'finished_at' => date_db(),
                'items_seen' => max(0, $itemsSeen),
                'items_imported' => max(0, $itemsImported),
                'content_id' => $contentId > 0 ? $contentId : null,
                'http_status' => $httpStatus !== null && $httpStatus > 0 ? $httpStatus : null,
                'error' => $error !== '' ? self::feedText($error, 500) : null,
            ], ['id' => $runId]);
        } catch (Throwable) {
            // Run history is diagnostic and must never break the import.
        }
    }

    public static function deleteSource(int $sourceId): void
    {
        if ($sourceId < 1) {
            return;
        }

        db_transaction(static function () use ($sourceId): void {
            delete('bot_source_runs', ['source_id' => $sourceId]);
            delete('bot_feed_items', ['source_id' => $sourceId]);
            delete('bot_sources', ['id' => $sourceId]);
        });
    }

    private static function parseFeed(string $xml): array
    {
        if ($xml === '' || !function_exists('simplexml_load_string')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$feed instanceof SimpleXMLElement) {
            return [];
        }

        $nodes = isset($feed->channel->item) ? $feed->channel->item : $feed->entry;
        $items = [];

        foreach ($nodes as $node) {
            $namespaces = $node->getNamespaces(true);
            $link = trim((string) $node->link);

            if ($node->getName() === 'entry') {
                foreach ($node->link as $linkNode) {
                    $attributes = $linkNode->attributes();
                    $rel = strtolower((string) ($attributes['rel'] ?? 'alternate'));

                    if ($rel === '' || $rel === 'alternate') {
                        $link = trim((string) ($attributes['href'] ?? $linkNode));
                        break;
                    }
                }
            }

            $creator = '';
            if (isset($namespaces['dc'])) {
                $creator = trim((string) $node->children($namespaces['dc'])->creator);
            }
            if ($creator === '') {
                $creator = trim((string) ($node->author->name ?? $node->author));
            }

            $categories = [];
            foreach ($node->category as $category) {
                $value = trim((string) ($category['term'] ?? $category));
                if ($value !== '') {
                    $categories[] = $value;
                }
            }

            $title = self::feedText((string) $node->title, 500);
            $descriptionSource = (string) ($node->description ?: $node->summary ?: $node->content);
            $description = self::feedDescriptionText($descriptionSource, 1200);
            $guid = trim((string) ($node->guid ?: $node->id ?: $link));
            $published = trim((string) ($node->pubDate ?: $node->published ?: $node->updated));

            if ($guid === '' || ($title === '' && $link === '')) {
                continue;
            }

            $timestamp = $published !== '' ? strtotime($published) : false;
            $items[] = [
                'guid' => $guid,
                'title' => $title,
                'description' => $description,
                'url' => LinkMetadata::isSafeRemoteUrl($link) ? $link : '',
                'image_url' => self::feedImageUrl($node, $namespaces, $descriptionSource),
                'author' => self::feedText($creator, 200),
                'categories' => array_values(array_unique($categories)),
                'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null,
                '_timestamp' => $timestamp !== false ? $timestamp : 0,
            ];

            if (count($items) >= 100) {
                break;
            }
        }

        usort($items, static fn (array $a, array $b): int => ((int) $a['_timestamp']) <=> ((int) $b['_timestamp']));
        return $items;
    }

    private static function feedText(string $value, int $limit): string
    {
        $value = strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function feedDescriptionText(string $value, int $limit): string
    {
        $value = preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a\s*>~is',
            static function (array $match): string {
                $label = (string) ($match[2] ?? '');
                $videoUrl = self::feedHtmlVideoUrl((string) ($match[1] ?? ''), 'href');
                if ($videoUrl === '') {
                    return $label;
                }

                $labelText = strip_html_tags_preserving_text(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                foreach (StatusLinks::extract($labelText) as $labelLink) {
                    if ((string) ($labelLink['normalized_url'] ?? '') === $videoUrl) {
                        return $label;
                    }
                }

                return $label . ' ' . $videoUrl;
            },
            $value
        ) ?? $value;
        $value = preg_replace_callback(
            '~<(?:iframe|embed)\b([^>]*)>(?:\s*</iframe\s*>)?~is',
            static fn (array $match): string => self::feedHtmlVideoUrl((string) ($match[1] ?? ''), 'src'),
            $value
        ) ?? $value;
        $value = strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace_callback(
            StatusLinks::pattern(),
            static function (array $match): string {
                [$url, $tail] = StatusLinks::splitTail((string) ($match[0] ?? ''));
                $videoUrl = self::supportedVideoUrl($url);
                return ($videoUrl !== '' ? $videoUrl : '') . $tail;
            },
            $value
        ) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = preg_replace('/\s+([.,;:!?])/u', '$1', $value) ?? $value;
        $value = preg_replace('/(?:^|\s)The post\b.*?\bappeared first on\s*[.!?]*\s*$/iu', '', $value) ?? $value;
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function feedHtmlVideoUrl(string $attributes, string $name): string
    {
        $name = preg_quote($name, '~');
        if (preg_match('~\b' . $name . '\s*=\s*(["\'])(.*?)\1~is', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode((string) ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('~\b' . $name . '\s*=\s*([^\s>]+)~i', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode(trim((string) ($match[1] ?? ''), "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private static function supportedVideoUrl(string $url): string
    {
        $link = StatusLinks::fromRaw(trim($url));
        return ($link['link_type'] ?? '') === 'video' ? (string) ($link['normalized_url'] ?? '') : '';
    }

    private static function feedImageUrl(SimpleXMLElement $node, array $namespaces, string $description = ''): string
    {
        $candidates = [];

        if (isset($namespaces['media'])) {
            $media = $node->children((string) $namespaces['media']);
            foreach (['content', 'thumbnail'] as $element) {
                foreach ($media->{$element} as $image) {
                    $candidates[] = (string) ($image->attributes()['url'] ?? '');
                }
            }
        }

        foreach ($node->enclosure as $enclosure) {
            $attributes = $enclosure->attributes();
            $type = strtolower((string) ($attributes['type'] ?? ''));
            if ($type === '' || str_starts_with($type, 'image/')) {
                $candidates[] = (string) ($attributes['url'] ?? '');
            }
        }

        if (preg_match('~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~is', $description, $match) === 1) {
            $candidates[] = (string) ($match[2] ?? '');
        } elseif (preg_match('~<img\b[^>]*\bsrc\s*=\s*([^\s>]+)~is', $description, $match) === 1) {
            $candidates[] = trim((string) ($match[1] ?? ''), "\"'");
        }

        foreach ($candidates as $candidate) {
            $url = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strlen($url) <= 2048 && LinkMetadata::isSafeRemoteUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    private static function renderPost(array $source, array $item): string
    {
        $values = [
            '{{title}}' => (string) ($item['title'] ?? ''),
            '{{description}}' => (string) ($item['description'] ?? ''),
            '{{url}}' => (string) ($item['url'] ?? ''),
            '{{author}}' => (string) ($item['author'] ?? ''),
            '{{source}}' => (string) ($source['name'] ?? ''),
            '{{categories}}' => implode(', ', (array) ($item['categories'] ?? [])),
            '#{categories}' => self::feedCategoryTags((array) ($item['categories'] ?? [])),
        ];
        $body = trim(preg_replace("/\n{3,}/", "\n\n", strtr((string) ($source['post_template'] ?? self::defaultSourceTemplate()), $values)) ?? '');
        return function_exists('mb_substr') ? mb_substr($body, 0, 2000, 'UTF-8') : substr($body, 0, 2000);
    }

    private static function feedCategoryTags(array $categories): string
    {
        $tags = [];

        foreach ($categories as $category) {
            $tag = status_tag_normalize((string) $category);
            if ($tag !== '') {
                $tags[$tag] = '#' . $tag;
            }

            if (count($tags) >= status_tag_max_count()) {
                break;
            }
        }

        return implode(' ', array_values($tags));
    }

    private static function runDueSources(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $sources = all(
            'SELECT bs.*, u.username, u.status AS user_status
                FROM bot_sources bs
                INNER JOIN users u ON u.id = bs.bot_user_id
                WHERE bs.enabled = 1 AND u.role = ? AND u.status = ?
                    AND (bs.next_run_at IS NULL OR bs.next_run_at <= ?)
                ORDER BY COALESCE(bs.next_run_at, bs.created_at) ASC, bs.id ASC
                LIMIT ' . $limit,
            ['bot', 'active', date_db()]
        );
        $results = [];

        foreach ($sources as $source) {
            $results[] = self::runSource($source);
        }

        return $results;
    }

    public static function runSource(array $source, bool $force = false): array
    {
        $sourceId = (int) ($source['id'] ?? 0);
        $botUserId = (int) ($source['bot_user_id'] ?? 0);
        $feedUrl = (string) ($source['feed_url'] ?? '');
        $interval = max(5, min(43200, (int) ($source['interval_minutes'] ?? 60)));
        $now = date_db();
        $next = date('Y-m-d H:i:s', time() + $interval * 60);
        $claimed = $force
            ? run(
                'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1',
                [$next, $now, $sourceId]
            )
            : run(
                'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?)',
                [$next, $now, $sourceId, $now]
            );

        if ($sourceId < 1 || $claimed < 1) {
            return ['source_id' => $sourceId, 'status' => 'skipped'];
        }

        $runId = self::createSourceRun($sourceId, $botUserId);
        $itemsSeen = 0;
        $httpStatus = null;

        try {
            $response = LinkMetadata::fetchDocument((string) ($source['feed_url'] ?? ''));
            if ($response === null) {
                throw new RuntimeException('RSS feed could not be downloaded.');
            }

            $httpStatus = (int) ($response['status'] ?? 0);
            $items = self::parseFeed((string) ($response['body'] ?? ''));
            $itemsSeen = count($items);
            if ($items === []) {
                throw new RuntimeException('RSS feed contains no usable items.');
            }

            $feedHash = self::feedSourceHash($feedUrl);
            $itemHashes = array_map(static fn (array $item): string => hash('sha256', (string) ($item['guid'] ?? '')), $items);
            $sourceItemHashes = self::feedItemHashes($sourceId, $itemHashes);
            $historyItemHashes = self::feedHistoryHashes($botUserId, $feedHash, $itemHashes);

            foreach ($items as $item) {
                $itemGuid = (string) ($item['guid'] ?? '');
                $hash = hash('sha256', $itemGuid);
                if (isset($sourceItemHashes[$hash]) || isset($historyItemHashes[$hash])) {
                    continue;
                }

                $body = self::renderPost($source, $item);
                if ($body === '') {
                    throw new RuntimeException('Post template produced an empty post.');
                }

                $publishedAt = date_db();
                $contentId = (int) insert('content', [
                    'body' => $body,
                    'author_id' => $botUserId,
                    'published_at' => $publishedAt,
                    'created_at' => $publishedAt,
                ]);
                status_sync_tags($contentId, status_tags_from_text($body));
                $feedLink = StatusLinks::fromRaw((string) ($item['url'] ?? ''));
                status_sync_links(
                    $contentId,
                    status_links_from_text($body),
                    (string) ($feedLink['url_hash'] ?? ''),
                    (string) ($item['image_url'] ?? '')
                );
                $feedItem = [
                    'source_id' => $sourceId,
                    'item_hash' => $hash,
                ];
                if (!self::hasCompactFeedItems()) {
                    $feedItem += [
                        'content_id' => $contentId,
                        'item_guid' => $itemGuid,
                        'item_published_at' => $item['published_at'] ?? null,
                        'created_at' => $publishedAt,
                    ];
                }
                insert('bot_feed_items', $feedItem);
                self::recordFeedHistory(
                    $botUserId,
                    $feedHash,
                    $hash,
                    $itemGuid,
                    $contentId,
                    (string) ($item['published_at'] ?? ''),
                    $publishedAt
                );
                $sourceItemHashes[$hash] = true;
                $historyItemHashes[$hash] = true;
                update('bot_sources', ['last_imported_at' => $publishedAt], ['id' => $sourceId]);
                self::finishSourceRun($runId, 'posted', $itemsSeen, 1, $contentId, $httpStatus);

                return ['source_id' => $sourceId, 'status' => 'posted', 'content_id' => $contentId];
            }

            self::finishSourceRun($runId, 'current', $itemsSeen, 0, 0, $httpStatus);
            return ['source_id' => $sourceId, 'status' => 'current'];
        } catch (Throwable $exception) {
            $error = self::feedText($exception->getMessage(), 500);
            update('bot_sources', ['last_error' => $error], ['id' => $sourceId]);
            self::finishSourceRun($runId, 'error', $itemsSeen, 0, 0, $httpStatus, $error);
            return ['source_id' => $sourceId, 'status' => 'error', 'error' => $error];
        }
    }
}
