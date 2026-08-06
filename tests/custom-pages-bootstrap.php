<?php
declare(strict_types=1);

use TinyCat\Extension\Registry;
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
if (!Sitemap::hasSection('custom_pages')) {
    throw new RuntimeException('Custom Pages did not register its sitemap section.');
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

$html = CustomPages::renderMarkdown("# Heading\n\nA **safe** [link](https://example.com) and [bad](javascript:alert(1)).\n\n<script>alert(1)</script>");
if (!str_contains($html, '<h2>Heading</h2>')
    || !str_contains($html, '<strong>safe</strong>')
    || !str_contains($html, 'rel="noopener noreferrer"')
    || str_contains($html, 'href="javascript:')
    || !str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')) {
    throw new RuntimeException('Custom Pages Markdown renderer did not preserve its safety contract.');
}

echo "PASS Custom Pages boots and renders safe Markdown.\n";
