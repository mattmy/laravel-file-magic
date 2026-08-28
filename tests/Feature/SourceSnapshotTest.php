<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidFileSource;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Sources\PathFileSource;
use Mattmy\FileMagic\Support\FileInspector;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('stores exactly the bytes captured from a changing source', function (): void {
    $firstContents = 'first snapshot contents';
    $source = new ChangingSnapshotSource($firstContents, '<?php echo "replacement";');

    $file = pendingFile($source)->named('changing')->store();

    expect($source->openedStreams)->toBe(1)
        ->and(Storage::disk('testing')->get($file->path))->toBe($firstContents)
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->size)->toBe(\strlen($firstContents))
        ->and($file->checksum)->toBe(\hash('sha256', $firstContents));
});

it('stores a path snapshot when the original path changes before storage reads it', function (): void {
    $path = \tempnam(\sys_get_temp_dir(), 'file-magic-source-');

    if ($path === false) {
        throw new RuntimeException('The source fixture could not be created.');
    }

    \file_put_contents($path, 'captured path contents');

    $filesystem = Mockery::mock(Filesystem::class);
    $factory = Mockery::mock(FilesystemFactory::class);
    $storedContents = null;

    $this->app->instance(FilesystemFactory::class, $factory);
    $factory->shouldReceive('disk')->once()->with('testing')->andReturn($filesystem);
    $filesystem->shouldReceive('exists')->once()->with('files/path.txt')->andReturnFalse();
    $filesystem->shouldReceive('put')
        ->once()
        ->withArgs(function (string $storedPath, mixed $stream, array $options) use ($path, &$storedContents): bool {
            \file_put_contents($path, 'replacement path contents');
            $storedContents = \is_resource($stream) ? \stream_get_contents($stream) : null;

            return $storedPath === 'files/path.txt' && $options === ['visibility' => 'private'];
        })
        ->andReturnTrue();

    try {
        $file = pendingFile(new PathFileSource($path))->named('path')->store();

        expect($storedContents)->toBe('captured path contents')
            ->and($file->size)->toBe(22)
            ->and($file->checksum)->toBe(\hash('sha256', 'captured path contents'));
    } finally {
        (new Illuminate\Filesystem\Filesystem())->delete($path);
    }
});

it('rejects an oversized source before storage or database mutation', function (): void {
    $source = new ChangingSnapshotSource(\str_repeat('a', 9000), 'unused');

    expect(static fn () => pendingFile($source)->named('oversized')->maxSize(8)->store())
        ->toThrow(FileTooLarge::class);

    expect($source->openedStreams)->toBe(1)
        ->and(StoredFile::query()->count())->toBe(0);
    Storage::disk('testing')->assertDirectoryEmpty('files');
});

it('captures an original source once when unique collision retries a path', function (): void {
    Storage::disk('testing')->put('files/same.txt', 'existing');
    $source = new ChangingSnapshotSource('unique snapshot', 'replacement');

    $file = pendingFile($source)->named('same')->store();

    expect($source->openedStreams)->toBe(1)
        ->and(Storage::disk('testing')->get($file->path))->toBe('unique snapshot')
        ->and($file->path)->not->toBe('files/same.txt');
});

it('processes images from a captured source without reopening the original', function (): void {
    $contents = \base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    if ($contents === false) {
        throw new RuntimeException('The image fixture could not be decoded.');
    }

    $source = new ChangingSnapshotSource($contents, 'replacement');
    $file = pendingFile($source)->named('image')->resizeImage(maxWidth: 1, quality: 80)->store();
    $storedContents = Storage::disk('testing')->get($file->path);

    expect($source->openedStreams)->toBe(1)
        ->and($file->mime_type)->toBe('image/png')
        ->and($file->size)->toBe(\strlen($storedContents))
        ->and($file->checksum)->toBe(\hash('sha256', $storedContents));
});

it('opens independent snapshot readers and releases its temporary resource safely', function (): void {
    $snapshot = (new FileInspector())->capture(
        new ContentFileSource('snapshot contents', 'snapshot.txt'),
        'sha256',
        100,
    );
    $first = $snapshot->openStream();
    $second = $snapshot->openStream();

    try {
        expect(\stream_get_contents($first))->toBe('snapshot contents');

        \fclose($first);
        $first = null;

        expect(\stream_get_contents($second))->toBe('snapshot contents');
    } finally {
        if (\is_resource($first)) {
            \fclose($first);
        }

        \fclose($second);
        $snapshot->release();
        $snapshot->release();
    }

    expect(static fn () => $snapshot->openStream())->toThrow(InvalidFileSource::class);
});

final class ChangingSnapshotSource implements FileSource
{
    public int $openedStreams = 0;

    /**
     * Create a source whose content changes after the first read.
     */
    public function __construct(
        private readonly string $firstContents,
        private readonly string $laterContents,
    ) {}

    /**
     * Open the next source version.
     *
     * @return resource
     */
    #[Override]
    public function openStream()
    {
        $this->openedStreams++;

        return (new ContentFileSource(
            $this->openedStreams === 1 ? $this->firstContents : $this->laterContents,
            'changing.txt',
        ))->openStream();
    }

    /**
     * Return the stable original filename.
     */
    #[Override]
    public function originalFilename(): string
    {
        return 'changing.txt';
    }

    /**
     * Return no client MIME hint.
     */
    #[Override]
    public function clientMimeType(): null
    {
        return null;
    }
}
