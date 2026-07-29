<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Contracts\HostResolver;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Exceptions\DisallowedMimeType;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidRemoteOptions;
use Mattmy\FileMagic\Exceptions\InvalidRemoteUrl;
use Mattmy\FileMagic\Exceptions\RemoteAccessDenied;
use Mattmy\FileMagic\Exceptions\RemoteDownloadUnavailable;
use Mattmy\FileMagic\Facades\FileMagic;
use Mattmy\FileMagic\Tests\Fixtures\FakeHostResolver;

beforeEach(function (): void {
    Storage::fake('testing');
    Http::preventStrayRequests();

    $this->app->instance(HostResolver::class, new FakeHostResolver([
        'downloads.example.com' => ['93.184.216.34'],
        'redirect.example.com' => ['93.184.216.35'],
        'private.example.com' => ['127.0.0.1'],
        'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
    ]));
});

it('keeps local sources available and reports remote downloads unavailable without curl', function (): void {
    if (\extension_loaded('curl') === true) {
        $this->markTestSkipped('The PHP cURL extension is enabled.');
    }

    $pendingRemoteFile = FileMagic::fromUrl('https://downloads.example.com/manual.txt');
    $localFile = FileMagic::fromContent('local document', 'local.txt')
        ->onDisk('testing')
        ->store();

    expect($localFile->contents())->toBe('local document')
        ->and(static fn () => $pendingRemoteFile->store())
        ->toThrow(RemoteDownloadUnavailable::class);

    Http::assertNothingSent();
});

it('downloads a remote file once through the complete pending file API', function (): void {
    Http::fake([
        'https://downloads.example.com/manual.txt' => Http::response(
            'remote document',
            200,
            [
                'Content-Disposition' => 'attachment; filename="manual.txt"',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ],
        ),
    ]);

    $file = FileMagic::fromUrl('https://downloads.example.com/manual.txt')
        ->onDisk('testing')
        ->inDirectory('remote')
        ->named('downloaded-manual')
        ->withMetadata(['source' => 'remote'])
        ->maxSize(1024)
        ->allowMimeTypes(['text/plain'])
        ->store();

    expect($file->contents())->toBe('remote document')
        ->and($file->original_filename)->toBe('manual.txt')
        ->and($file->mime_type)->toBe('text/plain')
        ->and($file->metadata)->toBe(['source' => 'remote']);

    Storage::disk('testing')->assertExists('remote/downloaded-manual.txt');
    Http::assertSentCount(1);
    Http::assertSent(
        static fn (Request $request): bool => $request->method() === 'GET' &&
            $request->url() === 'https://downloads.example.com/manual.txt' &&
            $request->hasHeader('Accept-Encoding', 'identity'),
    );
});

it('rejects HTTP unless it is explicitly enabled', function (): void {
    Http::fake([
        'http://downloads.example.com/manual.txt' => Http::response('document'),
    ]);

    expect(
        static fn () => FileMagic::fromUrl('http://downloads.example.com/manual.txt')->store(),
    )->toThrow(RemoteAccessDenied::class);

    $file = FileMagic::fromUrl(
        'http://downloads.example.com/manual.txt',
        new RemoteFileOptions(allowHttp: true),
    )->store();

    expect($file->contents())->toBe('document');
    Http::assertSentCount(1);
});

it('rejects private networks unless the exact host is explicitly allowed', function (): void {
    Http::fake([
        'https://private.example.com/file.txt' => Http::response('private document'),
    ]);

    expect(
        static fn () => FileMagic::fromUrl('https://private.example.com/file.txt')->store(),
    )->toThrow(RemoteAccessDenied::class);

    $file = FileMagic::fromUrl(
        'https://private.example.com/file.txt',
        new RemoteFileOptions(allowedPrivateHosts: ['private.example.com']),
    )->store();

    expect($file->contents())->toBe('private document');
    Http::assertSentCount(1);
});

it('rejects a host when any advertised address is non-public', function (): void {
    expect(
        static fn () => FileMagic::fromUrl('https://mixed.example.com/file.txt')->store(),
    )->toThrow(RemoteAccessDenied::class);

    Http::assertNothingSent();
});

it('enforces an exact public hostname allowlist', function (): void {
    expect(
        static fn () => FileMagic::fromUrl(
            'https://downloads.example.com/file.txt',
            new RemoteFileOptions(allowedHosts: ['redirect.example.com']),
        )->store(),
    )->toThrow(RemoteAccessDenied::class);

    Http::assertNothingSent();
});

