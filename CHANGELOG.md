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
- PHP, Laravel, image driver, SQLite, and MySQL continuous-integration coverage.
- Dependency auditing, Dependabot, contribution guidance, and a security policy.

### Changed

- `FileQuery::get()` returns a standard `Illuminate\Support\Collection`.
- Storage locations use a fixed-length SHA-256 identity to keep MySQL indexes
  within supported limits.
