<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Data\ImageOptions;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Exceptions\InvalidStoragePath;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\PendingFile;
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Sources\GeneratedDocumentSource;
use Mattmy\FileMagic\Support\ImageProcessor;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('stores and reads an uploaded file', function (): void {
    $file = FileMagic::fromUpload(
        UploadedFile::fake()->createWithContent('manual.txt', 'package test'),
    )->named('manual')->store();

    expect($file->contents())
        ->toBe('package test')
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->location_hash)->toBe(\hash('sha256', "testing\0files/manual.txt"));

    Storage::disk('testing')->assertExists($file->path);
});

it('delegates generated content without a trusted MIME type to ordinary inspection', function (
    Closure $generatedFactory,
    Closure $contentFactory,
): void {
    $generated = $generatedFactory();
    $content = $contentFactory();

    \assert($generated instanceof PendingFile);
    \assert($content instanceof PendingFile);

    $generatedFile = $generated->named('generated')->store();
    $contentFile = $content->named('content')->store();

    expect($generated->source())->toBeInstanceOf(ContentFileSource::class)
        ->and($generatedFile->original_filename)->toBe($contentFile->original_filename)
        ->and($generatedFile->mime_type)->toBe($contentFile->mime_type)
        ->and($generatedFile->extension)->toBe($contentFile->extension)
        ->and($generatedFile->checksum)->toBe($contentFile->checksum)
        ->and($generatedFile->contents())->toBe($contentFile->contents());
})->with([
    'omitted optional arguments' => [
        static fn (): PendingFile => FileMagic::fromGeneratedContent('DXF-like contents'),
        static fn (): PendingFile => FileMagic::fromContent('DXF-like contents'),
    ],
    'original filename only' => [
        static fn (): PendingFile => FileMagic::fromGeneratedContent('DXF-like contents', 'drawing.dxf'),
        static fn (): PendingFile => FileMagic::fromContent('DXF-like contents', 'drawing.dxf'),
    ],
    'explicit null MIME type' => [
        static fn (): PendingFile => FileMagic::fromGeneratedContent('DXF-like contents', 'drawing.dxf', null),
        static fn (): PendingFile => FileMagic::fromContent('DXF-like contents', 'drawing.dxf'),
    ],
]);

it('stores generated content with a recognized trusted MIME type', function (): void {
    $contents = "0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n";
    $pending = FileMagic::fromGeneratedContent(
        $contents,
        'imports/drawing.dxf',
        'image/vnd.dxf',
    );
    $source = $pending->source();

    if ($source instanceof GeneratedDocumentSource === false) {
        throw new RuntimeException('The generated content source must preserve trusted MIME metadata.');
    }

    $file = $pending
        ->named('drawing')
        ->allowMimeTypes(['image/vnd.dxf'])
        ->store();

    expect($source->originalFilename())->toBe('imports/drawing.dxf')
        ->and($source->clientMimeType())->toBeNull()
        ->and($source->trustedMimeType())->toBe('image/vnd.dxf')
        ->and($file->path)->toBe('files/drawing.dxf')
        ->and($file->original_filename)->toBe('drawing.dxf')
        ->and($file->mime_type)->toBe('image/vnd.dxf')
        ->and($file->extension)->toBe('dxf')
        ->and($file->size)->toBe(\strlen($contents))
        ->and($file->checksum)->toBe(\hash('sha256', $contents))
        ->and($file->contents())->toBe($contents);
});

it('keeps unmapped generated MIME types as untrusted client hints', function (string $mimeType): void {
    $contents = 'DXF-like contents';
    $generated = FileMagic::fromGeneratedContent($contents, 'drawing.dxf', $mimeType);
    $content = FileMagic::fromContent($contents, 'drawing.dxf', $mimeType);

    expect($generated->source())->toBeInstanceOf(ContentFileSource::class)
        ->and($generated->source()->clientMimeType())->toBe($mimeType);

    $generatedFile = $generated->named('generated')->store();
    $contentFile = $content->named('content')->store();

    expect($generatedFile->mime_type)->toBe($contentFile->mime_type)
        ->and($generatedFile->extension)->toBe($contentFile->extension)
        ->and($generatedFile->mime_type)->not->toBe('image/vnd.dxf')
        ->and($generatedFile->extension)->not->toBe('dxf');
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'meaningless text' => 'not-a-mime-type',
    'unmapped DXF MIME' => 'application/dxf',
]);

