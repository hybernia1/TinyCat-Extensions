<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$bots = is_array($bots ?? null) ? $bots : [];
$sources = is_array($sources ?? null) ? $sources : [];
$filterBotId = max(0, (int) ($filter_bot_id ?? 0));
$activeSources = count(array_filter(
    $sources,
    static fn (array $source): bool => (bool) ($source['enabled'] ?? false)
));
?>
<div class="stack stack-gap-14">
    <?php if ($bots === []): ?>
        <div class="alert alert-info"><?= et('bots.no_bots') ?></div>
    <?php else: ?>
        <div class="admin-list-toolbar">
            <div class="admin-filter-actions">
                <span class="badge"><?= et('bots.sources_count', ['count' => count($sources)]) ?></span>
                <span class="badge badge-primary"><?= et('bots.active_sources_count', ['count' => $activeSources]) ?></span>
                <?php if ($filterBotId > 0): ?>
                    <a class="btn btn-ghost" href="/admin/bots/list"><?= icon('close') ?> <span><?= et('common.clear_filters') ?></span></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($sources === []): ?>
            <div class="alert alert-info"><?= et($filterBotId > 0 ? 'bots.no_sources_filtered' : 'bots.no_sources') ?></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?= et('bots.source_name') ?></th>
                            <th><?= et('bots.bot') ?></th>
                            <th><?= et('common.status') ?></th>
                            <th><?= et('bots.schedule') ?></th>
                            <th><?= et('bots.last_import') ?></th>
                            <th><?= et('common.actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sources as $source): ?>
                            <?php $id = (int) ($source['id'] ?? 0); ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) ($source['name'] ?? '')) ?></strong>
                                    <div class="table-meta"><a href="<?= e((string) ($source['feed_url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($source['feed_url'] ?? '')) ?></a></div>
                                    <?php if (!empty($source['last_error'])): ?><div class="table-meta text-danger"><?= e((string) $source['last_error']) ?></div><?php endif; ?>
                                </td>
                                <td><a href="/admin/bots/<?= e((int) ($source['bot_user_id'] ?? 0)) ?>">@<?= e((string) ($source['username'] ?? '')) ?></a></td>
                                <td><span class="badge<?= (bool) ($source['enabled'] ?? false) ? ' badge-primary' : '' ?>"><?= et((bool) ($source['enabled'] ?? false) ? 'bots.enabled' : 'bots.disabled') ?></span></td>
                                <td>
                                    <?= et('bots.every_minutes', ['count' => (int) ($source['interval_minutes'] ?? 60)]) ?>
                                    <?php if (!empty($source['next_run_at'])): ?><div class="table-meta"><?= et('bots.next_run', ['time' => datetime((string) $source['next_run_at'])]) ?></div><?php endif; ?>
                                </td>
                                <td class="table-meta"><?= !empty($source['last_imported_at']) ? e(datetime((string) $source['last_imported_at'])) : et('bots.never_imported') ?></td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="bot-source-edit-<?= e($id) ?>" aria-label="<?= et('bots.edit_source') ?>" title="<?= et('bots.edit_source') ?>"><?= icon('edit') ?></button>
                                        <form class="inline-flex" method="post" action="<?= e(BotAdmin::sourceActionUrl('run')) ?>" data-ajax-form data-ajax-target="#bots-list">
                                            <?= csrf_field() ?><input type="hidden" name="source_id" value="<?= e($id) ?>">
                                            <button class="btn btn-sm btn-ghost btn-icon" type="submit" aria-label="<?= et('bots.detail_run_now') ?>" title="<?= et('bots.detail_run_now') ?>"><?= icon('refresh') ?></button>
                                        </form>
                                        <form class="inline-flex" method="post" action="<?= e(BotAdmin::apiUrl()) ?>" data-ajax-form data-ajax-target="#bots-list" data-confirm="<?= et('bots.delete_confirm') ?>">
                                            <?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><input type="hidden" name="id" value="<?= e($id) ?>">
                                            <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>"><?= icon('trash') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php foreach ($sources as $source): ?>
                <?= ExtensionRegistry::render('bots', 'modals/source', BotAdmin::sourceFormData($source)) ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
