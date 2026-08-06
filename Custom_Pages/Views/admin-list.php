<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$pages = is_array($pages ?? null) ? $pages : [];
?>
<?php if ($pages === []): ?>
    <div class="alert alert-info mb-0"><?= et('custom_pages.empty') ?></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?= et('custom_pages.page') ?></th>
                    <th><?= et('custom_pages.url') ?></th>
                    <th><?= et('common.status') ?></th>
                    <th><?= et('common.updated') ?></th>
                    <th><?= et('common.actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $page): ?>
                    <?php $id = (int) ($page['id'] ?? 0); ?>
                    <tr>
                        <td><strong><?= e((string) ($page['title'] ?? '')) ?></strong></td>
                        <td><code>/page/<?= e((string) ($page['slug'] ?? '')) ?></code></td>
                        <td><span class="badge<?= ($page['status'] ?? '') === 'published' ? ' badge-primary' : '' ?>"><?= et('custom_pages.status.' . (string) ($page['status'] ?? 'draft')) ?></span></td>
                        <td><time class="table-meta" datetime="<?= e(date_iso((string) ($page['updated_at'] ?? ''))) ?>"><?= e(datetime((string) ($page['updated_at'] ?? ''))) ?></time></td>
                        <td><div class="table-actions">
                            <?php if (($page['status'] ?? '') === 'published'): ?>
                                <a class="btn btn-sm btn-ghost btn-icon" href="/page/<?= e((string) ($page['slug'] ?? '')) ?>" target="_blank" rel="noopener" title="<?= et('custom_pages.view_page') ?>" aria-label="<?= et('custom_pages.view_page') ?>"><?= icon('external-link') ?></a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-ghost btn-icon" href="/admin/custom-pages/<?= e($id) ?>" title="<?= et('common.edit') ?>" aria-label="<?= et('common.edit') ?>"><?= icon('edit') ?></a>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
