<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

if (method() !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

layout('layout', [
    'title' => t('bots.sources_title'),
    'current' => '/admin/bots/list',
    'actions' => '<button class="btn btn-primary btn-sm" type="button" data-modal-open="bot-source-create-modal">' . icon('plus') . ' <span>' . et('bots.new_source') . '</span></button>',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('rss') ?> <?= et('bots.sources_title') ?></h2>
            <button class="btn btn-secondary btn-sm" type="button" data-modal-open="bots-filter-modal">
                <?= icon('filter') ?> <span><?= et('common.filters') ?></span>
            </button>
        </div>
        <div class="card-body" id="bots-list">
            <?= ExtensionRegistry::render('bots', 'parts/sources', BotAdmin::sourcesViewData()) ?>
        </div>
    </section>
    <?= ExtensionRegistry::render('bots', 'modals/source', BotAdmin::sourceFormData(null)) ?>
    <?= ExtensionRegistry::render('bots', 'modals/source-filter', BotAdmin::sourceFilterData()) ?>
    <?php
});
