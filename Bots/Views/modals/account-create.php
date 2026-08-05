<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

ob_start();
?>
<div class="stack">
    <label class="field">
        <span class="label"><?= et('common.username') ?></span>
        <input class="input" name="username" autocomplete="off" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" required>
        <span class="help"><?= e(username_hint()) ?></span>
    </label>
    <label class="field">
        <span class="label"><?= et('common.status') ?></span>
        <select class="select" name="status">
            <?php foreach (admin_user_statuses() as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $value === 'active' ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <p class="help m-0"><?= et('bots.account_create_help') ?></p>
</div>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('user-plus') . ' <span>' . et('common.create') . '</span></button>';

echo render('modals/layout', [
    'id' => 'bot-account-create-modal',
    'title' => t('bots.new_account'),
    'icon' => 'user-plus',
    'action' => BotAdmin::accountsApiUrl(),
    'method' => 'POST',
    'target' => '#bot-accounts-list',
    'reset' => true,
    'closeOnSuccess' => true,
    'body' => $body,
    'footer' => $footer,
]);
