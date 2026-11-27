# FileMagic

[繁體中文](README.zh-TW.md)

FileMagic stores files on Laravel Filesystem disks and keeps an Eloquent `StoredFile` record for each file.

## What you can do

- Store uploads, local files, text or binary content, Base64, and remote HTTP(S) files.
- Generate TXT, JSON, and CSV files, or supply a trusted MIME type for content your app generates.
- Set size and MIME limits, choose how to handle filename collisions, and resize supported images.
- Save metadata and associate files with Eloquent models.
- Find files by ID, UUID, or model. Read them, generate URLs, download files or ZIP archives, and delete in batches.
- Audit database records whose files are missing from storage.

## Requirements

- PHP 8.3 or later in the PHP 8 series
- Laravel 12 or 13
- PHP `ext-fileinfo`
- A configured Laravel Filesystem disk and database connection

Remote imports require `ext-curl`, and ZIP downloads require `ext-zip`.

## Installation

```bash
composer require mattmy/laravel-file-magic
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

To change the defaults, publish the configuration with `php artisan vendor:publish --tag=file-magic-config`.

If you need image resizing, also install Intervention Image 4 and enable PHP GD or Imagick:

```bash
composer require intervention/image:^4.0
```

## Quick start

Validate the upload and check the user's permissions before running this example.

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

`fromContent()` detects the MIME type from the bytes. With `fromGeneratedContent()`, FileMagic
uses the supplied MIME type when Symfony can map it to an extension. Use it only for content
whose format your application guarantees. If the MIME type is omitted or unmapped, FileMagic
falls back to `fromContent()`.

```php
// Unsafe: neither the bytes nor MIME type are application-controlled.
FileMagic::fromGeneratedContent($request->getContent(), null, $request->header('Content-Type'));
```

For a raw request body, use `fromContent($request->getContent())`. Both methods take the complete
`$contents` string in PHP memory. For large files, use an upload, a local path, or a URL as appropriate.

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

`named()` keeps dots in the base name and appends the MIME-derived extension. For example,
a text file named `report.txt` saves as `report.txt.txt` if the name is available.

When overwriting, omitted `ownedBy()` and `withMetadata()` options preserve their existing values.
Pass `withMetadata([])` to clear the metadata.

Use `resizeImage()` to shrink supported images before storage.

```php
$image = FileMagic::fromUpload($uploadedImage)
    ->resizeImage(maxWidth: 1200, quality: 80)
    ->store();
```

FileMagic checks size and MIME limits before resizing and checks again if the output changes.

## Find and use stored files

```php
$query = FileMagic::find($uuid);
$file = $query->one();
$files = FileMagic::find([$firstId, $secondUuid])->get();
$exists = $query->exists();
$url = $query->url();
$temporaryUrl = $query->temporaryUrl();
$customTemporaryUrl = $query->temporaryUrl(now()->addMinutes(15));
$contents = $query->contents();

return $query->download();
```

`find()` accepts decimal ID strings such as `'123'` and skips invalid scalar targets such as `1.0`.
Reuse a `FileQuery` to avoid resolving the same targets again. `contents()` loads the whole file into
memory. For large files, use `readStream()` and close the stream when you're done.

## Safety and resource limits

- Authorize every operation in your application, including access to each file in a ZIP download.
- Use relative storage paths with `/` separators.
- `store()` copies the source to a local temporary file. Budget extra space for decoded Base64,
  remote downloads, ZIP entries and archives, and overwrite backups.
- FileMagic checks the decoded Base64 size before decoding. The encoded input and the finished CSV
  from `csv()` occupy PHP memory.

## ZIP downloads and deletion

```php
return FileMagic::find($targets)->downloadZip('documents');
```

```php
$deleted = FileMagic::find($targets)->delete();
```

When enabled, path locks coordinate stores, batch deletes, and audit cleanup through the same
cache backend while the locks are valid. Direct `StoredFile::delete()` and external writes bypass these locks.

## Consistency audits

Check whether the files referenced by database records still exist.

```bash
php artisan file-magic:audit
```

The audit is read-only by default. `--delete-missing-records` rechecks the record and its file, then
deletes the record only if the file is still missing. Non-interactive cleanup requires `--force`.
On remote disks, each existence check can add latency and request charges.

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

See the [FileMagic documentation](https://mattmy.github.io/laravel-file-magic-docs/) for guides and the API reference.

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).

## License

FileMagic uses the [MIT License](LICENSE).
