<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = CustomPages::publishedBySlug($customPageSlug);
if ($page === null) {
    http_response_code(404);
    layout('layout', ['title' => t('custom_pages.messages.not_found')], static function (): void {
        ?>
        <section class="card"><div class="card-body"><p class="mb-0"><?= et('custom_pages.messages.not_found') ?></p></div></section>
        <?php
    });
    return;
}

layout('layout', [
    'title' => (string) $page['title'],
    'current' => '/page/' . (string) $page['slug'],
], static function () use ($page): void {
    ?>
    <article class="card">
        <div class="card-body stack stack-gap-16">
            <header class="stack stack-gap-4">
                <h1 class="text-xl m-0"><?= e((string) $page['title']) ?></h1>
                <?php if (!empty($page['published_at'])): ?>
                    <time class="text-muted text-sm" datetime="<?= e(date_iso((string) $page['published_at'])) ?>"><?= e(datetime((string) $page['published_at'])) ?></time>
                <?php endif; ?>
            </header>
            <div class="custom-page-content">
                <?= sanitize_html((string) $page['body_html']) ?>
            </div>
        </div>
    </article>
    <?php
});
