<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$bots = is_array($bots ?? null) ? $bots : [];
$filterBotId = max(0, (int) ($filter_bot_id ?? 0));

ob_start();
?>
<label class="field">
    <span class="label"><?= et('bots.filter_by_bot') ?></span>
    <select class="select" name="bot">
        <option value="0"><?= et('bots.all_bots') ?></option>
        <?php foreach ($bots as $bot): ?>
            <option value="<?= e((int) $bot['id']) ?>"<?= $filterBotId === (int) $bot['id'] ? ' selected' : '' ?>>@<?= e((string) $bot['username']) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<?php
$body = trim((string) ob_get_clean());
$footer = '<a class="btn btn-secondary" href="/admin/bots/list">' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>';

echo render('modals/layout', [
    'id' => 'bots-filter-modal',
    'title' => t('bots.filter_by_bot'),
    'icon' => 'filter',
    'action' => '/admin/bots/list',
    'method' => 'GET',
    'ajax' => false,
    'csrf' => false,
    'body' => $body,
    'footer' => $footer,
]);
