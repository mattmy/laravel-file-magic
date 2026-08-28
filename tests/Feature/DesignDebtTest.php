<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Exceptions\InvalidStoredFileModel;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\Tests\Fixtures\CompatibleStoredFile;

it('does not use container helper calls in production classes', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../src'),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo === false || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = \file_get_contents($file->getPathname());

        if ($contents === false) {
            throw new RuntimeException('The production source could not be read.');
        }

        expect($contents)
            ->not->toMatch('/\\bapp\\s*\\(/')
            ->not->toMatch('/(?<!function )(?<!->)(?<!::)\\bresolve\\s*\\(/');
    }
});

it('rejects a configured model whose table does not match package configuration before storage', function (): void {
    config()->set('file-magic.model', CompatibleStoredFile::class);
    config()->set('file-magic.table', 'custom_stored_files');

    try {
        expect(static fn () => FileMagic::fromContent('contents')->store())
            ->toThrow(InvalidStoredFileModel::class);
    } finally {
        config()->set('file-magic.model', StoredFile::class);
        config()->set('file-magic.table', 'stored_files');
    }
});

it('forwards an explicit temporary URL expiration from a stored file', function (): void {
    $expiration = now()->addMinute();
    $file = new StoredFile(['disk' => 'testing', 'path' => 'files/file.txt']);
    $adapter = Mockery::mock(FilesystemAdapter::class);

    Storage::shouldReceive('disk')
        ->once()
        ->with('testing')
        ->andReturn($adapter);
    $adapter->shouldReceive('temporaryUrl')
        ->once()
        ->with('files/file.txt', $expiration)
        ->andReturn('https://example.test/files/file.txt');

    expect($file->temporaryUrl($expiration))->toBe('https://example.test/files/file.txt');
});
