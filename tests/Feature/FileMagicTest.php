<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Exceptions\InvalidStoragePath;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;

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
        ->and($file->mime_type)->toBe('text/plain');

    Storage::disk('testing')->assertExists($file->path);
});

it('rejects path traversal', function (): void {
    FileMagic::fromContent('unsafe')
        ->inDirectory('../private')
        ->store();
})->throws(InvalidStoragePath::class);

it('deletes multiple files in disk and database batches', function (): void {
    $first = FileMagic::fromContent('first')->named('first')->store();
    $second = FileMagic::fromContent('second')->named('second')->store();

    $files = FileMagic::find([$first->id, $second->uuid])->get();
    $deleted = $files->delete();

    expect($files)->toHaveCount(2)
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
    $ids = \array_map(
        static fn (StoredFile $file): int => $file->id,
        \iterator_to_array($files),
    );

    expect($ids)->toBe([$second->id, $first->id])
        ->and(FileMagic::find($first)->one())->toBe($first)
        ->and(FileMagic::find([])->get()->isEmpty())->toBeTrue();
});