it('rejects HTML by default and stores it only after explicit opt in', function (): void {
    Http::fake([
        'https://downloads.example.com/page' => Http::response(
            '<!doctype html><html><body>Hello</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(
        static fn () => FileMagic::fromUrl('https://downloads.example.com/page')->store(),
    )->toThrow(DisallowedMimeType::class);

    $file = FileMagic::fromUrl(
        'https://downloads.example.com/page',
        new RemoteFileOptions(allowHtml: true),
    )->named('page')->store();

    expect($file->mime_type)->toBe('text/html')
        ->and($file->extension)->toBe('html')
        ->and($file->contents())->toContain('<body>Hello</body>');
});

it('revalidates and follows relative redirects within the configured limit', function (): void {
    Http::fake([
        'https://redirect.example.com/start' => Http::response(
            '',
            302,
            ['Location' => 'https://downloads.example.com/final.txt'],
        ),
        'https://downloads.example.com/final.txt' => Http::response('redirected'),
    ]);

    $file = FileMagic::fromUrl('https://redirect.example.com/start')->store();

    expect($file->contents())->toBe('redirected')
        ->and($file->original_filename)->toBe('final.txt');
    Http::assertSentCount(2);
});

it('enforces the actual streamed byte limit', function (): void {
    Http::fake([
        'https://downloads.example.com/large.txt' => Http::response(
            'more than five bytes',
            200,
            ['Content-Length' => '20'],
        ),
    ]);

    expect(
        static fn () => FileMagic::fromUrl('https://downloads.example.com/large.txt')
            ->maxSize(5)
            ->store(),
    )->toThrow(FileTooLarge::class);
});

it('cleans the temporary download after successful storage', function (): void {
    Http::fake([
        'https://downloads.example.com/temporary.txt' => Http::response('temporary'),
    ]);
    $before = \glob(\sys_get_temp_dir() . '/file-magic-url-*') ?: [];

    FileMagic::fromUrl('https://downloads.example.com/temporary.txt')->store();

    $after = \glob(\sys_get_temp_dir() . '/file-magic-url-*') ?: [];

    expect($after)->toBe($before);
});

it('validates URL syntax schemes credentials fragments hosts and ports', function (
    string $url,
    string $exception,
): void {
    expect(static fn () => FileMagic::fromUrl($url)->store())->toThrow($exception);
})->with([
    'relative URL' => ['downloads.example.com/file.txt', InvalidRemoteUrl::class],
    'credentials' => ['https://user:secret@downloads.example.com/file.txt', InvalidRemoteUrl::class],
    'fragment' => ['https://downloads.example.com/file.txt#section', InvalidRemoteUrl::class],
    'unsupported scheme' => ['ftp://downloads.example.com/file.txt', RemoteAccessDenied::class],
    'non-standard port' => ['https://downloads.example.com:8443/file.txt', RemoteAccessDenied::class],
]);

it('validates and normalizes remote options', function (): void {
    $options = new RemoteFileOptions(
        allowedHosts: ['DOWNLOADS.EXAMPLE.COM.', 'downloads.example.com'],
        allowedPorts: [443, 80, 443],
        allowedPrivateHosts: ['PRIVATE.EXAMPLE.COM'],
    );
    $withoutTls = RemoteFileOptions::withoutTlsVerification();

    expect($options->allowedHosts)->toBe(['downloads.example.com'])
        ->and($options->allowedPorts)->toBe([443, 80])
        ->and($options->allowedPrivateHosts)->toBe(['private.example.com'])
        ->and($withoutTls->verifyTls)->toBeFalse()
        ->and($withoutTls->allowHttp)->toBeFalse();
});

it('rejects unsafe remote option values', function (Closure $create): void {
    expect($create)->toThrow(InvalidRemoteOptions::class);
})->with([
    'empty ports' => [static fn () => new RemoteFileOptions(allowedPorts: [])],
    'invalid port' => [static fn () => new RemoteFileOptions(allowedPorts: [0])],
    'zero connect timeout' => [static fn () => new RemoteFileOptions(connectTimeoutSeconds: 0)],
    'short total timeout' => [static fn () => new RemoteFileOptions(
        connectTimeoutSeconds: 5,
        timeoutSeconds: 4,
    )],
    'excessive redirects' => [static fn () => new RemoteFileOptions(maxRedirects: 11)],
]);
