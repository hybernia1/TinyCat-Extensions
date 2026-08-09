# TinyCat Extensions

Official extensions for [TinyCat](https://github.com/hybernia1/TinyCat).

This repository is the signed source used by the Extensions screen in TinyCat administration. Each extension remains an independent package even though the official packages share one small repository.

## Official extensions

- **Bots** — publishes RSS and Atom feed items through bot accounts. Its own
  uninstall flow can retain all data, convert bot accounts while preserving
  their content, or delete bot accounts and their content.
- **Custom Pages** — lets administrators publish safe HTML pages at
  `/page/{slug}`. Draft pages stay private and published pages are included in
  the sitemap.

## Repository layout

Each top-level extension directory contains its own `extension.json`, runtime code, translations, views, and migrations. A release contains one ZIP per extension plus the signed `tinycat-extensions.json` catalog.

Packages are installed only after TinyCat verifies the catalog signature, package SHA-256, exact file list, manifest identity, and compatibility requirements.

Official extensions target the namespaced `TinyCat\Extension\Registry` API.
The release builder rejects packages that depend on the removed global
`ExtensionRegistry` compatibility name or publish legacy adoption metadata.

## Building a release

```shell
php tools/build-release.php --output=dist --key=extension-signing.key
php tools/verify-release.php dist
```

Before publishing a Registry API change, verify Bots against the target TinyCat
checkout:

```shell
php tests/bots-bootstrap.php /path/to/tinycat
php tests/bots-tags.php /path/to/tinycat
php tests/bots-uninstall.php /path/to/tinycat
php tests/bots-history-migration.php /path/to/tinycat
php tests/custom-pages-bootstrap.php /path/to/tinycat
php tests/custom-pages-uninstall.php /path/to/tinycat
```

The signing key is private release infrastructure and must never be committed.
