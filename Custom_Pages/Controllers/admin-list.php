<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
$pages = CustomPages::allForAdmin();

layout('layout', [
    'title' => t('custom_pages.title'),
    'current' => '/admin/custom-pages',
    'actions' => '<a class="btn btn-primary btn-sm" href="/admin/custom-pages/new">' . icon('plus') . ' <span>' . et('custom_pages.new_page') . '</span></a>',
], static function () use ($pages): void {
    ?>
    <section class="card">
        <div class="card-header split">
            <div>
                <h1 class="text-lg m-0 cluster gap-2"><?= icon('file') ?> <?= et('custom_pages.title') ?></h1>
                <p class="text-muted mb-0"><?= et('custom_pages.intro') ?></p>
            </div>
        </div>
        <div class="card-body">
            <?= Registry::render('custom_pages', 'admin-list', ['pages' => $pages]) ?>
        </div>
    </section>
    <?php
});
