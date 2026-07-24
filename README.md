# FileMagic

[English](README.md) | [繁體中文](README.zh-TW.md)

FileMagic is a strongly typed file-management package for Laravel. It accepts uploads, readable local paths, binary strings, plain Base64, and Base64 Data URIs; detects trusted metadata from content; stores through Laravel Filesystem; and records each object with Eloquent.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- PHP `fileinfo`
- A configured Laravel Filesystem disk

Image resizing additionally needs `intervention/image` 4.0 or later and PHP GD or Imagick.

## Installation

```bash
composer require mattmy/file-magic
php artisan vendor:publish --tag=file-magic-config
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

Laravel auto-discovers `Mattmy\FileMagic\FileMagicServiceProvider` and the `FileMagic` facade. If discovery is disabled, register the provider manually:

```php
use Mattmy\FileMagic\FileMagicServiceProvider;

return [
    FileMagicServiceProvider::class,
];
```

## Configuration

The published `config/file-magic.php` contains:

```php
<?php

declare(strict_types=1);

return [
    'disk' => \env('FILE_MAGIC_DISK', \env('FILESYSTEM_DISK', 'local')),
    'directory' => \env('FILE_MAGIC_DIRECTORY', 'files'),
    'visibility' => \env('FILE_MAGIC_VISIBILITY', 'private'),
    'max_size' => 100 * 1024 * 1024,
    'allowed_mime_types' => [],
    'blocked_mime_types' => [
        'application/x-httpd-php',
        'application/x-php',
    ],
    'collision' => 'unique',
    'checksum_algorithm' => 'sha256',
    'temporary_url_ttl' => 5,
    'model' => Mattmy\FileMagic\Models\StoredFile::class,
    'table' => 'stored_files',
    'image' => [
        'quality' => 80,
        'max_width' => 1920,
    ],
];
```

| Option | Purpose |
| --- | --- |
| `disk` | Default Filesystem disk |
| `directory` | Default relative storage directory |
| `visibility` | `private` or `public` |
| `max_size` | Maximum detected size in bytes |
| `allowed_mime_types` | MIME allowlist; empty allows every non-blocked type |
| `blocked_mime_types` | MIME types rejected in every default operation |
| `collision` | `unique`, `error`, or `overwrite` |
| `checksum_algorithm` | PHP hash algorithm; invalid values fall back to `sha256` |
| `temporary_url_ttl` | Default temporary URL lifetime in minutes |
| `model` | Class extending `StoredFile` |
| `table` | File-record table |

Environment overrides:

```dotenv
FILE_MAGIC_DISK=s3
FILE_MAGIC_DIRECTORY=uploads
FILE_MAGIC_VISIBILITY=private
```

## Store an uploaded file

Validate the HTTP boundary first:

```php
use Illuminate\Http\Request;
use Mattmy\FileMagic\Facades\FileMagic;

public function store(Request $request)
{
    $validated = $request->validate([
        'document' => ['required', 'file', 'max:10240'],
    ]);

    $file = FileMagic::fromUpload($validated['document'])->store();

    return response()->json($file);
}
```

FileMagic inspects the content again. Request validation and package inspection protect different boundaries.

## Store from other sources

Readable local path:

```php
$file = FileMagic::fromPath(storage_path('imports/report.pdf'))
    ->inDirectory('reports')
    ->store();
```

Only pass paths chosen by trusted application code.

String or binary content:

```php
$file = FileMagic::fromContent(
    contents: $pdfContents,
    originalFilename: 'invoice.pdf',
    mimeType: 'application/pdf',
)->inDirectory('invoices')->store();
```

The MIME argument is only a source hint. Stored MIME and extension come from content inspection.

Plain Base64:

```php
$file = FileMagic::fromBase64(
    \base64_encode($contents),
    'document.pdf',
)->store();
```

Data URI:

```php
$file = FileMagic::fromBase64(
    'data:text/plain;base64,'.\base64_encode('Hello'),
    'hello.txt',
)->store();
```

Decoding is strict. Invalid or non-canonical input throws `InvalidBase64`. Base64 consumes more memory than its decoded file, so prefer uploads or paths for large objects.

## Customize storage

```php
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;

$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('s3')
    ->inDirectory('accounts/42/contracts')
    ->named('signed-contract')
    ->visibility(FileVisibility::Private)
    ->onCollision(CollisionPolicy::Unique)
    ->store();
```

`named()` takes a name without extension. Directories must be relative; absolute paths, drive paths, null bytes, `.` and `..` are rejected.

Collision policies:

- `Unique` adds a random suffix when the path exists.
- `Error` throws `FileWriteFailed`.
- `Overwrite` intentionally replaces the physical path.

## Size and MIME restrictions

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->maxSize(10 * 1024 * 1024)
    ->allowMimeTypes([
        'application/pdf',
        'image/jpeg',
        'image/png',
    ])
    ->blockMimeTypes([
        'image/svg+xml',
        'text/html',
    ])
    ->store();
```