it('preserves the ordinary unmapped-extension fallback', function (): void {
    $file = FileMagic::fromGeneratedContent('')->named('empty')->store();

    expect($file->extension)->toBe('bin')
        ->and($file->contents())->toBe('');
});

it('does not trust a MIME hint supplied to ordinary content', function (): void {
    $file = FileMagic::fromContent(
        'not actually a DXF document',
        'drawing.dxf',
        'image/vnd.dxf',
    )->named('ordinary')->store();

    expect($file->mime_type)->not->toBe('image/vnd.dxf')
        ->and($file->extension)->not->toBe('dxf');
});

it('resizes an image with Intervention Image 4', function (): void {
    $contents = \base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    if ($contents === false) {
        throw new RuntimeException('The image fixture could not be decoded.');
    }

    $file = FileMagic::fromContent($contents, 'pixel.png', 'image/png')
        ->resizeImage(maxWidth: 1, quality: 80)
        ->store();
    $size = \getimagesizefromstring($file->contents());

    if ($size === false) {
        throw new RuntimeException('The image fixture could not be inspected.');
    }

    expect($file->mime_type)->toBe('image/png')
        ->and($size[0])->toBe(1)
        ->and($size[1])->toBe(1);
});

it('stores non-images unchanged when image resizing is requested', function (): void {
    $contents = 'FileMagic document';
    $file = FileMagic::fromContent($contents, 'document.txt', 'text/plain')
        ->resizeImage(maxWidth: 800, quality: 75)
        ->store();

    expect($file->contents())->toBe($contents)
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->extension)->toBe('txt')
        ->and($file->checksum)->toBe(\hash('sha256', $contents));
});

it('keeps sources that Intervention Image cannot decode', function (): void {
    $source = new ContentFileSource('invalid png', 'broken.png', 'image/png');
    $result = (new ImageProcessor())->process(
        $source,
        'image/png',
        new ImageOptions(maxWidth: 800, quality: 75),
    );

    expect($result)->toBe($source);
});

it('rejects path traversal', function (): void {
    FileMagic::fromContent('unsafe')
        ->inDirectory('../private')
        ->store();
})->throws(InvalidStoragePath::class);

it('deletes multiple files in disk and database batches', function (): void {
    $first = FileMagic::fromContent('first')->named('first')->store();
    $second = FileMagic::fromContent('second')->named('second')->store();

    $query = FileMagic::find([$first->id, $second->uuid]);
    $files = $query->get();
    $deleted = $query->delete();

    expect($files::class)->toBe(Collection::class)
        ->and($files)
        ->toHaveCount(2)
        ->and($deleted)->toBe(2)
        ->and(StoredFile::query()->count())->toBe(0);
});

it('updates the record when overwriting a path', function (): void {
    $file = FileMagic::fromContent('old')->named('same')->store();
    $replacement = FileMagic::fromContent('new')
        ->named('same')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store();

    expect($replacement->id)->toBe($file->id)
        ->and($replacement->contents())->toBe('new')
        ->and(StoredFile::query()->count())->toBe(1);
});

it('accepts variadic array collection and model targets in order', function (): void {
    $first = FileMagic::fromContent('first')->store();
    $second = FileMagic::fromContent('second')->store();

    $files = FileMagic::find(collect([$second->uuid, $first->id, $second]))->get();
    $ids = $files->map(
        static fn (StoredFile $file): int => $file->id,
    )->all();

    expect($files::class)->toBe(Collection::class)
        ->and($ids)->toBe([$second->id, $first->id])
        ->and(FileMagic::find($first)->one()?->getKey())->toBe($first->getKey())
        ->and(FileMagic::find([])->get()->isEmpty())->toBeTrue();
});
