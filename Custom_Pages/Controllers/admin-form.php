<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
$page = $customPageId > 0 ? CustomPages::byId($customPageId) : null;
if ($customPageId > 0 && $page === null) {
    CustomPagesAdmin::notFound();
    return;
}

$values = CustomPagesAdmin::values($page);
$errors = [];
if (is_post()) {
    csrf_require();
    $result = CustomPagesAdmin::save($page);
    $values = $result['values'];
    $errors = $result['errors'];
}

$editing = $page !== null;
layout('layout', [
    'title' => t($editing ? 'custom_pages.edit_page' : 'custom_pages.new_page'),
    'current' => '/admin/custom-pages',
], static function () use ($editing, $page, $values, $errors): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= icon('file') ?> <?= et($editing ? 'custom_pages.edit_page' : 'custom_pages.new_page') ?></h1>
        </div>
        <div class="card-body">
            <?= Registry::render('custom_pages', 'admin-form', compact('editing', 'page', 'values', 'errors')) ?>
        </div>
    </section>
    <?php
});
