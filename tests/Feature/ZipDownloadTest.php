<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Exceptions\FileNotFound;
use Mattmy\FileMagic\Exceptions\InvalidFileName;
use Mattmy\FileMagic\Exceptions\ZipCreationUnavailable;
use Mattmy\FileMagic\Exceptions\ZipLimitExceeded;
use Mattmy\FileMagic\Facades\FileMagic;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('reports ZIP creation unavailable when the extension is missing', function (): void {
    if (\extension_loaded('zip')) {
        $this->markTestSkipped('The PHP zip extension is enabled.');
    }

    $file = FileMagic::fromContent('contents')->store();

    FileMagic::find($file)->downloadZip();
})->throws(ZipCreationUnavailable::class);

it('downloads resolved files as a named ZIP in query order', function (): void {
    $first = FileMagic::fromContent('first', 'report.txt')->store();
    $second = FileMagic::fromContent('second', 'report.txt')->store();

    $response = FileMagic::find([$second->uuid, $first->id])->downloadZip('project-files');
    $archivePath = $response->getFile()->getPathname();
    $archive = new ZipArchive;

    expect($response->headers->get('Content-Type'))->toBe('application/zip')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('project-files.zip')
        ->and($archive->open($archivePath))->toBeTrue()
        ->and($archive->numFiles)->toBe(2)
        ->and($archive->getNameIndex(0))->toBe('report.txt')
        ->and($archive->getNameIndex(1))->toBe('report (2).txt')
        ->and($archive->getFromIndex(0))->toBe('second')
        ->and($archive->getFromIndex(1))->toBe('first');

    $archive->close();

    \ob_start();
    $response->sendContent();
    \ob_end_clean();

    expect(\is_file($archivePath))->toBeFalse();
});

it('creates a safe default ZIP download name', function (): void {
    $file = FileMagic::fromContent('contents', 'document.txt')->store();
    $response = FileMagic::find($file)->downloadZip();
    $archivePath = $response->getFile()->getPathname();
    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toMatch('/attachment; filename=files-[a-f0-9]{16}\.zip/');

    \ob_start();
    $response->sendContent();
    \ob_end_clean();

    expect(\is_file($archivePath))->toBeFalse();
});

it('does not duplicate a supplied ZIP extension', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $response = FileMagic::find($file)->downloadZip('documents.ZIP');
    $archivePath = $response->getFile()->getPathname();

    expect($response->headers->get('Content-Disposition'))
        ->toContain('documents.zip')
        ->not->toContain('.zip.zip');

    \ob_start();
    $response->sendContent();
    \ob_end_clean();

    expect(\is_file($archivePath))->toBeFalse();
});

it('removes source paths from archive entry names', function (): void {
    $file = FileMagic::fromContent('contents', 'document.txt')->store();
    $file->forceFill(['original_filename' => '../../private/document.txt'])->save();
    $response = FileMagic::find($file)->downloadZip('safe-entries');
    $archivePath = $response->getFile()->getPathname();
    $archive = new ZipArchive;

    expect($archive->open($archivePath))->toBeTrue()
        ->and($archive->numFiles)->toBe(1)
        ->and($archive->getNameIndex(0))->toBe('document.txt');

    $archive->close();

    \ob_start();
    $response->sendContent();
    \ob_end_clean();
});

it('rejects unsafe ZIP download names', function (): void {
    $file = FileMagic::fromContent('contents')->store();

    FileMagic::find($file)->downloadZip('../documents');
})->throws(InvalidFileName::class);

it('rejects an empty ZIP query', function (): void {
    FileMagic::find([])->downloadZip();
})->throws(FileNotFound::class);

it('rejects ZIP downloads over the configured file limit', function (): void {
    \config()->set('file-magic.zip.max_files', 1);
    $first = FileMagic::fromContent('first')->store();
    $second = FileMagic::fromContent('second')->store();

    FileMagic::find([$first, $second])->downloadZip();
})->throws(ZipLimitExceeded::class);

it('rejects ZIP downloads over the configured metadata size limit', function (): void {
    \config()->set('file-magic.zip.max_size', 5);
    $file = FileMagic::fromContent('contents')->store();

    FileMagic::find($file)->downloadZip();
})->throws(ZipLimitExceeded::class);

it('enforces the actual streamed size when stored metadata is stale', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    $file->forceFill(['size' => 1])->save();
    \config()->set('file-magic.zip.max_size', 5);

    FileMagic::find($file)->downloadZip();
})->throws(ZipLimitExceeded::class);

it('fails the entire ZIP download when a physical file is missing', function (): void {
    $file = FileMagic::fromContent('contents')->store();
    Storage::disk('testing')->delete($file->path);

    FileMagic::find($file)->downloadZip();
})->throws(FileNotFound::class);
