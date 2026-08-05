<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

if ((!class_exists('ZipArchive') && !class_exists('PharData')) || !function_exists('sodium_crypto_sign_detached')) {
    fwrite(STDERR, "Zip or Phar and the Sodium extension are required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', ['output::', 'key::', 'allow-dirty']);
$output = trim((string) ($options['output'] ?? 'dist'));
$keyPath = trim((string) ($options['key'] ?? 'extension-signing.key'));
$allowDirty = array_key_exists('allow-dirty', $options);

$absolute = static fn (string $path): bool => str_starts_with($path, DIRECTORY_SEPARATOR)
    || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
$output = $absolute($output) ? $output : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $output);
$keyPath = $absolute($keyPath) ? $keyPath : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $keyPath);

if (!$allowDirty && is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
    exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $status, $exitCode);
    if ($exitCode !== 0 || $status !== []) {
        fwrite(STDERR, "The tracked worktree must be clean. Use --allow-dirty only for local testing.\n");
        exit(1);
    }
}

$secret = base64_decode(trim((string) @file_get_contents($keyPath)), true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "A valid Ed25519 signing key is required.\n");
    exit(1);
}

if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
    fwrite(STDERR, "Unable to create the output directory.\n");
    exit(1);
}

$validVersion = static fn (string $version): bool => preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version) === 1;
$extensions = [];

foreach (new DirectoryIterator($root) as $directory) {
    if (!$directory->isDir() || $directory->isDot() || str_starts_with($directory->getFilename(), '.')) continue;
    $name = $directory->getFilename();
    $extensionRoot = $directory->getPathname();
    $manifestPath = $extensionRoot . DIRECTORY_SEPARATOR . 'extension.json';
    if (!is_file($manifestPath)) continue;

    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fwrite(STDERR, "Invalid {$name}/extension.json: {$exception->getMessage()}\n");
        exit(1);
    }

    $slug = strtolower(trim((string) ($manifest['slug'] ?? '')));
    $version = trim((string) ($manifest['version'] ?? ''));
    $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
    $minimumTinycat = trim((string) ($requires['tinycat'] ?? ''));
    $minimumPhp = trim((string) ($requires['php'] ?? '8.4.0'));
    $descriptions = is_array($manifest['descriptions'] ?? null) ? $manifest['descriptions'] : [];
    $homepage = trim((string) ($manifest['homepage'] ?? ''));

    if (!is_array($manifest) || array_is_list($manifest)
        || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1
        || strtolower($name) !== $slug
        || !$validVersion($version) || !$validVersion($minimumTinycat) || !$validVersion($minimumPhp)
        || filter_var($homepage, FILTER_VALIDATE_URL) === false
        || strtolower((string) parse_url($homepage, PHP_URL_SCHEME)) !== 'https'
    ) {
        fwrite(STDERR, "Invalid extension manifest: {$name}\n");
        exit(1);
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extensionRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = $name . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($extensionRoot) + 1));
        if (strlen($relative) > 240 || preg_match('/^[A-Za-z0-9._\/-]+$/', $relative) !== 1) {
            fwrite(STDERR, "Unsupported extension file path: {$relative}\n");
            exit(1);
        }
        $files[$relative] = $file->getPathname();
    }
    ksort($files, SORT_STRING);

    $packageName = 'tinycat-extension-' . $slug . '-' . $version . '.zip';
    $packagePath = $output . DIRECTORY_SEPARATOR . $packageName;
    @unlink($packagePath);
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fwrite(STDERR, "Unable to create {$packageName}.\n");
            exit(1);
        }
        foreach ($files as $relative => $path) {
            if (!$zip->addFile($path, $relative)) {
                $zip->close();
                fwrite(STDERR, "Unable to add {$relative}.\n");
                exit(1);
            }
        }
        $zip->close();
    } else {
        try {
            $zip = new PharData($packagePath, 0, null, Phar::ZIP);
            foreach ($files as $relative => $path) $zip->addFile($path, $relative);
            unset($zip);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Unable to create {$packageName}: {$exception->getMessage()}\n");
            exit(1);
        }
    }

    $hashes = [];
    foreach ($files as $relative => $path) $hashes[$relative] = hash_file('sha256', $path);
    $packageHash = hash_file('sha256', $packagePath);
    $packageSize = filesize($packagePath);
    if (!is_string($packageHash) || !is_int($packageSize) || $packageSize < 1) {
        fwrite(STDERR, "Unable to inspect {$packageName}.\n");
        exit(1);
    }

    $extensions[] = [
        'slug' => $slug,
        'name' => trim((string) ($manifest['name'] ?? $name)),
        'directory' => $name,
        'version' => $version,
        'requires' => ['tinycat' => $minimumTinycat, 'php' => $minimumPhp],
        'descriptions' => $descriptions,
        'homepage' => $homepage,
        'package' => $packageName,
        'sha256' => $packageHash,
        'size' => $packageSize,
        'files' => $hashes,
    ];
}

usort($extensions, static fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));
if ($extensions === []) {
    fwrite(STDERR, "No extension manifests were found.\n");
    exit(1);
}

$catalog = json_encode(['schema' => 1, 'extensions' => $extensions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
$catalogPath = $output . DIRECTORY_SEPARATOR . 'tinycat-extensions.json';
$signaturePath = $output . DIRECTORY_SEPARATOR . 'tinycat-extensions.sig';
file_put_contents($catalogPath, $catalog, LOCK_EX);
file_put_contents($signaturePath, base64_encode(sodium_crypto_sign_detached($catalog, $secret)) . "\n", LOCK_EX);
@chmod($signaturePath, 0644);
sodium_memzero($secret);

fwrite(STDOUT, "Built " . count($extensions) . " signed TinyCat extension package(s).\n");
