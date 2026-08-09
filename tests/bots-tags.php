<?php
declare(strict_types=1);

$tinycatRoot = realpath((string) ($argv[1] ?? getenv('TINYCAT_ROOT') ?: ''));

if ($tinycatRoot === false || !is_file($tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/bots-tags.php /path/to/tinycat\n");
    exit(2);
}

define('TINYCAT', true);
require_once $tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Bots' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$categories = array_map(static fn (int $number): string => 'Category ' . $number, range(1, 12));
$method = new ReflectionMethod(Bots::class, 'feedCategoryTags');
$rendered = (string) $method->invoke(null, $categories);
$tags = array_values(array_filter(explode(' ', $rendered), static fn (string $tag): bool => $tag !== ''));

if (count($tags) !== 12 || $tags[0] !== '#category-1' || $tags[11] !== '#category-12') {
    throw new RuntimeException('Bots did not preserve every valid feed category tag.');
}

echo "PASS Bots preserves more than ten feed category tags.\n";
