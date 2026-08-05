<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is available only from the command line.\n");
    exit(1);
}

$directory = isset($argv[1]) ? (string) $argv[1] : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist';
if (!str_starts_with($directory, DIRECTORY_SEPARATOR) && preg_match('~^[A-Za-z]:[\\\\/]~', $directory) !== 1) {
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory);
}

$catalogPath = $directory . DIRECTORY_SEPARATOR . 'tinycat-extensions.json';
$signaturePath = $directory . DIRECTORY_SEPARATOR . 'tinycat-extensions.sig';
$catalogJson = (string) @file_get_contents($catalogPath);
$signature = base64_decode(trim((string) @file_get_contents($signaturePath)), true);
$publicKey = base64_decode('zyqmqAwPK6K+c5V/cCifO4dP4s2rVDfzhoUST5Wqjcw=', true);

if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
    || !is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || !sodium_crypto_sign_verify_detached($signature, $catalogJson, $publicKey)
) {
    fwrite(STDERR, "The extension catalog signature is invalid.\n");
    exit(1);
}

try {
    $catalog = json_decode($catalogJson, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "The extension catalog is invalid JSON.\n");
    exit(1);
}

foreach ((array) ($catalog['extensions'] ?? []) as $extension) {
    $package = $directory . DIRECTORY_SEPARATOR . basename((string) ($extension['package'] ?? ''));
    $expectedHash = (string) ($extension['sha256'] ?? '');
    $expectedSize = (int) ($extension['size'] ?? 0);
    if (!is_file($package) || filesize($package) !== $expectedSize || !hash_equals($expectedHash, (string) hash_file('sha256', $package))) {
        fwrite(STDERR, "An extension package failed integrity verification.\n");
        exit(1);
    }

    $seen = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            fwrite(STDERR, "An extension package cannot be opened.\n");
            exit(1);
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
            if (str_ends_with($name, '/')) continue;
            $content = $zip->getFromIndex($index);
            if (!is_string($content) || !isset($extension['files'][$name])
                || !hash_equals((string) $extension['files'][$name], hash('sha256', $content))
            ) {
                $zip->close();
                fwrite(STDERR, "An extension package contains an invalid file.\n");
                exit(1);
            }
            $seen[$name] = true;
        }
        $zip->close();
    } else {
        try {
            $archive = new PharData($package);
            $real = realpath($package);
            $prefix = 'phar://' . str_replace('\\', '/', (string) $real) . '/';
            foreach (new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
                if (!$file instanceof SplFileInfo || $file->isDir()) continue;
                $uri = str_replace('\\', '/', $file->getPathname());
                $name = str_starts_with($uri, $prefix) ? substr($uri, strlen($prefix)) : '';
                $content = $name !== '' ? file_get_contents($uri) : false;
                if (!is_string($content) || !isset($extension['files'][$name])
                    || !hash_equals((string) $extension['files'][$name], hash('sha256', $content))
                ) {
                    throw new RuntimeException('Invalid extension package file.');
                }
                $seen[$name] = true;
            }
        } catch (Throwable) {
            fwrite(STDERR, "An extension package contains an invalid file.\n");
            exit(1);
        }
    }
    if (array_diff(array_keys((array) ($extension['files'] ?? [])), array_keys($seen)) !== []) {
        fwrite(STDERR, "An extension package is incomplete.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Verified " . count((array) ($catalog['extensions'] ?? [])) . " signed TinyCat extension package(s).\n");
