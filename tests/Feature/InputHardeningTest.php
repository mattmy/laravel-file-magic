<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Contracts\SizeLimitedFileSource;
use Mattmy\FileMagic\Data\ImageOptions;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Exceptions\DisallowedMimeType;
use Mattmy\FileMagic\Exceptions\FileNotFound;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidBase64;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Exceptions\InvalidFileName;
use Mattmy\FileMagic\Exceptions\InvalidStoragePath;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\PendingFile;
use Mattmy\FileMagic\Sources\Base64FileSource;
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Sources\RemoteFileSource;
use Mattmy\FileMagic\Support\FileMagicConfig;

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

it('keeps blocked MIME defaults only when the key is missing', function (): void {
    $configuration = config('file-magic');

    \assert(is_array($configuration));

    config()->set(
        'file-magic',
        Arr::except($configuration, ['blocked_mime_types']),
    );

    expect(app(FileMagicConfig::class)->blockedMimeTypes())
        ->toBe(['application/x-httpd-php', 'application/x-php']);
});

it('honors explicit blocked MIME lists, including an empty opt-out', function (array $blocked): void {
    config()->set('file-magic.blocked_mime_types', $blocked);

    expect(app(FileMagicConfig::class)->blockedMimeTypes())->toBe($blocked);
})->with([
    'empty opt-out' => [[]],
    'custom list' => [['text/html']],
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

it('decodes Base64 into independent rewound temporary streams across chunk boundaries', function (): void {
    $contents = \random_bytes(16385);
    $source = new Base64FileSource(\base64_encode($contents));
    $source->limitSize(\strlen($contents));
    $first = $source->openStream();
    $second = $source->openStream();

    try {
        expect(\ftell($first))->toBe(0)
            ->and(\ftell($second))->toBe(0)
            ->and(\fread($first, 8193))->toBe(\substr($contents, 0, 8193));

        \fclose($first);

        expect(\stream_get_contents($second))->toBe($contents);
    } finally {
        if (\is_resource($first)) {
            \fclose($first);
        }

        if (\is_resource($second)) {
            \fclose($second);
        }
    }
});

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

it('rejects oversized Base64 before decoding or mutating storage', function (string $base64): void {
    $pending = FileMagic::fromBase64($base64);
    $source = $pending->source();

    expect(static fn () => $pending->maxSize(5)->store())
        ->toThrow(FileTooLarge::class);

    expect(inputHardeningHasDecodedCache($source))->toBeFalse()
        ->and(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'plain Base64' => [\base64_encode('123456')],
    'data URI' => ['data:text/plain;base64,' . \base64_encode('123456')],
]);

it('rejects invalid paths before Base64 decoding', function (Closure $configure, string $exception): void {
    $pending = FileMagic::fromBase64(\base64_encode('contents'));
    $source = $pending->source();
    $configured = $configure($pending);

    \assert($configured instanceof PendingFile);

    expect(static fn () => $configured->store())->toThrow($exception);

    expect(inputHardeningHasDecodedCache($source))->toBeFalse()
        ->and(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'directory' => [
        static fn (PendingFile $pending): PendingFile => $pending->inDirectory('invalid/'),
        InvalidStoragePath::class,
    ],
    'filename' => [
        static fn (PendingFile $pending): PendingFile => $pending->named('invalid/name'),
        InvalidFileName::class,
    ],
]);

it('rejects invalid paths before a remote request', function (Closure $configure, string $exception): void {
    Http::preventStrayRequests();
    $configured = $configure(FileMagic::fromUrl('https://downloads.example.com/file.txt'));

    \assert($configured instanceof PendingFile);

    expect(static fn () => $configured->store())
        ->toThrow($exception);

    Http::assertNothingSent();
    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'directory' => [
        static fn (PendingFile $pending): PendingFile => $pending->inDirectory('invalid/'),
        InvalidStoragePath::class,
    ],
    'filename' => [
        static fn (PendingFile $pending): PendingFile => $pending->named('invalid/name'),
        InvalidFileName::class,
    ],
]);

it('rejects original image policy failures before image processing', function (
    Closure $configure,
    string $exception,
): void {
    $source = new InputHardeningCountingSource(inputHardeningPng(100, 100));
    $pending = pendingFile($source)->resizeImage(maxWidth: 1, quality: 80);
    $configured = $configure($pending);

    \assert($configured instanceof PendingFile);

    expect(static fn () => $configured->store())->toThrow($exception);

    expect($source->openedStreams)->toBe(1)
        ->and(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
})->with([
    'oversized source' => [
        static fn (PendingFile $pending): PendingFile => $pending->maxSize(1000),
        FileTooLarge::class,
    ],
    'blocked MIME type' => [
        static fn (PendingFile $pending): PendingFile => $pending->blockMimeTypes(['image/png']),
        DisallowedMimeType::class,
    ],
    'non-allowed MIME type' => [
        static fn (PendingFile $pending): PendingFile => $pending->allowMimeTypes(['text/plain']),
        DisallowedMimeType::class,
    ],
]);

it('rejects image output that grows beyond the accepted source size', function (): void {
    $contents = inputHardeningPng(1, 1);

    expect(static fn () => FileMagic::fromContent($contents, 'small.png', 'image/png')
        ->resizeImage(maxWidth: 1, quality: 100)
        ->maxSize(\strlen($contents))
        ->store())->toThrow(FileTooLarge::class);

    expect(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('captures a source once when image processing returns the same snapshot', function (): void {
    $source = new InputHardeningCountingSource('not an image');

    $file = pendingFile($source)
        ->resizeImage(maxWidth: 1, quality: 80)
        ->store();

    expect($file->contents())->toBe('not an image')
        ->and($source->openedStreams)->toBe(1);
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
    'trailing whitespace' => ['nested '],
    'trailing whitespace segment' => ['nested/child '],
    'trailing separator' => ['nested/'],
    'reserved segment' => ['CON/files'],
    'delete control' => ["nested/\x7F"],
    'C0 control' => ["nested/\x1F"],
    'invalid UTF-8' => ["nested/\xC3\x28"],
    'unsafe character' => ['nested:directory'],
    'dot segment' => ['nested/./directory'],
    'parent segment' => ['nested/../directory'],
    'leading separator' => ['/nested'],
    'null byte' => ["nested/\0directory"],
    'reserved stem with suffix' => ['nested/CON.txt'],
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
    'backslash' => ['nested\\report'],
    'forward slash' => ['nested/report'],
    'delete control' => ["report\x7F"],
    'C0 control' => ["report\x1F"],
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

it('preserves a canonical nested directory', function (): void {
    $file = FileMagic::fromContent('contents')
        ->inDirectory('accounts/42/documents')
        ->named('report')
        ->store();

    expect($file->path)->toBe('accounts/42/documents/report.txt');
});

it('accepts a filename at the length limit and rejects one above it', function (): void {
    $file = FileMagic::fromContent('contents')->named(\str_repeat('a', 200))->store();

    expect(static fn () => FileMagic::fromContent('contents')->named(\str_repeat('a', 201))->store())
        ->toThrow(InvalidFileName::class);
});

it('rejects invalid operation options', function (Closure $operation): void {
    $operation(FileMagic::fromContent('contents'));
})->with([
    'empty disk' => [static fn (PendingFile $pending): PendingFile => $pending->onDisk('')],
    'zero maximum size' => [static fn (PendingFile $pending): PendingFile => $pending->maxSize(0)],
    // This test deliberately verifies rejection of a non-list MIME array.
    'associative MIME list' => [static fn (PendingFile $pending): PendingFile => $pending->allowMimeTypes(
        // @phpstan-ignore argument.type
        ['mime' => 'text/plain'],
    )],
    'empty MIME member' => [static fn (PendingFile $pending): PendingFile => $pending->blockMimeTypes([''])],
])->throws(InvalidConfiguration::class);

it('validates image configuration only when defaults are used', function (): void {
    config()->set('file-magic.image.quality', '80');
    config()->set('file-magic.image.max_width', '1920');

    FileMagic::fromContent('contents')->store();

    expect(static fn () => FileMagic::fromContent('contents')->resizeImage())
        ->toThrow(InvalidConfiguration::class)
        ->and(FileMagic::fromContent('contents')->resizeImage(maxWidth: 1, quality: 80)->imageOptions())
        ->toBeInstanceOf(ImageOptions::class);
});

it('reads only the missing image defaults', function (): void {
    config()->set('file-magic.image.max_width', '1920');

    expect(FileMagic::fromContent('contents')->resizeImage(maxWidth: 1)->imageOptions())
        ->toBeInstanceOf(ImageOptions::class);

    config()->set('file-magic.image.max_width', 1920);
    config()->set('file-magic.image.quality', '80');

    expect(FileMagic::fromContent('contents')->resizeImage(quality: 80)->imageOptions())
        ->toBeInstanceOf(ImageOptions::class);
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

it('validates remote defaults only when they are used', function (): void {
    config()->set('file-magic.remote.allowed_ports', ['443']);

    expect(static fn () => FileMagic::fromUrl('https://example.com/file.txt'))
        ->toThrow(InvalidConfiguration::class);
    FileMagic::fromContent('contents')->store();

    expect(FileMagic::fromUrl('https://example.com/file.txt', new RemoteFileOptions())->source())
        ->toBeInstanceOf(RemoteFileSource::class);
});

it('does not validate unused optional configuration', function (): void {
    config()->set('file-magic.remote.connect_timeout', '5');
    config()->set('file-magic.zip.max_size', '100');

    expect(FileMagic::fromContent('contents')->store()->exists())->toBeTrue();
});

it('accepts remote configuration boundary values', function (): void {
    config()->set('file-magic.remote.connect_timeout', 1);
    config()->set('file-magic.remote.timeout', 1);
    config()->set('file-magic.remote.max_redirects', 0);
    config()->set('file-magic.remote.allowed_hosts', ['example.com']);
    config()->set('file-magic.remote.allowed_ports', [1, 65535]);

    expect(FileMagic::fromUrl('https://example.com/file.txt')->source())->toBeInstanceOf(RemoteFileSource::class);

    config()->set('file-magic.remote.max_redirects', 10);

    expect(FileMagic::fromUrl('https://example.com/file.txt')->source())->toBeInstanceOf(RemoteFileSource::class);
});

it('reads strict configuration reader boundaries', function (
    string $key,
    mixed $value,
    Closure $read,
    mixed $expected,
): void {
    config()->set($key, $value);

    expect($read(app(FileMagicConfig::class)))->toBe($expected);
})->with([
    'disk' => ['file-magic.disk', 'archive', static fn (FileMagicConfig $config): string => $config->disk(), 'archive'],
    'disk root directory' => ['file-magic.directory', '', static fn (FileMagicConfig $config): string => $config->directory(), ''],
    'maximum size lower bound' => ['file-magic.max_size', 1, static fn (FileMagicConfig $config): int => $config->maximumSize(), 1],
    'temporary URL TTL lower bound' => ['file-magic.temporary_url_ttl', 1, static fn (FileMagicConfig $config): int => $config->temporaryUrlTtl(), 1],
    'image quality lower bound' => ['file-magic.image.quality', 1, static fn (FileMagicConfig $config): int => $config->imageQuality(), 1],
    'image quality upper bound' => ['file-magic.image.quality', 100, static fn (FileMagicConfig $config): int => $config->imageQuality(), 100],
    'ZIP limits lower bound' => ['file-magic.zip.max_files', 1, static fn (FileMagicConfig $config): int => $config->zipMaximumFiles(), 1],
    'redirect lower bound' => ['file-magic.remote.max_redirects', 0, static fn (FileMagicConfig $config): int => $config->remoteMaximumRedirects(), 0],
    'redirect upper bound' => ['file-magic.remote.max_redirects', 10, static fn (FileMagicConfig $config): int => $config->remoteMaximumRedirects(), 10],
    'visibility enum' => ['file-magic.visibility', 'public', static fn (FileMagicConfig $config): string => $config->visibility()->value, 'public'],
    'collision enum' => ['file-magic.collision', 'overwrite', static fn (FileMagicConfig $config): string => $config->collisionPolicy()->value, 'overwrite'],
    'allowed MIME list' => ['file-magic.allowed_mime_types', ['text/plain'], static fn (FileMagicConfig $config): array => $config->allowedMimeTypes(), ['text/plain']],
    'remote host list' => ['file-magic.remote.allowed_hosts', ['example.com'], static fn (FileMagicConfig $config): array => $config->remoteAllowedHosts(), ['example.com']],
    'remote port bounds' => ['file-magic.remote.allowed_ports', [1, 65535], static fn (FileMagicConfig $config): array => $config->remoteAllowedPorts(), [1, 65535]],
    'hash algorithm' => ['file-magic.checksum_algorithm', 'sha256', static fn (FileMagicConfig $config): string => $config->checksumAlgorithm(), 'sha256'],
    'equal remote timeouts' => ['file-magic.remote.timeout', 5, static fn (FileMagicConfig $config): int => $config->remoteTimeout(5), 5],
]);

it('rejects invalid strict configuration reader values', function (
    string $key,
    mixed $value,
    Closure $read,
): void {
    config()->set($key, $value);

    expect(static fn () => $read(app(FileMagicConfig::class)))->toThrow(InvalidConfiguration::class);
})->with([
    'numeric disk' => ['file-magic.disk', 1, static fn (FileMagicConfig $config): string => $config->disk()],
    'zero maximum size' => ['file-magic.max_size', 0, static fn (FileMagicConfig $config): int => $config->maximumSize()],
    'float TTL' => ['file-magic.temporary_url_ttl', 1.0, static fn (FileMagicConfig $config): int => $config->temporaryUrlTtl()],
    'boolean image width' => ['file-magic.image.max_width', true, static fn (FileMagicConfig $config): int => $config->imageMaximumWidth()],
    'null ZIP size' => ['file-magic.zip.max_size', null, static fn (FileMagicConfig $config): int => $config->zipMaximumSize()],
    'high image quality' => ['file-magic.image.quality', 101, static fn (FileMagicConfig $config): int => $config->imageQuality()],
    'negative redirects' => ['file-magic.remote.max_redirects', -1, static fn (FileMagicConfig $config): int => $config->remoteMaximumRedirects()],
    'sparse MIME list' => ['file-magic.allowed_mime_types', [1 => 'text/plain'], static fn (FileMagicConfig $config): array => $config->allowedMimeTypes()],
    'zero remote port' => ['file-magic.remote.allowed_ports', [0], static fn (FileMagicConfig $config): array => $config->remoteAllowedPorts()],
    'unsupported hash' => ['file-magic.checksum_algorithm', 'unsupported', static fn (FileMagicConfig $config): string => $config->checksumAlgorithm()],
    'short remote timeout' => ['file-magic.remote.timeout', 4, static fn (FileMagicConfig $config): int => $config->remoteTimeout(5)],
]);

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

    try {
        expect(static fn () => FileMagic::fromBase64('MTIz')->store())
            ->toThrow(InvalidConfiguration::class);
    } finally {
        config()->set('file-magic.table', 'stored_files');
    }

    Storage::disk('testing')->assertDirectoryEmpty('/');
});

it('validates the temporary URL TTL only when FileQuery uses the default expiration', function (): void {
    config()->set('file-magic.temporary_url_ttl', '5');
    $file = FileMagic::fromContent('contents')->store();

    FileMagic::find($file)->temporaryUrl();
})->throws(InvalidConfiguration::class);

it('does not read the temporary URL TTL when an expiration is explicit', function (): void {
    config()->set('file-magic.temporary_url_ttl', '5');
    $file = FileMagic::fromContent('contents')->store();
    $exception = null;

    try {
        FileMagic::find($file)->temporaryUrl(now()->addMinute());
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->not->toBeInstanceOf(InvalidConfiguration::class);
});

it('resolves a required file before reading the default temporary URL TTL', function (): void {
    config()->set('file-magic.temporary_url_ttl', '5');

    expect(static fn () => FileMagic::find()->temporaryUrl())->toThrow(FileNotFound::class);
});

function inputHardeningPng(int $width, int $height): string
{
    \assert($width > 0 && $height > 0);

    $pixels = \random_bytes($width * $height * 3);
    $rows = '';

    for ($offset = 0; $offset < \strlen($pixels); $offset += $width * 3) {
        $rows .= "\0" . \substr($pixels, $offset, $width * 3);
    }

    $compressed = \gzcompress($rows);

    if ($compressed === false) {
        throw new RuntimeException('The PNG fixture could not be compressed.');
    }

    return "\x89PNG\r\n\x1a\n"
        . inputHardeningPngChunk('IHDR', \pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . inputHardeningPngChunk('IDAT', $compressed)
        . inputHardeningPngChunk('IEND', '');
}

function inputHardeningHasDecodedCache(FileSource $source): bool
{
    return (new ReflectionClass($source))->hasProperty('decodedContents');
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
    #[Override]
    public function openStream()
    {
        $this->openedStreams++;

        return (new ContentFileSource($this->contents))->openStream();
    }

    /**
     * Return no original filename.
     */
    #[Override]
    public function originalFilename(): ?string
    {
        return null;
    }

    /**
     * Return no client MIME hint.
     */
    #[Override]
    public function clientMimeType(): ?string
    {
        return null;
    }
}
