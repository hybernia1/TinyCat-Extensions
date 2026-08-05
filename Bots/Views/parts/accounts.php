<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$filters = is_array($filters ?? null) ? $filters : [];
$accounts = is_array($accounts ?? null) ? $accounts : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$params = is_array($params ?? null) ? $params : [];
$hasFilters = (bool) ($has_filters ?? false);
?>
<div class="stack stack-gap-14">
    <div class="admin-list-toolbar">
        <form class="admin-search-form" action="<?= e(BotAdmin::accountsApiUrl([], false)) ?>" method="get" data-ajax-form data-ajax-target="#bot-accounts-list" data-history="/admin/bots/accounts">
            <input type="hidden" name="view" value="html">
            <?php if (($filters['status'] ?? '') !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
            <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
            <label class="sr-only" for="bot-accounts-search"><?= et('common.search') ?></label>
            <span class="input-icon">
                <?= icon('search') ?>
                <input class="input" id="bot-accounts-search" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= et('bots.accounts_search_placeholder') ?>">
            </span>
            <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
        </form>
        <?php if ($hasFilters): ?>
            <div class="admin-filter-actions">
                <a class="btn btn-ghost" href="<?= e(BotAdmin::accountsApiUrl(['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#bot-accounts-list" data-history="<?= e(admin_list_url('/admin/bots/accounts', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                    <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?= part('admin/per-page', [
            'path' => '/api/admin/bot-accounts',
            'target' => '#bot-accounts-list',
            'params' => $params,
            'selected' => (int) ($pagination['per_page'] ?? admin_per_page()),
            'history_path' => '/admin/bots/accounts',
        ]) ?>
    </div>
    <?php if ($accounts === []): ?>
        <div class="alert alert-info"><?= et($hasFilters ? 'bots.accounts_empty_filtered' : 'bots.accounts_empty') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= et('bots.account') ?></th>
                        <th><?= et('common.status') ?></th>
                        <th><?= et('bots.detail_stat_sources') ?></th>
                        <th><?= et('bots.detail_stat_posts') ?></th>
                        <th><?= et('common.updated') ?></th>
                        <th><?= et('common.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <?php $id = (int) ($account['id'] ?? 0); ?>
                        <tr>
                            <td><strong>@<?= e((string) ($account['username'] ?? '')) ?></strong></td>
                            <td><?= part('admin/user-status-badge', ['status' => (string) ($account['status'] ?? '')]) ?></td>
                            <td><?= e((int) ($account['source_count'] ?? 0)) ?></td>
                            <td><?= e((int) ($account['post_count'] ?? 0)) ?></td>
                            <td><time class="table-meta" datetime="<?= e(date_iso((string) ($account['updated_at'] ?? ''))) ?>"><?= e(datetime((string) ($account['updated_at'] ?? ''))) ?></time></td>
                            <td>
                                <div class="table-actions">
                                    <a class="btn btn-sm btn-ghost btn-icon" href="/admin/bots/<?= e($id) ?>" aria-label="<?= et('bots.account_manage', ['username' => (string) ($account['username'] ?? '')]) ?>" title="<?= et('common.edit') ?>">
                                        <?= icon('edit') ?>
                                    </a>
                                    <form class="inline-flex" action="<?= e(BotAdmin::accountsApiUrl(['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#bot-accounts-list" data-confirm="<?= et('bots.account_delete_confirm', ['username' => (string) ($account['username'] ?? '')]) ?>" data-confirm-title="<?= et('bots.account_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>"><?= icon('trash') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= part('admin/pagination', [
            'pagination' => $pagination,
            'path' => '/api/admin/bot-accounts',
            'target' => '#bot-accounts-list',
            'params' => $params,
            'page_name' => 'page',
            'window' => 2,
            'history_path' => '/admin/bots/accounts',
        ]) ?>
    <?php endif; ?>
    <?= Registry::render('bots', 'modals/account-create') ?>
    <?= Registry::render('bots', 'modals/account-filter', ['filters' => $filters]) ?>
</div>
