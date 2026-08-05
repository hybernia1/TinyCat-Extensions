<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

layout('layout', [
    'title' => t('bots.accounts_title'),
    'current' => '/admin/bots/accounts',
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="bot-account-create-modal">' . icon('user-plus') . ' <span>' . et('bots.new_account') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('users') ?> <?= et('bots.accounts_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="bot-accounts-filter-modal">
                <?= icon('filter') ?> <span><?= et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="bot-accounts-list">
            <?= Registry::render('bots', 'parts/accounts', BotAdmin::accountsViewData()) ?>
        </div>
    </section>
    <?php
});
