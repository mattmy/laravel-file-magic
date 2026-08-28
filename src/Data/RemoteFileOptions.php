<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Data;

use Mattmy\FileMagic\Exceptions\InvalidRemoteOptions;

final readonly class RemoteFileOptions
{
    public const int DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;

    public const int DEFAULT_TIMEOUT_SECONDS = 30;

    public const int DEFAULT_MAX_REDIRECTS = 3;

    public const int MAX_REDIRECTS = 10;

    private const int MINIMUM_PORT = 1;

    private const int MAXIMUM_PORT = 65535;

    /**
     * @var list<string>
     */
    public array $allowedHosts;

    /**
     * @var non-empty-list<int>
     */
    public array $allowedPorts;

    /**
     * @var list<string>
     */
    public array $allowedPrivateHosts;

    /**
     * Create immutable options for one remote download.
     *
     * @param  array<array-key, mixed>  $allowedHosts
     * @param  array<array-key, mixed>  $allowedPorts
     * @param  array<array-key, mixed>  $allowedPrivateHosts
     */
    public function __construct(
        public bool $verifyTls = true,
        public bool $allowHttp = false,
        public bool $allowHtml = false,
        public int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        array $allowedHosts = [],
        array $allowedPorts = [80, 443],
        array $allowedPrivateHosts = [],
    ) {
        $this->validateTimeouts();
        $this->validateRedirects();
        $this->allowedHosts = $this->normalizeHosts($allowedHosts);
        $this->allowedPorts = $this->normalizePorts($allowedPorts);
        $this->allowedPrivateHosts = $this->normalizeHosts($allowedPrivateHosts);
    }

    /**
     * Create default options with TLS certificate verification explicitly disabled.
     */
    public static function withoutTlsVerification(): self
    {
        return new self(verifyTls: false);
    }

    /**
     * Determine whether a normalized host is explicitly allowed.
     *
     * @param  list<string>  $hosts
     */
    public function hostIsListed(string $host, array $hosts): bool
    {
        return \in_array(\strtolower($host), $hosts, true);
    }

    /**
     * Validate timeout relationships.
     */
    private function validateTimeouts(): void
    {
        if ($this->connectTimeoutSeconds < 1) {
            throw new InvalidRemoteOptions('The remote connect timeout must be greater than zero.');
        }

        if ($this->timeoutSeconds < $this->connectTimeoutSeconds) {
            throw new InvalidRemoteOptions(
                'The remote timeout must be greater than or equal to the connect timeout.',
            );
        }
    }

    /**
     * Validate the redirect limit.
     */
    private function validateRedirects(): void
    {
        if ($this->maxRedirects < 0 || $this->maxRedirects > self::MAX_REDIRECTS) {
            throw new InvalidRemoteOptions(
                'The remote redirect limit must be between 0 and ' . self::MAX_REDIRECTS . '.',
            );
        }
    }

    /**
     * Normalize an exact hostname allowlist.
     *
     * @param  array<array-key, mixed>  $hosts
     * @return list<string>
     */
    private function normalizeHosts(array $hosts): array
    {
        $normalized = [];

        foreach ($hosts as $host) {
            if (\is_string($host) === false) {
                throw new InvalidRemoteOptions('Remote host allowlists must contain only strings.');
            }

            $host = \strtolower(\rtrim(\trim($host), '.'));

            if ($host === '' || $this->isValidHost($host) === false) {
                throw new InvalidRemoteOptions('Remote host allowlists contain an invalid hostname.');
            }

            if (\in_array($host, $normalized, true) === false) {
                $normalized[] = $host;
            }
        }

        return $normalized;
    }

    /**
     * Normalize a non-empty port allowlist.
     *
     * @param  array<array-key, mixed>  $ports
     * @return non-empty-list<int>
     */
    private function normalizePorts(array $ports): array
    {
        $normalized = [];

        foreach ($ports as $port) {
            if (
                \is_int($port) === false ||
                $port < self::MINIMUM_PORT ||
                $port > self::MAXIMUM_PORT
            ) {
                throw new InvalidRemoteOptions('Remote ports must be integers between 1 and 65535.');
            }

            if (\in_array($port, $normalized, true) === false) {
                $normalized[] = $port;
            }
        }

        if ($normalized === []) {
            throw new InvalidRemoteOptions('At least one remote port must be allowed.');
        }

        return $normalized;
    }

    /**
     * Determine whether a normalized hostname or IP literal is valid.
     */
    private function isValidHost(string $host): bool
    {
        return \filter_var($host, FILTER_VALIDATE_IP) !== false ||
            \filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
