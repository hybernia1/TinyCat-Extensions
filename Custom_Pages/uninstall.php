<?php
declare(strict_types=1);

return static function (PDO $database, array $context): array {
    if (($context['mode'] ?? '') !== 'delete') {
        return ['data_removed' => false];
    }

    $database->exec('DROP TABLE IF EXISTS custom_pages');
    return ['data_removed' => true];
};
