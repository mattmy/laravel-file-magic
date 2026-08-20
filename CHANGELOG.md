# Changelog

All notable changes to this project will be documented in this file. FileMagic
follows [Semantic Versioning](https://semver.org/) and the structure of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- Preserve the package blocked-MIME defaults when the configuration key is
  omitted, while keeping explicit empty lists as an opt-out and invalid values
  strict.

## [0.2.0] - 2026-08-18

### Changed

- Reject non-global special-purpose IP addresses and canonicalize trailing-dot
  hostnames before DNS resolution and cURL IP pinning for remote imports.
- Treat invalid package configuration as `InvalidConfiguration` instead of
  coercing values or silently falling back.
- Reject oversized Base64 input before decoding it.
- Apply size and MIME restrictions both before and after image processing.
- Reject non-canonical storage paths instead of trimming them or normalizing
  separators.
- Validate model targets against the configured model before queries or filesystem
  operations, including their connection, table, and primary key.
- Reject unsaved owners and owners without an integer or non-empty string key.
- Make overwrite identity lookups ignore global scopes and preserve both causes
  when cleanup of a newly written object fails.
- Add opt-in Laravel atomic cache locks for collision checks, writes, record
  persistence, and compensation. Locking is disabled by default for backward
  compatibility and prevents cooperating writers from overwriting or deleting
  each other's objects when enabled.

## [0.1.1] - 2026-08-03

### Changed

- Clarified the Composer package description to better communicate FileMagic's
  secure, fluent file-management workflow.

## [0.1.0] - 2026-07-30

### Added

- Support for PHP 8.3 or later and Laravel 12 or 13.
- A unified `PendingFile` workflow for uploaded files, local paths, strings or
  binary content, Base64 input, remote HTTP(S) files, and generated documents.
- Content-based MIME detection, configurable size and MIME restrictions,
  normalized storage paths, visibility controls, metadata, ownership, and
  streamed checksums.
- TXT, JSON, and CSV generation through the standard storage workflow.
- Optional best-effort image resizing with Intervention Image 4 and GD or
  Imagick; unsupported image formats remain unchanged.
- `Unique`, `Error`, and `Overwrite` collision policies, including streamed
  local backups and compensating recovery when an overwrite fails.
- A unified `find()` API accepting IDs, UUIDs, configured stored-file models,
  arrays, Laravel Collections, and variadic targets.
- Ordered batch lookup and standard `Illuminate\Support\Collection` results.
- Stored-file operations for existence checks, URLs, temporary URLs, contents,
  streams, downloads, and deletion.
- Optional streamed ZIP downloads with safe entry names, configurable limits,
  duplicate-name handling, Zip Slip protection, and temporary-file cleanup.
- Consistency-aware batch deletion with partial-failure reconciliation and a
  structured `PartialFileDeletion` exception.
- A configurable stored-file model, database connection, table, primary key,
  owner relation, and migration.
- A read-only-by-default `file-magic:audit` Artisan command with disk filtering,
  bounded database chunks, explicit missing-record cleanup, safe handling of
  unknown storage states, and automation-friendly exit codes.
- English and Traditional Chinese package documentation and a complete
  bilingual documentation website.
- Continuous integration for supported PHP and Laravel versions, SQLite,
  MySQL 8.4, GD, Imagick, and optional-extension behavior.
- Composer validation, Pest tests, Larastan analysis, Pint formatting checks,
  dependency auditing, Dependabot, contribution guidance, and a security
  policy.

### Security

- Required PHP Fileinfo for MIME inspection based on actual file contents
  instead of filenames or client-provided MIME values.
- Streamed remote HTTP(S) imports with TLS verification enabled by default,
  strict URL validation, SSRF protection, DNS and redirect validation, private
  network restrictions, byte limits, bounded redirects, HTML opt-in, and
  temporary-file cleanup.
- Safe storage path and filename normalization, blocked MIME defaults, ZIP entry
  validation, and strict file-target resolution.
- Preservation of database records when storage state cannot be confirmed
  during deletion or consistency auditing.

[Unreleased]: https://github.com/mattmy/laravel-file-magic/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/mattmy/laravel-file-magic/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/mattmy/laravel-file-magic/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mattmy/laravel-file-magic/releases/tag/v0.1.0
