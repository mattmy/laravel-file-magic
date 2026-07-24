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
use Mattmy\FileMagic\Sources\ContentFileSource;
use Mattmy\FileMagic\Support\ImageProcessor;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('stores and reads an uploaded file', function (): void {
    $file = FileMagic::fromUpload(
        UploadedFile::fake()->createWithContent('manual.txt', 'package test'),
    )->named('manual')->store();

    expect($file)
        ->toBeInstanceOf(StoredFile::class)
        ->contents()->toBe('package test')
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->location_hash)->toBe(\hash('sha256', "testing\0files/manual.txt"));

    Storage::disk('testing')->assertExists($file->path);
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

    expect($file->mime_type)->toBe('image/png')
        ->and($size)->toBeArray()
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
        ->and(FileMagic::find($first)->one())->toBe($first)
        ->and(FileMagic::find([])->get()->isEmpty())->toBeTrue();
});
