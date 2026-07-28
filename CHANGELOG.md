# Changelog

All notable changes to this project will be documented in this file. FileMagic
follows [Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Initial strongly typed Laravel file-management implementation.
- Unified `find()` API for IDs, UUIDs, stored file models, arrays, Collections,
  and variadic targets.
- Zero-query model resolution, ordered batch results, strict target validation,
  and direct file operations.
- Optional Intervention Image 4 processing with safe fallback for unsupported
  file formats.
- TXT, JSON, and CSV document generation through the standard `PendingFile`
  storage flow.
- Optional streamed ZIP downloads through `FileQuery::downloadZip()`, including
  safe entry naming, configurable limits, and temporary-file cleanup.
- PHP, Laravel, image driver, SQLite, and MySQL continuous-integration coverage.
- Dependency auditing, Dependabot, contribution guidance, and a security policy.
- Secure streamed HTTP(S) imports through `fromUrl()`, with immutable remote
  options, TLS verification, SSRF protection, bounded redirects, byte limits,
  HTML opt-in, and temporary-file cleanup.

### Changed

- `FileQuery::get()` returns a standard `Illuminate\Support\Collection`.
- Storage locations use a fixed-length SHA-256 identity to keep MySQL indexes
  within supported limits.
- `Overwrite` now creates a streamed local temporary backup and restores the
  original object and visibility when storage or database persistence fails.
