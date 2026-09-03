# FileMagic

[繁體中文](README.zh-TW.md)

Manage files in Laravel through one clear API. FileMagic stores file records with Eloquent
and works with Laravel Filesystem disks, so you can upload, find, read, download, and delete
files without building the same workflow for every source.

## What you can do

- Store uploaded files, local files, content, Base64, and remote HTTP(S) files.
- Generate and store TXT, JSON, CSV, and application-generated content with a trusted MIME type.
- Choose the disk, directory, filename, visibility, and collision behavior for each file.
- Limit file size and allow or block MIME types.
- Attach metadata and associate files with Eloquent models.
- Resize supported images before storage.
- Find files by ID, UUID, model, array, or Laravel Collection.
- Read file contents, open streams, create URLs, and return downloads.
- Download multiple files as ZIP or delete files in batches.
- Audit database records whose physical files are missing.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- PHP `ext-fileinfo`
- A configured Laravel Filesystem disk

Remote files need PHP `ext-curl`. Image resizing needs Intervention Image 4 with GD or
Imagick. ZIP downloads need PHP `ext-zip`.

## Installation

```bash
composer require mattmy/laravel-file-magic
php artisan vendor:publish --tag=file-magic-config
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

## Quick start

```php
use Mattmy\FileMagic\Facades\FileMagic;

$file = FileMagic::fromUpload($request->file('document'))
    ->onDisk('local')
    ->inDirectory('documents')
    ->named('contract')
    ->store();

return FileMagic::find($file)->download();
```

## Store files from different sources

```php
FileMagic::fromUpload($uploadedFile)->store();
FileMagic::fromPath($trustedPath)->store();
FileMagic::fromContent($contents, 'report.pdf')->store();
FileMagic::fromGeneratedContent($dxfContents, 'drawing.dxf', 'image/vnd.dxf')->store();
FileMagic::fromBase64($base64, 'avatar.png')->store();
FileMagic::fromUrl('https://example.com/manual.pdf')->store();
```

Generate documents with the same storage options:

```php
FileMagic::text('Hello')->named('message')->store();
FileMagic::json(['status' => 'ready'])->named('status')->store();
FileMagic::csv($rows)->named('report')->store();
```

```php
// Unsafe: neither the bytes nor MIME type are application-controlled.
FileMagic::fromGeneratedContent($request->getContent(), null, $request->header('Content-Type'));
```

The complete `$contents` string is already in PHP memory. Prefer upload, path, or remote
sources when generated content may approach the worker's memory limit.

## Configure a file

```php
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;

$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('s3')
    ->inDirectory('accounts/42/contracts')
    ->named('signed-contract')
    ->visibility(FileVisibility::Private)
    ->onCollision(CollisionPolicy::Unique)
    ->maxSize(10 * 1024 * 1024)
    ->allowMimeTypes(['application/pdf'])
    ->withMetadata(['category' => 'contract'])
    ->ownedBy($user)
    ->store();
```

Use `resizeImage()` when supported images should be reduced before storage:

```php
$image = FileMagic::fromUpload($uploadedImage)
    ->resizeImage(maxWidth: 1200, quality: 80)
    ->store();
```

## Find and use stored files

```php
$file = FileMagic::find($uuid)->one();
$files = FileMagic::find([$firstId, $secondUuid])->get();
$exists = FileMagic::find($uuid)->exists();
$url = FileMagic::find($uuid)->url();
$temporaryUrl = FileMagic::find($uuid)->temporaryUrl();
$customTemporaryUrl = FileMagic::find($uuid)->temporaryUrl(now()->addMinutes(15));
$contents = FileMagic::find($uuid)->contents();
$stream = FileMagic::find($uuid)->readStream();

return FileMagic::find($uuid)->download();
```

Use `readStream()` instead of `contents()` for large files, and close the returned stream
when finished.

## Safety and resource limits

Package configuration is validated strictly. Base64 inputs are rejected from their decoded
size before decoding, then decoded in bounded chunks into a temporary stream: the encoded input
remains in memory, while decoded bytes use temporary disk space. Storage paths must already be
canonical, and image size and MIME policies are checked before and after image processing.

## ZIP downloads and deletion

```php
return FileMagic::find($targets)->downloadZip('documents');
```

```php
$deleted = FileMagic::find($targets)->delete();
```

Applications must authorize every file before reading, downloading, or deleting it.
When collision locking is enabled, stores, `FileQuery::delete()`, and audit cleanup coordinate
the same storage path; direct `StoredFile::delete()` and external mutations are outside that guarantee.

## Consistency audits

Check whether database records still have matching files on storage:

```bash
php artisan file-magic:audit
```

The command is read-only unless `--delete-missing-records` is supplied. Cleanup revalidates each
locked record and checks storage again before deletion. Remote disks may
add network time and storage request charges, so review the audit guide before scheduling
cleanup.

## Handle errors

All package exceptions extend `FileMagicException`:

```php
use Mattmy\FileMagic\Exceptions\FileMagicException;

try {
    $file = FileMagic::fromUpload($uploadedFile)->store();
} catch (FileMagicException $exception) {
    report($exception);
}
```

## Documentation

For every method, field, parameter, exception, configuration option, performance note, and
security recommendation, see the
[FileMagic documentation](https://mattmy.github.io/laravel-file-magic-docs/).

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).

## License

FileMagic is open-source software licensed under the [MIT License](LICENSE).
