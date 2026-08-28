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
use Mattmy\FileMagic\Support\RemoteUrlGuard;
use Mattmy\FileMagic\Tests\Fixtures\FakeHostResolver;

beforeEach(function (): void {
    Storage::fake('testing');
    Http::preventStrayRequests();

    $this->hostResolver = new FakeHostResolver([
        'downloads.example.com' => ['93.184.216.34'],
        'redirect.example.com' => ['93.184.216.35'],
        'private.example.com' => ['127.0.0.1'],
        'mixed.example.com' => ['93.184.216.34', '100.64.0.1'],
    ]);
    $this->application()->instance(HostResolver::class, $this->hostResolver);
});

it('rejects non-global IPv4 addresses', function (string $address): void {
    $guard = new RemoteUrlGuard(new FakeHostResolver([
        'special.example.com' => [$address],
    ]));

    expect(
        static fn () => $guard->validate('https://special.example.com/file.txt', new RemoteFileOptions()),
    )->toThrow(RemoteAccessDenied::class);

    Http::assertNothingSent();
})->with([
    'shared range start' => '100.64.0.0',
    'shared range representative' => '100.100.0.1',
    'shared range end' => '100.127.255.255',
    'benchmarking range start' => '198.18.0.0',
    'benchmarking range representative' => '198.18.1.1',
    'benchmarking range end' => '198.19.255.255',
    'unspecified' => '0.0.0.0',
    'loopback' => '127.0.0.1',
    'private' => '10.0.0.1',
    'link local' => '169.254.1.1',
    'documentation' => '192.0.2.1',
    'multicast' => '224.0.0.1',
    'reserved' => '240.0.0.1',
    'limited broadcast' => '255.255.255.255',
]);

it('allows IPv4 addresses outside the shared and benchmarking ranges', function (string $address): void {
    $guard = new RemoteUrlGuard(new FakeHostResolver([
        'public.example.com' => [$address],
    ]));

    $endpoint = $guard->validate('https://public.example.com/file.txt', new RemoteFileOptions());

    expect($endpoint->ipAddress)->toBe($address);
})->with([
    'before shared range' => '100.63.255.255',
    'after shared range' => '100.128.0.0',
    'before benchmarking range' => '198.17.255.255',
    'after benchmarking range' => '198.20.0.0',
    'ordinary public address' => '93.184.216.34',
]);

it('rejects non-global IPv6 addresses including every IPv4-mapped address', function (string $address): void {
    $guard = new RemoteUrlGuard(new FakeHostResolver([
        'special.example.com' => [$address],
    ]));

    expect(
        static fn () => $guard->validate('https://special.example.com/file.txt', new RemoteFileOptions()),
    )->toThrow(RemoteAccessDenied::class);

    Http::assertNothingSent();
})->with([
    'unspecified' => '::',
    'loopback' => '::1',
    'mapped loopback' => '::ffff:127.0.0.1',
    'mapped private' => '::ffff:10.0.0.1',
    'mapped shared' => '::ffff:100.64.0.1',
    'mapped public' => '::ffff:8.8.8.8',
    'unique local' => 'fc00::1',
    'link local' => 'fe80::1',
    'documentation' => '2001:db8::1',
    'multicast' => 'ff00::1',
]);

it('allows ordinary public IPv6 addresses', function (): void {
    $guard = new RemoteUrlGuard(new FakeHostResolver([
        'public.example.com' => ['2001:4860:4860::8888'],
    ]));

    $endpoint = $guard->validate('https://public.example.com/file.txt', new RemoteFileOptions());

    expect($endpoint->ipAddress)->toBe('2001:4860:4860::8888');
});

it('canonicalizes a trailing-dot hostname before resolving and pinning it', function (): void {
    $endpoint = $this->application()->make(RemoteUrlGuard::class)->validate(
        'https://DOWNLOADS.EXAMPLE.COM.:8443/file.pdf?token=value',
        new RemoteFileOptions(allowedPorts: [8443]),
    );

    expect($this->hostResolver->resolvedHosts)->toBe(['downloads.example.com'])
        ->and($endpoint->url)->toBe('https://downloads.example.com:8443/file.pdf?token=value')
        ->and($endpoint->host)->toBe('downloads.example.com')
        ->and($endpoint->curlResolveEntry())->toBe('downloads.example.com:8443:93.184.216.34');
});

it('allows special-purpose addresses only for exact private host allowlist matches', function (): void {
    $guard = new RemoteUrlGuard(new FakeHostResolver([
        'private.example.com' => ['100.64.0.1'],
        'nested.private.example.com' => ['198.18.0.1'],
    ]));

    $endpoint = $guard->validate(
        'https://PRIVATE.EXAMPLE.COM./file.txt',
        new RemoteFileOptions(allowedPrivateHosts: ['private.example.com']),
    );

    expect($endpoint->ipAddress)->toBe('100.64.0.1')
        ->and(
            static fn () => $guard->validate(
                'https://nested.private.example.com/file.txt',
                new RemoteFileOptions(allowedPrivateHosts: ['private.example.com']),
            ),
        )->toThrow(RemoteAccessDenied::class);
});

it('keeps global address policy enabled when HTTP or TLS verification is explicitly changed', function (): void {
    $guard = new RemoteUrlGuard(new FakeHostResolver([
        'special.example.com' => ['100.64.0.1'],
    ]));

    expect(
        static fn () => $guard->validate(
            'http://special.example.com/file.txt',
            new RemoteFileOptions(allowHttp: true),
        ),
    )->toThrow(RemoteAccessDenied::class)
        ->and(
            static fn () => $guard->validate(
                'https://special.example.com/file.txt',
                RemoteFileOptions::withoutTlsVerification(),
            ),
        )->toThrow(RemoteAccessDenied::class);

    Http::assertNothingSent();
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

it('uses the canonical trailing-dot hostname for the HTTP request', function (): void {
    Http::fake([
        'https://downloads.example.com/dot.txt?token=value' => Http::response('canonical'),
    ]);

    $file = FileMagic::fromUrl('https://DOWNLOADS.EXAMPLE.COM./dot.txt?token=value')->store();

    expect($file->contents())->toBe('canonical')
        ->and($this->hostResolver->resolvedHosts)->toBe(['downloads.example.com']);
    Http::assertSent(
        static fn (Request $request): bool => $request->url() === 'https://downloads.example.com/dot.txt?token=value',
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

it('rejects a host when any advertised address is non-global', function (): void {
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

it('revalidates and canonicalizes every redirect endpoint', function (): void {
    Http::fake([
        'https://redirect.example.com/start' => Http::response(
            '',
            302,
            ['Location' => 'https://DOWNLOADS.EXAMPLE.COM./final.txt'],
        ),
        'https://downloads.example.com/final.txt' => Http::response('redirected'),
    ]);

    $file = FileMagic::fromUrl('https://REDIRECT.EXAMPLE.COM./start')->store();

    expect($file->contents())->toBe('redirected')
        ->and($file->original_filename)->toBe('final.txt')
        ->and($this->hostResolver->resolvedHosts)->toBe([
            'redirect.example.com',
            'downloads.example.com',
        ]);
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
