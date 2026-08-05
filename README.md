# TinyCat Extensions

Official extensions for [TinyCat](https://github.com/hybernia1/TinyCat).

This repository is the signed source used by the Extensions screen in TinyCat administration. Each extension remains an independent package even though the official packages share one small repository.

## Official extensions

- **Bots** — publishes RSS and Atom feed items through bot accounts. Its own
  uninstall flow can retain all data, convert bot accounts while preserving
  their content, or delete bot accounts and their content.

## Repository layout

Each top-level extension directory contains its own `extension.json`, runtime code, translations, views, and migrations. A release contains one ZIP per extension plus the signed `tinycat-extensions.json` catalog.

Packages are installed only after TinyCat verifies the catalog signature, package SHA-256, exact file list, manifest identity, and compatibility requirements.

## Building a release

```shell
php tools/build-release.php --output=dist --key=extension-signing.key
php tools/verify-release.php dist
```

The signing key is private release infrastructure and must never be committed.