Per-operation values override their corresponding global configuration. FileMagic uses `finfo`, not the browser-provided MIME header.

## Metadata and ownership

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->withMetadata([
        'category' => 'invoice',
        'year' => 2026,
    ])
    ->ownedBy($user)
    ->store();
```

Metadata must be JSON-serializable and must not contain secrets. Any persisted Eloquent model can be an owner.

Add the inverse relation:

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mattmy\FileMagic\Models\StoredFile;

public function files(): MorphMany
{
    return $this->morphMany(StoredFile::class, 'owner');
}
```

Eager-load the relation from the owning model, then pass the already loaded file model into FileMagic:

```php
$post = Post::query()
    ->with('attachment')
    ->findOrFail($postId);

return FileMagic::find($post->attachment)->download();
```

Passing an existing `StoredFile` model does not execute another database query.

## Image resizing

```bash
composer require "intervention/image:^4.0"
```

With GD or Imagick enabled:

```php
$file = FileMagic::fromUpload($image)
    ->resizeImage(maxWidth: 1600, quality: 82)
    ->store();
```

JPEG, PNG, WebP, and BMP are supported. GIF and SVG are deliberately not transformed, preventing silent animation loss or treating active SVG content as an ordinary raster image. They may still be stored without `resizeImage()`.

## Query files

All normal file lookups go through the single `find()` entry point. It accepts a positive integer ID, UUID, or existing `StoredFile` model:

```php
$file = FileMagic::find($id)->one();
$file = FileMagic::find($uuid)->one();
$file = FileMagic::find($fileModel)->one();
```

You may operate on the first resolved file without extracting the model:

```php
FileMagic::find($uuid)->contents();
FileMagic::find($fileModel)->download();
FileMagic::find($id)->delete();
```

Batch lookups accept variadic targets, an array, or a Laravel Collection:

```php
$variadic = FileMagic::find(
    $firstId,
    $secondUuid,
    $fileModel,
)->get();

$array = FileMagic::find([
    $firstId,
    $secondUuid,
    $fileModel,
])->get();

$collection = FileMagic::find(collect([
    $firstId,
    $secondUuid,
    $fileModel,
]))->get();
```

All three forms preserve input order and remove duplicate models. IDs and UUIDs are fetched in one query; model targets are reused without querying. Empty arrays and Collections return an empty `FileCollection` without a query.

Arrays and Collections must be one-dimensional. Every element must be a positive integer ID, valid UUID, or persisted `StoredFile`; invalid elements throw `InvalidFileTarget` instead of being silently removed.

`one()` returns the first resolved `StoredFile` or `null`. `get()` returns an operational `FileCollection`, not an Eloquent query builder.

## URLs

```php
$publicUrl = FileMagic::find($target)->url();
$temporaryUrl = FileMagic::find($target)->temporaryUrl();
$customExpiration = FileMagic::find($target)
    ->temporaryUrl(now()->addMinutes(30));
```

The disk must support the requested operation. Local temporary URLs require `serve => true`; cloud disks require their normal credentials.

## Read and stream

```php
if (FileMagic::find($target)->exists()) {
    $smallContents = FileMagic::find($target)->contents();
}
```

Use streams for large files:

```php
$stream = FileMagic::find($target)->readStream();

try {
    while (\feof($stream) === false) {
        $chunk = \fread($stream, 8192);

        if ($chunk === false) {
            break;
        }

        // Consume the chunk.
    }
} finally {
    \fclose($stream);
}
```

The caller owns and must close the returned stream.

## Download

```php
return FileMagic::find($target)->download();
return FileMagic::find($target)->download('invoice-2026.pdf');
```

Laravel streams the response using the detected MIME type.

## Delete

Single file:

```php
$deleted = FileMagic::find($target)->delete();
```

Batch deletion:

```php
$files = FileMagic::find($targets)->get();
$deleted = $files->delete();
```

Batch deletion groups physical paths by disk and removes database rows in one query.

## Custom model and table

```php
namespace App\Models;

use Mattmy\FileMagic\Models\StoredFile as BaseStoredFile;

final class StoredFile extends BaseStoredFile {}
```

```php
'model' => App\Models\StoredFile::class,
'table' => 'assets',
```

The custom model must extend the package model. Configure a custom table before publishing the migration. If already deployed, create a new migration rather than editing migration history.

## Exceptions

All exceptions extend `FileMagicException`.

