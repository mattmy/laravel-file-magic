<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Mattmy\FileMagic\Data\RemoteDownload;
use Mattmy\FileMagic\Data\RemoteEndpoint;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Exceptions\FileMagicException;
use Mattmy\FileMagic\Exceptions\FileTooLarge;
use Mattmy\FileMagic\Exceptions\InvalidRemoteUrl;
use Mattmy\FileMagic\Exceptions\RemoteDownloadFailed;
use Mattmy\FileMagic\Exceptions\RemoteDownloadUnavailable;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class RemoteDownloader
{
    private const string TEMPORARY_FILE_PREFIX = 'file-magic-url-';

    /**
     * Create the remote streaming downloader.
     */
    public function __construct(
        private HttpFactory $http,
        private RemoteUrlGuard $urls,
    ) {}

    /**
     * Download one validated URL to a bounded local temporary file.
     */
    public function download(
        string $url,
        RemoteFileOptions $options,
        int $maximumBytes,
    ): RemoteDownload {
        $this->ensureCurlIsAvailable();

        $path = \tempnam(\sys_get_temp_dir(), self::TEMPORARY_FILE_PREFIX);

        if ($path === false) {
            throw new RemoteDownloadFailed('A temporary remote file could not be created.');
        }

        try {
            return $this->follow($url, $path, $options, $maximumBytes);
        } catch (Throwable $exception) {
            @\unlink($path);

            if ($exception instanceof FileMagicException) {
                throw $exception;
            }

            throw new RemoteDownloadFailed(
                'The remote file could not be downloaded.',
                previous: $exception,
            );
        }
    }

    /**
     * Ensure the optional cURL transport is available before using it.
     */
    private function ensureCurlIsAvailable(): void
    {
        if (\extension_loaded('curl') === false) {
            throw new RemoteDownloadUnavailable(
                'Remote URL imports require the PHP cURL extension.',
            );
        }
    }

    /**
     * Follow a bounded redirect chain while revalidating every endpoint.
     */
    private function follow(
        string $url,
        string $path,
        RemoteFileOptions $options,
        int $maximumBytes,
    ): RemoteDownload {
        for ($redirects = 0; $redirects <= $options->maxRedirects; $redirects++) {
            $endpoint = $this->urls->validate($url, $options);
            $response = $this->request($endpoint, $path, $options, $maximumBytes);

            if ($this->isRedirect($response)) {
                if ($redirects === $options->maxRedirects) {
                    throw new RemoteDownloadFailed('The remote redirect limit was exceeded.');
                }

                $url = $this->redirectUrl($url, $response);

                continue;
            }

            if ($response->successful() === false) {
                throw new RemoteDownloadFailed(
                    "The remote server returned HTTP status {$response->status()}.",
                );
            }

            return new RemoteDownload(
                $path,
                $this->originalFilename($response) ?? $this->urlFilename($url),
                $this->clientMimeType($response),
            );
        }

        throw new RemoteDownloadFailed('The remote redirect limit was exceeded.');
    }

    /**
     * Execute one non-redirecting request pinned to its validated IP address.
     */
    private function request(
        RemoteEndpoint $endpoint,
        string $path,
        RemoteFileOptions $options,
        int $maximumBytes,
    ): Response {
        $resource = \fopen($path, 'w+b');

        if ($resource === false) {
            throw new RemoteDownloadFailed('The remote temporary file could not be opened.');
        }

        $sink = new LimitedWriteStream($resource, $maximumBytes);

        try {
            $response = $this->http
                ->withHeaders([
                    'Accept' => '*/*',
                    'Accept-Encoding' => 'identity',
                ])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => [$endpoint->curlResolveEntry()],
                    ],
                    'on_headers' => $this->headerLimit($maximumBytes),
                    'proxy' => '',
                    'sink' => $sink,
                    'verify' => $options->verifyTls,
                ])
                ->connectTimeout($options->connectTimeoutSeconds)
                ->timeout($options->timeoutSeconds)
                ->get($endpoint->url);
            $statistics = \fstat($resource);

            if (
                \is_array($statistics) &&
                $statistics['size'] === 0 &&
                $response->body() !== ''
            ) {
                $sink->write($response->body());
            }

            return $response;
        } catch (FileTooLarge $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            $fileMagicException = $this->previousFileMagicException($exception);

            if ($fileMagicException instanceof FileMagicException) {
                throw $fileMagicException;
            }

            throw new RemoteDownloadFailed(
                'The remote server could not be reached securely.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $fileMagicException = $this->previousFileMagicException($exception);

            if ($fileMagicException instanceof FileMagicException) {
                throw $fileMagicException;
            }

            throw $exception;
        } finally {
            $sink->close();
        }
    }

    /**
     * Reject a declared response size before downloading its body.
     *
     * @return \Closure(ResponseInterface): void
     */
    private function headerLimit(int $maximumBytes): \Closure
    {
        return static function (ResponseInterface $response) use ($maximumBytes): void {
            $length = $response->getHeaderLine('Content-Length');

            if (
                $length !== '' &&
                \ctype_digit($length) &&
                (int) $length > $maximumBytes
            ) {
                throw new FileTooLarge(
                    "The remote file exceeds the {$maximumBytes} byte limit.",
                );
            }
        };
    }

    /**
     * Determine whether a response requests another validated location.
     */
    private function isRedirect(Response $response): bool
    {
        return \in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    /**
     * Resolve an absolute or relative redirect location.
     */
    private function redirectUrl(string $currentUrl, Response $response): string
    {
        $location = \trim($response->header('Location'));

        if ($location === '') {
            throw new RemoteDownloadFailed('The remote redirect did not provide a location.');
        }

        try {
            return (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        } catch (Throwable $exception) {
            throw new InvalidRemoteUrl(
                'The remote redirect location is invalid.',
                previous: $exception,
            );
        }
    }

    /**
     * Extract an untrusted filename hint from Content-Disposition.
     */
    private function originalFilename(Response $response): ?string
    {
        $disposition = $response->header('Content-Disposition');

        if (
            \preg_match('/filename\\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches) !== 1
        ) {
            return null;
        }

        $filename = \rawurldecode(\trim($matches[1]));

        return $filename === '' ? null : $filename;
    }

    /**
     * Extract an untrusted MIME type hint from Content-Type.
     */
    private function clientMimeType(Response $response): ?string
    {
        $contentType = \trim(\explode(';', $response->header('Content-Type'), 2)[0]);

        return $contentType === '' ? null : \strtolower($contentType);
    }

    /**
     * Extract an untrusted filename hint from the final URL path.
     */
    private function urlFilename(string $url): ?string
    {
        $path = \parse_url($url, PHP_URL_PATH);

        if (\is_string($path) === false) {
            return null;
        }

        $filename = \rawurldecode(\basename($path));

        return \in_array($filename, ['', '.', '/'], true)
            ? null
            : $filename;
    }

    /**
     * Find a domain exception wrapped by the HTTP transport.
     */
    private function previousFileMagicException(Throwable $exception): ?FileMagicException
    {
        do {
            if ($exception instanceof FileMagicException) {
                return $exception;
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return null;
    }
}
