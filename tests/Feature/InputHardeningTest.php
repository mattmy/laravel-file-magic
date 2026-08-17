<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\SizeLimitedFileSource;
use Mattmy\FileMagic\Exceptions\DisallowedMimeType;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidBase64;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Exceptions\InvalidFileName;
use Mattmy\FileMagic\Exceptions\InvalidStoragePath;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\PendingFile;
use Mattmy\FileMagic\Sources\ContentFileSource;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('rejects an unsupported checksum configuration before storage', function (): void {
    config()->set('file-magic.checksum_algorithm', 'unsupported');

    expect(static fn () => FileMagic::fromContent('contents')->store())
        ->toThrow(InvalidConfiguration::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('rejects invalid store configuration before storage', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(static fn () => FileMagic::fromContent('contents')->store())
        ->toThrow(InvalidConfiguration::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'empty disk' => ['file-magic.disk', ''],
    'non-string directory' => ['file-magic.directory', []],
    'string maximum size' => ['file-magic.max_size', '100'],
    'invalid visibility' => ['file-magic.visibility', 'private '],
    'invalid collision policy' => ['file-magic.collision', 'unique '],
    'invalid MIME member' => ['file-magic.allowed_mime_types', ['text/plain', 123]],
    'null checksum' => ['file-magic.checksum_algorithm', null],
    'floating maximum size' => ['file-magic.max_size', 100.0],
    'associative blocked MIME list' => ['file-magic.blocked_mime_types', ['mime' => 'text/plain']],
    'empty blocked MIME member' => ['file-magic.blocked_mime_types', ['']],
]);

it('limits Base64 by exact decoded bytes before materialization', function (): void {
    $pending = FileMagic::fromBase64(\base64_encode('123456'));
    $source = $pending->source();

    expect($source)->toBeInstanceOf(SizeLimitedFileSource::class);

    /** @var SizeLimitedFileSource $source */
    $source->limitSize(5);
})->throws(FileTooLarge::class);

it('accepts Base64 whose decoded bytes equal the operation limit', function (): void {
    $file = FileMagic::fromBase64(\base64_encode('123456'))
        ->maxSize(6)
        ->store();

    expect($file->contents())->toBe('123456')
        ->and($file->size)->toBe(6);
});

it('stores canonical plain and data URI Base64 values', function (string $value, string $expected): void {
    $file = FileMagic::fromBase64($value)->store();

    expect($file->contents())->toBe($expected);
})->with([
    'empty' => ['', ''],
    'no padding' => ['MTIz', '123'],
    'one padding' => ['MTI=', '12'],
    'two padding' => ['MQ==', '1'],
    'data URI without padding' => ['data:text/plain;base64,MTIz', '123'],
    'data URI with one padding' => ['data:text/plain;base64,MTI=', '12'],
    'data URI with two padding' => ['data:text/plain;base64,MQ==', '1'],
]);

it('rejects non-canonical Base64 during source creation', function (string $value): void {
    FileMagic::fromBase64($value);
})->with([
    'whitespace' => ['MT Iz'],
    'URL-safe alphabet' => ['__8='],
    'misplaced padding' => ['M=TI'],
    'invalid padded bits' => ['MR=='],
    'non-quantized length' => ['MTI'],
])->throws(InvalidBase64::class);

it('lets an operation Base64 limit override the global limit', function (): void {
    config()->set('file-magic.max_size', 1);

    $file = FileMagic::fromBase64('MTIz')->maxSize(3)->store();

    expect($file->contents())->toBe('123');
});

it('rejects an oversized Base64 data URI before storage', function (): void {
    expect(static fn () => FileMagic::fromBase64('data:text/plain;base64,MTIz')->maxSize(2)->store())
        ->toThrow(FileTooLarge::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('rejects an oversized original image before resizing it', function (): void {
    $contents = inputHardeningPng(width: 100, height: 100);

    expect(\strlen($contents))->toBeGreaterThan(1000);

    FileMagic::fromContent($contents, 'large.png', 'image/png')
        ->resizeImage(maxWidth: 1, quality: 80)
        ->maxSize(1000)
        ->store();
})->throws(FileTooLarge::class);

it('rejects a blocked original image before image processing', function (): void {
    expect(static fn () => FileMagic::fromContent(inputHardeningPng(2, 2), 'blocked.png', 'image/png')
        ->resizeImage(maxWidth: 1, quality: 80)
        ->blockMimeTypes(['image/png'])
        ->store())->toThrow(DisallowedMimeType::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('rejects an original image outside the MIME allowlist before image processing', function (): void {
    expect(static fn () => FileMagic::fromContent(inputHardeningPng(2, 2), 'blocked.png', 'image/png')
        ->resizeImage(maxWidth: 1, quality: 80)
        ->allowMimeTypes(['text/plain'])
        ->store())->toThrow(DisallowedMimeType::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('rejects image output that grows beyond the accepted source size', function (): void {
    $contents = inputHardeningPng(1, 1);

    expect(static fn () => FileMagic::fromContent($contents, 'small.png', 'image/png')
        ->resizeImage(maxWidth: 1, quality: 100)
        ->maxSize(\strlen($contents))
        ->store())->toThrow(FileTooLarge::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('inspects a source only once when image processing returns the same source', function (): void {
    $source = new InputHardeningCountingSource('not an image');

    $file = (new PendingFile($source))
        ->resizeImage(maxWidth: 1, quality: 80)
        ->store();

    expect($file->contents())->toBe('not an image')
        ->and($source->openedStreams)->toBe(2);
});

it('rejects non-canonical storage directories before storage', function (string $directory): void {
    expect(static fn () => FileMagic::fromContent('contents')->inDirectory($directory)->store())
        ->toThrow(InvalidStoragePath::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'backslash separator' => ['nested\\directory'],
    'repeated separator' => ['nested//directory'],
    'leading whitespace' => [' nested'],
    'trailing separator' => ['nested/'],
    'reserved segment' => ['CON/files'],
    'delete control' => ["nested/\x7F"],
    'invalid UTF-8' => ["nested/\xC3\x28"],
    'unsafe character' => ['nested:directory'],
    'dot segment' => ['nested/./directory'],
    'parent segment' => ['nested/../directory'],
    'leading separator' => ['/nested'],
    'null byte' => ["nested/\0directory"],
    'trailing dot' => ['nested./directory'],
]);

it('rejects non-canonical storage filenames before storage', function (string $filename): void {
    expect(static fn () => FileMagic::fromContent('contents')->named($filename)->store())
        ->toThrow(InvalidFileName::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'leading whitespace' => [' report'],
    'trailing whitespace' => ['report '],
    'reserved stem' => ['CON.txt'],
    'forward slash' => ['nested/report'],
    'delete control' => ["report\x7F"],
    'invalid UTF-8' => ["report\xC3\x28"],
    'leading dot' => ['.report'],
    'null byte' => ["report\0"],
    'unsafe character' => ['report?'],
    'trailing dot' => ['report.'],
]);

it('stores an empty directory at the disk root without a leading slash', function (): void {
    $file = FileMagic::fromContent('contents')
        ->inDirectory('')
        ->named('root')
        ->store();

    expect($file->path)->toBe('root.txt');
    Storage::disk('testing')->assertExists('root.txt');
});

it('accepts a filename at the length limit and rejects one above it', function (): void {
    $file = FileMagic::fromContent('contents')->named(\str_repeat('a', 200))->store();

    expect($file)->toBeInstanceOf(StoredFile::class)
        ->and(static fn () => FileMagic::fromContent('contents')->named(\str_repeat('a', 201))->store())
        ->toThrow(InvalidFileName::class);
});

it('rejects invalid operation options', function (Closure $operation): void {
    $operation(FileMagic::fromContent('contents'));
})->with([
    'empty disk' => [static fn ($pending) => $pending->onDisk('')],
    'zero maximum size' => [static fn ($pending) => $pending->maxSize(0)],
    'associative MIME list' => [static fn ($pending) => $pending->allowMimeTypes(['mime' => 'text/plain'])],
    'empty MIME member' => [static fn ($pending) => $pending->blockMimeTypes([''])],
])->throws(InvalidConfiguration::class);

it('validates image configuration only when defaults are used', function (): void {
    config()->set('file-magic.image.quality', '80');

    expect(FileMagic::fromContent('contents')->store())->toBeInstanceOf(StoredFile::class)
        ->and(static fn () => FileMagic::fromContent('contents')->resizeImage())
        ->toThrow(InvalidConfiguration::class);
});

it('accepts image configuration boundary values', function (): void {
    config()->set('file-magic.image.max_width', 1);
    config()->set('file-magic.image.quality', 1);
    $minimum = FileMagic::fromContent('contents')->resizeImage()->imageOptions();

    config()->set('file-magic.image.quality', 100);
    $maximum = FileMagic::fromContent('contents')->resizeImage()->imageOptions();

    expect($minimum?->maxWidth)->toBe(1)
        ->and($minimum?->quality)->toBe(1)
        ->and($maximum?->quality)->toBe(100);
});

it('rejects invalid remote defaults before creating a pending source', function (): void {
    config()->set('file-magic.remote.allowed_ports', ['443']);

    FileMagic::fromUrl('https://example.com/file.txt');
})->throws(InvalidConfiguration::class);

it('does not validate unused optional configuration', function (): void {
    config()->set('file-magic.remote.connect_timeout', '5');
    config()->set('file-magic.zip.max_size', '100');

    expect(FileMagic::fromContent('contents')->store())->toBeInstanceOf(StoredFile::class);
});

it('accepts remote configuration boundary values', function (): void {
    config()->set('file-magic.remote.connect_timeout', 1);
    config()->set('file-magic.remote.timeout', 1);
    config()->set('file-magic.remote.max_redirects', 0);
    config()->set('file-magic.remote.allowed_hosts', ['example.com']);
    config()->set('file-magic.remote.allowed_ports', [1, 65535]);

    expect(FileMagic::fromUrl('https://example.com/file.txt'))->toBeInstanceOf(PendingFile::class);

    config()->set('file-magic.remote.max_redirects', 10);

    expect(FileMagic::fromUrl('https://example.com/file.txt'))->toBeInstanceOf(PendingFile::class);
});

it('rejects invalid ZIP configuration when ZIP download is used', function (): void {
    if (\extension_loaded('zip') === false) {
        $this->markTestSkipped('The PHP zip extension is unavailable.');
    }

    config()->set('file-magic.zip.max_files', '100');
    $file = FileMagic::fromContent('contents')->store();

    FileMagic::find($file)->downloadZip();
})->throws(InvalidConfiguration::class);

it('rejects an unresolved disk before source inspection', function (): void {
    config()->set('file-magic.disk', 'missing-disk');

    FileMagic::fromBase64('MTIz')->store();
})->throws(InvalidConfiguration::class);

it('rejects invalid model runtime configuration', function (): void {
    config()->set('file-magic.table', '');

    expect(static fn () => FileMagic::fromBase64('MTIz')->store())
        ->toThrow(InvalidConfiguration::class);

    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('validates the temporary URL TTL only when the default expiration is used', function (): void {
    config()->set('file-magic.temporary_url_ttl', '5');
    $file = new StoredFile([
        'disk' => 'testing',
        'path' => 'file.txt',
    ]);

    $file->temporaryUrl();
})->throws(InvalidConfiguration::class);

function inputHardeningPng(int $width, int $height): string
{
    $pixels = \random_bytes($width * $height * 3);
    $rows = '';

    for ($offset = 0; $offset < \strlen($pixels); $offset += $width * 3) {
        $rows .= "\0" . \substr($pixels, $offset, $width * 3);
    }

    $compressed = \gzcompress($rows);

    if ($compressed === false) {
        throw new \RuntimeException('The PNG fixture could not be compressed.');
    }

    return "\x89PNG\r\n\x1a\n"
        . inputHardeningPngChunk('IHDR', \pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . inputHardeningPngChunk('IDAT', $compressed)
        . inputHardeningPngChunk('IEND', '');
}

function inputHardeningPngChunk(string $type, string $data): string
{
    return \pack('N', \strlen($data))
        . $type
        . $data
        . \pack('N', \crc32($type . $data));
}

final class InputHardeningCountingSource implements FileSource
{
    public int $openedStreams = 0;

    /**
     * Create a source that records each materialization.
     */
    public function __construct(private readonly string $contents) {}

    /**
     * Open a fresh stream and record the call.
     *
     * @return resource
     */
    public function openStream()
    {
        $this->openedStreams++;

        return (new ContentFileSource($this->contents))->openStream();
    }

    /**
     * Return no original filename.
     */
    public function originalFilename(): ?string
    {
        return null;
    }

    /**
     * Return no client MIME hint.
     */
    public function clientMimeType(): ?string
    {
        return null;
    }
}
