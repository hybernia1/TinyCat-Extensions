<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
$botId = max(0, (int) get('id', 0));
$detail = BotAdmin::accountDetailData($botId);

if ($detail === null) {
    http_response_code(404);
    layout('layout', [
        'title' => t('bots.detail_not_found'),
        'current' => '/admin/bots/accounts',
    ], static function (): void {
        ?><div class="alert alert-info"><?= et('bots.detail_not_found') ?></div><?php
    });
    return;
}

$bot = (array) $detail['account'];
$sources = (array) $detail['sources'];
$runs = (array) $detail['runs'];
$stats = (array) $detail['stats'];
$lastPosts = (array) $detail['last_posts'];
$lastRun = $detail['last_run'];
$profileUrl = (string) $detail['profile_url'];

layout('layout', [
    'title' => '@' . (string) $bot['username'],
    'current' => '/admin/bots/accounts',
    'actions' => '<a class="btn btn-secondary btn-sm" href="/admin/bots/accounts">' . icon('arrow-left') . ' <span>' . et('common.back') . '</span></a>',
], static function () use ($bot, $sources, $runs, $stats, $lastPosts, $lastRun, $profileUrl): void {
    $botName = '@' . (string) ($bot['username'] ?? '');
    ?>
    <section class="card">
        <div class="card-header split">
            <div class="cluster gap-3">
                <div class="avatar avatar-lg"><?= part('user/avatar', ['user' => $bot, 'alt' => $botName]) ?></div>
                <div>
                    <h1 class="text-xl m-0"><?= e($botName) ?></h1>
                    <p class="text-muted mb-0"><?= et('bots.detail_title') ?></p>
                </div>
            </div>
            <div class="cluster gap-2">
                <span class="badge<?= (string) ($bot['status'] ?? '') === 'active' ? ' badge-primary' : '' ?>"><?= e((string) ($bot['status'] ?? '')) ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= e($profileUrl) ?>" target="_blank" rel="noopener"><?= icon('external-link') ?> <span><?= et('bots.detail_public_profile') ?></span></a>
            </div>
        </div>
        <div class="card-body">
            <div class="grid sm:grid-2 md:grid-4">
                <?php foreach ($stats as $stat): ?>
                    <div class="card">
                        <div class="card-body stack gap-1">
                            <span class="table-meta"><?= et((string) $stat['label']) ?></span>
                            <strong class="text-2xl"><?= e((string) $stat['value']) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('settings') ?> <?= et('bots.detail_account') ?></h2></div>
        <form method="post" action="/api/admin/bot-accounts?id=<?= e((int) ($bot['id'] ?? 0)) ?>" enctype="multipart/form-data" data-ajax-form>
            <?= csrf_field() ?><input type="hidden" name="_method" value="PATCH">
            <div class="card-body grid md:grid-2">
                <label class="field"><span class="label"><?= et('common.status') ?></span><select class="select" name="status">
                    <?php foreach (admin_user_statuses() as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string) ($bot['status'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <div class="field"><span class="label"><?= et('bots.detail_identity') ?></span><div class="table-meta">@<?= e((string) ($bot['username'] ?? '')) ?> · <?= et('users.roles.bot') ?></div></div>
                <label class="field settings-field-span"><span class="label"><?= et('bots.detail_bio') ?></span><textarea class="textarea" name="bio" rows="4" maxlength="500"><?= e((string) ($bot['bio'] ?? '')) ?></textarea></label>
                <div class="field settings-field-span">
                    <span class="label"><?= et('account.avatar') ?></span>
                    <div class="cluster gap-3">
                        <div class="avatar avatar-lg"><?= part('user/avatar', ['user' => $bot, 'alt' => '@' . (string) ($bot['username'] ?? '')]) ?></div>
                        <div class="stack gap-1">
                            <input class="input" type="file" name="avatar" accept="image/png,image/jpeg,image/webp">
                            <?php if (user_avatar_url($bot) !== ''): ?>
                                <label class="check"><input type="checkbox" name="remove_avatar" value="1"> <span><?= et('account.remove_avatar') ?></span></label>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer cluster justify-end"><button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button></div>
        </form>
    </section>

    <section class="grid lg:grid-2">
        <article class="card">
            <div class="card-header split">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('link') ?> <?= et('bots.detail_sources') ?></h2>
            </div>
            <div class="card-body stack">
                <?php if ($sources === []): ?>
                    <p class="text-muted mb-0"><?= et('bots.no_sources') ?></p>
                <?php else: ?>
                    <?php foreach ($sources as $source): ?>
                        <?php $sourceId = (int) ($source['id'] ?? 0); $enabled = (bool) ($source['enabled'] ?? false); ?>
                        <details class="result-item">
                            <summary class="split gap-3">
                                <div class="stack gap-1">
                                    <strong><?= e((string) ($source['name'] ?? '')) ?></strong>
                                    <span class="table-meta"><?= e((string) ($source['feed_url'] ?? '')) ?></span>
                                    <small class="table-meta"><?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?><?php if (!empty($source['last_imported_at'])): ?> · <?= et('bots.last_imported', ['time' => datetime((string) $source['last_imported_at'])]) ?><?php endif; ?></small>
                                </div>
                                <span class="badge<?= $enabled ? ' badge-primary' : '' ?>"><?= et($enabled ? 'bots.enabled' : 'bots.disabled') ?></span>
                            </summary>
                            <div class="stack gap-2 mt-3">
                                <div class="grid sm:grid-2">
                                    <div><span class="label"><?= et('bots.source_name') ?></span><div><?= e((string) ($source['name'] ?? '')) ?></div></div>
                                    <div><span class="label"><?= et('bots.interval') ?></span><div><?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?></div></div>
                                </div>
                                <div><span class="label"><?= et('bots.feed_url') ?></span><a class="text-muted" href="<?= e((string) ($source['feed_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($source['feed_url'] ?? '')) ?></a></div>
                                <div><span class="label"><?= et('bots.template') ?></span><pre class="code-block"><code><?= e((string) ($source['post_template'] ?? '')) ?></code></pre></div>
                                <?php if (!empty($source['last_error'])): ?><div class="text-danger"><?= e((string) $source['last_error']) ?></div><?php endif; ?>
                                <div class="cluster gap-2">
                                    <form method="post" action="/api/admin/bots/toggle" data-ajax-form>
                                        <?= csrf_field() ?><input type="hidden" name="source_id" value="<?= e($sourceId) ?>"><input type="hidden" name="bot_id" value="<?= e($botId) ?>"><input type="hidden" name="redirect" value="/admin/bots/<?= e($botId) ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit"><?= icon($enabled ? 'minus' : 'play') ?> <span><?= et($enabled ? 'bots.detail_pause' : 'bots.detail_enable') ?></span></button>
                                    </form>
                                    <?php if ($enabled): ?>
                                        <form method="post" action="/api/admin/bots/run" data-ajax-form>
                                            <?= csrf_field() ?><input type="hidden" name="source_id" value="<?= e($sourceId) ?>"><input type="hidden" name="bot_id" value="<?= e($botId) ?>"><input type="hidden" name="redirect" value="/admin/bots/<?= e($botId) ?>">
                                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('refresh') ?> <span><?= et('bots.detail_run_now') ?></span></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('refresh') ?> <?= et('bots.detail_last_run') ?></h2></div>
            <div class="card-body stack">
                <?php if ($lastRun === null): ?>
                    <p class="text-muted mb-0"><?= et('bots.detail_no_runs') ?></p>
                <?php else: ?>
                    <div class="split"><span><?= et('bots.detail_source') ?></span><strong><?= e((string) ($lastRun['source_name'] ?? '')) ?></strong></div>
                    <div class="split"><span><?= et('bots.detail_status') ?></span><span class="badge<?= (string) ($lastRun['status'] ?? '') === 'error' ? '' : ' badge-primary' ?>"><?= e((string) ($lastRun['status'] ?? '')) ?></span></div>
                    <div class="split"><span><?= et('bots.detail_started') ?></span><time datetime="<?= e(date_iso((string) ($lastRun['started_at'] ?? ''))) ?>"><?= e(datetime((string) ($lastRun['started_at'] ?? ''))) ?></time></div>
                    <div class="split"><span><?= et('bots.detail_items_seen') ?></span><strong><?= e((int) ($lastRun['items_seen'] ?? 0)) ?></strong></div>
                    <?php if (!empty($lastRun['error'])): ?><div class="alert alert-danger mb-0"><?= e((string) $lastRun['error']) ?></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="grid lg:grid-2">
        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('clock') ?> <?= et('bots.detail_history') ?></h2></div>
            <div class="card-body">
                <?php if ($runs === []): ?>
                    <p class="text-muted mb-0"><?= et('bots.detail_no_runs') ?></p>
                <?php else: ?>
                    <div class="table-wrap"><table class="table"><thead><tr><th><?= et('bots.detail_source') ?></th><th><?= et('bots.detail_status') ?></th><th><?= et('bots.detail_started') ?></th><th><?= et('bots.detail_items_seen') ?></th></tr></thead><tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr><td><?= e((string) ($run['source_name'] ?? '')) ?></td><td><?= e((string) ($run['status'] ?? '')) ?></td><td><?= e(datetime((string) ($run['started_at'] ?? ''))) ?></td><td><?= e((int) ($run['items_seen'] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </div>
        </article>

        <article class="card">
            <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('file') ?> <?= et('bots.detail_posts') ?></h2></div>
            <div class="card-body stack">
                <?php if ($lastPosts === []): ?>
                    <p class="text-muted mb-0"><?= et('bots.detail_no_posts') ?></p>
                <?php else: ?>
                    <?php foreach ($lastPosts as $post): ?>
                        <a class="result-item" href="<?= e(status_url((int) ($post['id'] ?? 0))) ?>"><strong><?= e(plain_text_limit((string) ($post['body'] ?? ''), 180)) ?></strong><small class="table-meta"><?= e(datetime((string) ($post['published_at'] ?? ''))) ?></small></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});
