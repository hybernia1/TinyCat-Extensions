<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$source = is_array($source ?? null) ? $source : [];
$bots = is_array($bots ?? null) ? $bots : [];
$create = (bool) ($create ?? false);
$id = (int) ($source['id'] ?? 0);

ob_start();
?>
<?php if (!$create): ?><input type="hidden" name="id" value="<?= e($id) ?>"><?php endif; ?>
<div class="stack">
    <div class="grid sm:grid-2">
        <label class="field"><span class="label"><?= et('bots.bot') ?></span><select class="select" name="bot_user_id" required><?php foreach ($bots as $bot): ?><option value="<?= e((int) $bot['id']) ?>"<?= (int) ($source['bot_user_id'] ?? 0) === (int) $bot['id'] ? ' selected' : '' ?>>@<?= e((string) $bot['username']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span class="label"><?= et('bots.source_name') ?></span><input class="input" name="name" maxlength="120" value="<?= e((string) ($source['name'] ?? '')) ?>" required></label>
    </div>
    <label class="field"><span class="label"><?= et('bots.feed_url') ?></span><input class="input" type="url" name="feed_url" maxlength="2048" value="<?= e((string) ($source['feed_url'] ?? '')) ?>" placeholder="https://example.com/feed/" required></label>
    <label class="field"><span class="label"><?= et('bots.interval') ?></span><input class="input" type="number" name="interval_minutes" min="5" max="43200" value="<?= e((int) ($source['interval_minutes'] ?? 60)) ?>" required><span class="help"><?= et('bots.interval_help') ?></span></label>
        <label class="field"><span class="label"><?= et('bots.template') ?></span><textarea class="textarea" name="post_template" rows="8" maxlength="2000" required><?= e((string) ($source['post_template'] ?? Bots::defaultSourceTemplate())) ?></textarea><span class="help"><?= et('bots.template_help') ?></span></label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= $create || (bool) ($source['enabled'] ?? false) ? ' checked' : '' ?>> <span><?= et('bots.enabled') ?></span></label>
</div>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>';

echo render('modals/layout', [
    'id' => $create ? 'bot-source-create-modal' : 'bot-source-edit-' . $id,
    'title' => t($create ? 'bots.new_source' : 'bots.edit_source'),
    'icon' => 'link',
    'action' => (string) ($action ?? BotAdmin::apiUrl()),
    'method' => $create ? 'POST' : 'PATCH',
    'ajax' => true,
    'target' => '#bots-list',
    'closeOnSuccess' => true,
    'size' => 'modal-panel-lg',
    'body' => $body,
    'footer' => $footer,
]);
