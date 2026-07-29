# Contributing to FileMagic

Thank you for helping improve FileMagic.

## Development setup

FileMagic requires PHP 8.3 or later and Composer 2.

```bash
git clone https://github.com/mattmy/laravel-file-magic.git
cd file-magic
composer install
composer check
```

`composer check` validates Composer metadata, runs Pest, PHPStan, Pint, and the
dependency security audit.

## Pull requests

- Keep each pull request focused on one change.
- Add or update Pest tests for changed behavior.
- Preserve strict types, public type declarations, and useful PHPDoc generics.
- Update both `README.md` and `README.zh-TW.md` when public behavior changes.
- Add user-visible changes under the `Unreleased` section of `CHANGELOG.md`.
- Do not commit credentials, generated `vendor` files, or application-specific code.

## Coding standards

Follow the repository `pint.json`. Prefer Laravel and PHP features already used
by the package over new abstractions or dependencies.

```bash
composer format
composer analyse
composer test
```

## Versioning

FileMagic follows Semantic Versioning:

- Patch releases contain backward-compatible fixes.
- Minor releases add backward-compatible functionality.
- Major releases may contain documented breaking changes.

Before tagging a release, move the relevant `Unreleased` entries into a dated
version section, verify `composer check`, and create a `vMAJOR.MINOR.PATCH` Git
tag matching the changelog.

## Security

Do not disclose vulnerabilities in public issues. Follow
[SECURITY.md](SECURITY.md) instead.
