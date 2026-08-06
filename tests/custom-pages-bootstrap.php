<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;
use TinyCat\Extension\Assets;
use TinyCat\Sitemap;

$tinycatRoot = realpath((string) ($argv[1] ?? getenv('TINYCAT_ROOT') ?: ''));
if ($tinycatRoot === false || !is_file($tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    fwrite(STDERR, "Usage: php tests/custom-pages-bootstrap.php /path/to/tinycat\n");
    exit(2);
}

define('TINYCAT', true);
require_once $tinycatRoot . DIRECTORY_SEPARATOR . 'App' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (Registry::has('custom_pages')) {
    throw new RuntimeException('The test TinyCat installation already loaded Custom Pages.');
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Custom_Pages' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!Registry::has('custom_pages')) {
    throw new RuntimeException('Custom Pages did not register through TinyCat\\Extension\\Registry.');
}
if (Registry::requiredTables() !== ['custom_pages']) {
    throw new RuntimeException('Custom Pages registered an unexpected database contract.');
}
if ((CustomPages::adminNavigation()['icon'] ?? '') !== 'file') {
    throw new RuntimeException('Custom Pages did not register its menu icon.');
}
if (!Sitemap::hasSection('custom_pages')) {
    throw new RuntimeException('Custom Pages did not register its sitemap section.');
}
$assets = Assets::forPath('/admin/custom-pages');
if (count($assets['scripts'] ?? []) !== 1 || ($assets['styles'] ?? []) !== []) {
    throw new RuntimeException('Custom Pages did not register its HTML editor asset.');
}
Registry::registerRoutes();
$routes = (new ReflectionProperty(Core::class, 'routes'))->getValue();
$paths = array_column($routes, 'path');
foreach (['/page/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', '/admin/custom-pages', '/admin/custom-pages/new'] as $path) {
    if (!in_array($path, $paths, true)) {
        throw new RuntimeException('Custom Pages did not register its expected route: ' . $path);
    }
}
if (CustomPages::normalizeSlug('A custom page!') !== 'a-custom-page') {
    throw new RuntimeException('Custom Pages did not normalize page slugs.');
}

$pageController = (string) file_get_contents(dirname(__DIR__) . '/Custom_Pages/Controllers/public-page.php');
if (!str_contains($pageController, 'sanitize_html(')) {
    throw new RuntimeException('Custom Pages does not sanitize rendered HTML.');
}

$html = sanitize_html('<h2>Heading</h2><a href="javascript:alert(1)">bad</a><script>alert(1)</script>');
if (!str_contains($html, '<h2>Heading</h2>') || str_contains($html, 'javascript:') || str_contains($html, 'alert(1)')) {
    throw new RuntimeException('Custom Pages HTML safety contract failed.');
}

echo "PASS Custom Pages boots and renders safe HTML.\n";
