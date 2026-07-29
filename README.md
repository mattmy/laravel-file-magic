# FileMagic

[English](README.md) | [繁體中文](README.zh-TW.md)

FileMagic is a file-management package built exclusively for Laravel. It provides
one consistent workflow for accepting, validating, storing, querying, downloading,
and deleting files through Laravel Filesystem and Eloquent.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- PHP `curl` and `fileinfo`
- A configured Laravel Filesystem disk

Image resizing additionally needs `intervention/image` 4.0 or later and GD or
Imagick. ZIP downloads additionally need PHP `ext-zip`.

## Installation

```bash
composer require mattmy/laravel-file-magic
php artisan vendor:publish --tag=file-magic-config
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

Laravel automatically discovers the service provider and `FileMagic` facade.

## Quick start

Store an uploaded file:

```php
use Mattmy\FileMagic\Facades\FileMagic;

$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('local')
    ->inDirectory('documents')
    ->named('contract')
    ->store();
```

Retrieve it by ID, UUID, model, array, or Laravel Collection:

```php
$file = FileMagic::find($uuid)->one();

return FileMagic::find($file)->download();
```

The same `PendingFile` workflow supports local paths, content, Base64, remote
HTTP(S) files, and generated TXT, JSON, and CSV documents:

```php
$document = FileMagic::json([
    'message' => 'Hello',
])
    ->onDisk('s3')
    ->inDirectory('exports')
    ->named('message')
    ->store();
```

## Highlights

- Strict content inspection, size limits, MIME allowlists, and safe path handling
- Streaming storage, reads, downloads, and bounded ZIP creation
- SSRF-resistant URL imports with TLS verification enabled by default
- Best-effort image resizing with Intervention Image 4
- Collision policies with recovery for failed overwrite operations
- Ordered batch lookup and consistency-aware batch deletion
- Custom stored-file model and table support
- English and Traditional Chinese documentation

## Documentation

The complete guide, configuration reference, security notes, examples, and
troubleshooting information are available at:

**[Read the FileMagic documentation](https://mattmy.github.io/laravel-file-magic-docs/)**

- [Getting started](https://mattmy.github.io/laravel-file-magic-docs/guide/getting-started)
- [Storing files](https://mattmy.github.io/laravel-file-magic-docs/guide/storing-files)
- [Remote files](https://mattmy.github.io/laravel-file-magic-docs/guide/remote-files)
- [Documents and images](https://mattmy.github.io/laravel-file-magic-docs/guide/documents-and-images)
- [Querying files](https://mattmy.github.io/laravel-file-magic-docs/guide/querying-files)
- [ZIP and deletion](https://mattmy.github.io/laravel-file-magic-docs/guide/zip-and-deletion)
- [Models and exceptions](https://mattmy.github.io/laravel-file-magic-docs/guide/models-and-exceptions)
- [API reference and troubleshooting](https://mattmy.github.io/laravel-file-magic-docs/guide/reference)

## Security

Applications must authorize every file operation and retain Laravel request
validation at the HTTP boundary. Treat original filenames, client MIME values,
remote content, and stored bytes as untrusted. See the
[security guide](https://mattmy.github.io/laravel-file-magic-docs/guide/reference#security)
before accepting untrusted files or URLs.

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).

## License

FileMagic is open-source software licensed under the [MIT License](LICENSE).

Read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a pull request. Releases
follow [Semantic Versioning](https://semver.org/) and are documented in
[CHANGELOG.md](CHANGELOG.md).
