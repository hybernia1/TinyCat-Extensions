<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$page = is_array($page ?? null) ? $page : null;
$editing = (bool) ($editing ?? false);
$slugLocked = !empty($page['published_at']);
?>
<form class="stack stack-gap-16" method="post" action="<?= e($editing ? '/admin/custom-pages/' . (int) $page['id'] : '/admin/custom-pages/new') ?>">
    <?= csrf_field() ?>
    <div class="field">
        <label class="label" for="custom-page-title"><?= et('custom_pages.fields.title') ?></label>
        <input class="input" id="custom-page-title" name="title" maxlength="180" required value="<?= e((string) ($values['title'] ?? '')) ?>">
        <?php if (!empty($errors['title'])): ?><p class="field-error"><?= e((string) $errors['title']) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label class="label" for="custom-page-slug"><?= et('custom_pages.fields.slug') ?></label>
        <span class="text-muted">/page/</span><input class="input" id="custom-page-slug" name="slug" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*"<?= $slugLocked ? ' readonly' : '' ?> value="<?= e((string) ($values['slug'] ?? '')) ?>">
        <p class="help"><?= et($slugLocked ? 'custom_pages.slug_locked_help' : 'custom_pages.slug_help') ?></p>
        <?php if (!empty($errors['slug'])): ?><p class="field-error"><?= e((string) $errors['slug']) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label class="label" for="custom-page-body"><?= et('custom_pages.fields.body') ?></label>
        <textarea class="textarea" id="custom-page-body" name="body_markdown" rows="18" maxlength="200000" spellcheck="true"><?= e((string) ($values['body_markdown'] ?? '')) ?></textarea>
        <p class="help"><?= et('custom_pages.markdown_help') ?></p>
        <?php if (!empty($errors['body_markdown'])): ?><p class="field-error"><?= e((string) $errors['body_markdown']) ?></p><?php endif; ?>
    </div>
    <div class="field">
        <label class="label" for="custom-page-status"><?= et('common.status') ?></label>
        <select class="select" id="custom-page-status" name="status">
            <option value="draft"<?= ($values['status'] ?? 'draft') === 'draft' ? ' selected' : '' ?>><?= et('custom_pages.status.draft') ?></option>
            <option value="published"<?= ($values['status'] ?? '') === 'published' ? ' selected' : '' ?>><?= et('custom_pages.status.published') ?></option>
        </select>
        <?php if (!empty($errors['status'])): ?><p class="field-error"><?= e((string) $errors['status']) ?></p><?php endif; ?>
    </div>
    <div class="cluster gap-2 justify-end">
        <a class="btn btn-secondary" href="/admin/custom-pages"><?= et('common.cancel') ?></a>
        <button class="btn btn-primary" type="submit"><?= icon('check') ?> <span><?= et('common.save') ?></span></button>
    </div>
</form>
<?php if ($editing): ?>
    <form class="stack stack-gap-8" method="post" action="/admin/custom-pages/<?= e((int) $page['id']) ?>/delete" data-confirm="<?= et('custom_pages.delete_confirm', ['title' => (string) $page['title']]) ?>" data-confirm-title="<?= et('custom_pages.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
        <?= csrf_field() ?>
        <button class="btn btn-danger" type="submit"><?= icon('trash') ?> <span><?= et('common.delete') ?></span></button>
    </form>
<?php endif; ?>