| Exception | Meaning |
| --- | --- |
| `InvalidFileSource` | Invalid upload, path, or stream |
| `InvalidBase64` | Invalid Base64 or Data URI |
| `InvalidFileName` | Unsafe or reserved name |
| `InvalidStoragePath` | Unsafe directory |
| `InvalidFileTarget` | Invalid ID, UUID, model, array, or Collection target |
| `FileTooLarge` | Byte limit exceeded |
| `DisallowedMimeType` | MIME type rejected |
| `FileWriteFailed` | Storage or collision failure |
| `FileRecordFailed` | Database persistence failure |
| `FileNotFound` | Physical content unavailable |
| `ImageProcessingUnavailable` | Missing dependency, driver, or format support |

Handle errors in the application layer:

```php
use Mattmy\FileMagic\Exceptions\FileMagicException;

try {
    $file = FileMagic::fromUpload($uploadedFile)->store();
} catch (FileMagicException $exception) {
    report($exception);

    return back()->withErrors(['file' => 'The file could not be stored.']);
}
```

The package deliberately does not choose HTTP status codes or response formats.

## Testing

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Facades\FileMagic;

Storage::fake('documents');

$file = FileMagic::fromUpload(
    UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
)->onDisk('documents')->store();

Storage::disk('documents')->assertExists($file->path);

expect($file->contents())->toBe('hello');
```

Use `RefreshDatabase` for database assertions and load the published migration.

## Performance

- Uploads and paths are inspected and stored with streams.
- Checksums are calculated in chunks.
- `contents()` loads everything into memory; prefer `readStream()` for large files.
- Base64 necessarily uses additional memory.
- Image decoding may consume far more memory than the compressed file size.
- Use `find()` for batch lookup, eager-load owner relations, and call `FileCollection::delete()` for batch deletion.

## Security

- Authorize every store, read, download, and delete operation.
- Keep Laravel request validation in front of the package.
- Treat original names and client MIME values as untrusted metadata.
- Prefer MIME allowlists for sensitive workflows.
- Consider blocking HTML and SVG when serving from the same origin.
- Keep private files on private disks and use short-lived temporary URLs.
- Never pass a user-controlled server path to `fromPath()`.
- Configure web-server request limits in addition to `max_size`.
- Add antivirus scanning when required by the threat model.

## Migration from `App\Support\File`

```php
// Before
App\Support\File\FileMagic::parse($uploadedFile)->save();

// After
Mattmy\FileMagic\Facades\FileMagic::fromUpload($uploadedFile)->store();
```

```php
// Before
App\Support\File\FileMagic::base64($base64)->save();

// After
Mattmy\FileMagic\Facades\FileMagic::fromBase64($base64)->store();
```

```php
// Before
App\Support\File\FileMagic::find($uuid)->one();

// After
Mattmy\FileMagic\Facades\FileMagic::find($uuid)->one();
```

Migrate old rows with a dedicated application migration or command after confirming how legacy `name`, `original_name`, and `path` map to the new complete `path`. Do not point both models at one unconverted table.

## API reference

### `FileMagic`

```php
fromUpload(UploadedFile $file): PendingFile
fromPath(string $path): PendingFile
fromContent(string $contents, ?string $originalFilename = null, ?string $mimeType = null): PendingFile
fromBase64(string $base64, ?string $originalFilename = null): PendingFile
find(int|string|StoredFile|array|Collection ...$targets): FileQuery
```

### `PendingFile`

```php
onDisk(string $disk): self
inDirectory(string $directory): self
named(string|int $filename): self
visibility(FileVisibility $visibility): self
onCollision(CollisionPolicy $policy): self
maxSize(int $bytes): self
allowMimeTypes(array $mimeTypes): self
blockMimeTypes(array $mimeTypes): self
withMetadata(array $metadata): self
ownedBy(Model $owner): self
resizeImage(?int $maxWidth = null, ?int $quality = null): self
store(): StoredFile
```

### `FileQuery`

```php
one(): ?StoredFile
get(): FileCollection
exists(): bool
url(): string
temporaryUrl(?DateTimeInterface $expiration = null): string
contents(): string
readStream(): resource
download(?string $name = null): StreamedResponse
delete(): int
```

### `FileCollection`

```php
count(): int
isEmpty(): bool
first(): ?StoredFile
urls(): Collection
delete(): int
getIterator(): Traversable
```

## Troubleshooting

### MIME differs from the browser value

This is expected. FileMagic trusts content detected with `finfo`.

### A file gets a `.bin` extension

Symfony Mime has no extension for the detected type. Inspect `mime_type` and decide whether the workflow should allow it.

### Temporary local URLs fail

Enable `serve => true` on the local disk or use a driver supporting temporary URLs.

### Image processing is unavailable

Install `intervention/image`, enable GD or Imagick, and use JPEG, PNG, WebP, or BMP.

### A physical object was removed externally

`existsOnDisk()` returns `false`; `contents()` and `readStream()` throw `FileNotFound`.

## License

FileMagic is open-source software licensed under the [MIT License](LICENSE).
