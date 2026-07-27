<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Exceptions\InvalidDocumentData;
use Mattmy\FileMagic\Facades\FileMagic;

beforeEach(function (): void {
    Storage::fake('testing');
});

it('stores UTF-8 text through PendingFile and FileQuery', function (): void {
    $contents = "第一行\n第二行";
    $file = FileMagic::text($contents)
        ->onDisk('testing')
        ->inDirectory('documents')
        ->named('notes')
        ->store();

    expect($file->path)->toBe('documents/notes.txt')
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->extension)->toBe('txt')
        ->and($file->contents())->toBe($contents)
        ->and(FileMagic::find($file->uuid)->contents())->toBe($contents);
});

it('stores formatted JSON with trusted metadata', function (): void {
    $file = FileMagic::json([
        'message' => '我是文字',
        'items' => ['第一筆', '第二筆'],
    ])
        ->named('messages')
        ->onCollision(CollisionPolicy::Overwrite)
        ->store();

    expect($file->mime_type)->toBe('application/json')
        ->and($file->extension)->toBe('json')
        ->and($file->contents())->toBe(<<<'JSON'
            {
                "message": "我是文字",
                "items": [
                    "第一筆",
                    "第二筆"
                ]
            }

            JSON);
});

it('stores associative CSV rows with a header and RFC 4180 escaping', function (): void {
    $file = FileMagic::csv([
        ['name' => '第一筆', 'content' => '你好, "世界"'],
        ['name' => '第二筆', 'content' => '一般文字'],
    ])->named('messages')->store();

    expect($file->mime_type)->toBe('text/csv')
        ->and($file->extension)->toBe('csv')
        ->and($file->contents())->toBe(
            "name,content\r\n第一筆,\"你好, \"\"世界\"\"\"\r\n第二筆,一般文字\r\n",
        );
});

it('stores list CSV rows without inventing a header', function (): void {
    $file = FileMagic::csv((static function (): Generator {
        yield ['第一筆', 1];
        yield ['第二筆', 2];
    })())->store();

    expect($file->contents())->toBe("第一筆,1\r\n第二筆,2\r\n");
});

it('stores empty text JSON and CSV documents', function (): void {
    $text = FileMagic::text('')->store();
    $json = FileMagic::json([])->store();
    $csv = FileMagic::csv([])->store();

    expect($text->contents())->toBe('')
        ->and($text->mime_type)->toBe('text/plain')
        ->and($json->contents())->toBe("[]\n")
        ->and($csv->contents())->toBe('')
        ->and($csv->mime_type)->toBe('text/csv');
});

it('rejects invalid document data', function (): void {
    $resource = \fopen('php://memory', 'w+b');

    if ($resource === false) {
        throw new RuntimeException('The invalid JSON fixture could not be opened.');
    }

    try {
        expect(fn () => FileMagic::text("\xB1\x31"))
            ->toThrow(InvalidDocumentData::class)
            ->and(fn () => FileMagic::json(['resource' => $resource]))
            ->toThrow(InvalidDocumentData::class)
            ->and(fn () => FileMagic::csv([
                ['name' => 'first'],
                ['content' => 'second'],
            ]))
            ->toThrow(InvalidDocumentData::class)
            ->and(fn () => FileMagic::csv([['invalid' => "\xB1\x31"]]))
            ->toThrow(InvalidDocumentData::class);
    } finally {
        \fclose($resource);
    }
});
