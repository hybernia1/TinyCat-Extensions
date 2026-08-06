<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CustomPagesAdmin
{
    private function __construct()
    {
    }

    public static function registerRoutes(): void
    {
        route('GET', '/admin/custom-pages', static function (): void {
            require Registry::file('custom_pages', 'Controllers/admin-list.php');
        });
        route(['GET', 'POST'], '/admin/custom-pages/new', static function (): void {
            $customPageId = 0;
            require Registry::file('custom_pages', 'Controllers/admin-form.php');
        });
        route(['GET', 'POST'], '/admin/custom-pages/{page_id:[1-9][0-9]*}', static function (string $page_id): void {
            $customPageId = max(1, (int) $page_id);
            require Registry::file('custom_pages', 'Controllers/admin-form.php');
        });
        route('POST', '/admin/custom-pages/{page_id:[1-9][0-9]*}/delete', static function (string $page_id): void {
            require_admin();
            csrf_require();
            $page = CustomPages::byId(max(1, (int) $page_id));
            if ($page === null) {
                self::notFound();
                return;
            }
            delete('custom_pages', ['id' => (int) $page['id']]);
            flash('success', t('custom_pages.messages.deleted'));
            redirect('/admin/custom-pages');
        });
    }

    /** @return array{values: array<string, string>, errors: array<string, string>} */
    public static function save(?array $page): array
    {
        $title = plain_text_limit(trim((string) post('title', '')), 180);
        $requestedSlug = trim((string) post('slug', ''));
        $slug = CustomPages::normalizeSlug($requestedSlug !== '' ? $requestedSlug : $title);
        $bodyInput = str_replace(["\r\n", "\r"], "\n", (string) post('body_html', ''));
        $body = sanitize_html($bodyInput);
        $status = (string) post('status', 'draft');
        $values = ['title' => $title, 'slug' => $slug, 'body_html' => $body, 'status' => $status];
        $errors = [];

        if ($title === '') $errors['title'] = t('custom_pages.validation.title_required');
        if ($slug === '') $errors['slug'] = t('custom_pages.validation.slug_invalid');
        if (strlen($bodyInput) > 200000) $errors['body_html'] = t('custom_pages.validation.body_too_long');
        if (!in_array($status, ['draft', 'published'], true)) $errors['status'] = t('custom_pages.validation.status_invalid');
        if ($page !== null && !empty($page['published_at']) && $slug !== (string) $page['slug']) {
            $errors['slug'] = t('custom_pages.validation.slug_locked');
        }
        if ($slug !== '') {
            $duplicate = one('SELECT id FROM custom_pages WHERE slug = ? LIMIT 1', [$slug]);
            if ($duplicate !== null && (int) $duplicate['id'] !== (int) ($page['id'] ?? 0)) {
                $errors['slug'] = t('custom_pages.validation.slug_taken');
            }
        }
        if ($errors !== []) return ['values' => $values, 'errors' => $errors];

        $payload = ['title' => $title, 'slug' => $slug, 'body_html' => $body, 'status' => $status];
        if ($page === null) {
            $payload['published_at'] = $status === 'published' ? date_db() : null;
            insert('custom_pages', $payload);
            flash('success', t('custom_pages.messages.created'));
        } else {
            if ($status === 'published' && empty($page['published_at'])) $payload['published_at'] = date_db();
            update('custom_pages', $payload, ['id' => (int) $page['id']]);
            flash('success', t('custom_pages.messages.saved'));
        }

        redirect('/admin/custom-pages');
    }

    /** @return array<string, string> */
    public static function values(?array $page): array
    {
        return [
            'title' => (string) ($page['title'] ?? ''),
            'slug' => (string) ($page['slug'] ?? ''),
            'body_html' => (string) ($page['body_html'] ?? ''),
            'status' => (string) ($page['status'] ?? 'draft'),
        ];
    }

    public static function notFound(): void
    {
        http_response_code(404);
        echo e(t('custom_pages.messages.not_found'));
    }
}
