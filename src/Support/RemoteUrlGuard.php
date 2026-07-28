<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Contracts\HostResolver;
use Mattmy\FileMagic\Data\RemoteEndpoint;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Exceptions\InvalidRemoteUrl;
use Mattmy\FileMagic\Exceptions\RemoteAccessDenied;

final readonly class RemoteUrlGuard
{
    /**
     * Create the remote URL security policy.
     */
    public function __construct(private HostResolver $hosts) {}

    /**
     * Validate, authorize and resolve a remote HTTP endpoint.
     */
    public function validate(string $url, RemoteFileOptions $options): RemoteEndpoint
    {
        $parts = $this->parse($url);
        $scheme = \strtolower($this->stringPart($parts, 'scheme'));
        $host = $this->normalizeHost($this->stringPart($parts, 'host'));
        $port = $this->port($parts, $scheme);

        $this->validateScheme($scheme, $options);
        $this->validateHost($host, $options);
        $this->validatePort($port, $options);

        $addresses = $this->hosts->resolve($host);
        $privateHostAllowed = $options->hostIsListed($host, $options->allowedPrivateHosts);

        foreach ($addresses as $address) {
            if ($this->isPublicAddress($address) === false && $privateHostAllowed === false) {
                throw new RemoteAccessDenied('The remote URL resolves to a non-public network.');
            }
        }

        return new RemoteEndpoint($url, $host, $port, $addresses[0]);
    }

    /**
     * Parse a syntactically valid absolute URL with no ambiguous authority data.
     *
     * @return array<string, int|string>
     */
    private function parse(string $url): array
    {
        if (
            $url === '' ||
            \preg_match('/[\x00-\x20\x7F]/', $url) === 1 ||
            \filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidRemoteUrl('The remote URL is invalid.');
        }

        $parts = \parse_url($url);

        if (
            \is_array($parts) === false ||
            \array_key_exists('user', $parts) ||
            \array_key_exists('pass', $parts) ||
            \array_key_exists('fragment', $parts)
        ) {
            throw new InvalidRemoteUrl('The remote URL contains unsupported components.');
        }

        return $parts;
    }

    /**
     * Return a required string URL component.
     *
     * @param  array<string, int|string>  $parts
     */
    private function stringPart(array $parts, string $key): string
    {
        $value = $parts[$key] ?? null;

        if (\is_string($value) === false || $value === '') {
            throw new InvalidRemoteUrl("The remote URL requires a valid {$key}.");
        }

        return $value;
    }

    /**
     * Normalize and validate a URL hostname or IP literal.
     */
    private function normalizeHost(string $host): string
    {
        $host = \strtolower(\trim($host, '[]'));

        if (
            \filter_var($host, FILTER_VALIDATE_IP) === false &&
            \filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidRemoteUrl('The remote URL host is invalid.');
        }

        return \rtrim($host, '.');
    }

    /**
     * Resolve the explicit or standard port.
     *
     * @param  array<string, int|string>  $parts
     */
    private function port(array $parts, string $scheme): int
    {
        $port = $parts['port'] ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => 0,
        };

        if (\is_int($port) === false) {
            throw new InvalidRemoteUrl('The remote URL port is invalid.');
        }

        return $port;
    }

    /**
     * Enforce supported and explicitly enabled URL schemes.
     */
    private function validateScheme(string $scheme, RemoteFileOptions $options): void
    {
        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $options->allowHttp) {
            return;
        }

        throw new RemoteAccessDenied('The remote URL scheme is not allowed.');
    }

    /**
     * Enforce an optional exact hostname allowlist.
     */
    private function validateHost(string $host, RemoteFileOptions $options): void
    {
        if (
            $options->allowedHosts !== [] &&
            $options->hostIsListed($host, $options->allowedHosts) === false
        ) {
            throw new RemoteAccessDenied('The remote URL host is not allowed.');
        }
    }

    /**
     * Enforce the configured connection port allowlist.
     */
    private function validatePort(int $port, RemoteFileOptions $options): void
    {
        if (\in_array($port, $options->allowedPorts, true) === false) {
            throw new RemoteAccessDenied('The remote URL port is not allowed.');
        }
    }

    /**
     * Determine whether an IP address is globally routable.
     */
    private function isPublicAddress(string $address): bool
    {
        return \filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
