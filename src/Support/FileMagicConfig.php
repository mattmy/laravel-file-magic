<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;

final readonly class FileMagicConfig
{
    private const string DEFAULT_DIRECTORY = 'files';

    private const string DEFAULT_DISK = 'local';

    private const int DEFAULT_IMAGE_MAXIMUM_WIDTH = 1920;

    private const int DEFAULT_IMAGE_QUALITY = 80;

    private const int DEFAULT_MAXIMUM_SIZE = 104857600;

    private const string DEFAULT_TABLE = 'stored_files';

    private const int DEFAULT_TEMPORARY_URL_TTL = 5;

    private const int DEFAULT_ZIP_MAXIMUM_FILES = 100;

    private const int DEFAULT_ZIP_MAXIMUM_SIZE = 1073741824;

    private const int MAXIMUM_IMAGE_QUALITY = 100;

    private const int MAXIMUM_REMOTE_PORT = 65535;

    private const int MINIMUM_IMAGE_QUALITY = 1;

    private const int MINIMUM_REMOTE_PORT = 1;

    /** @var non-empty-list<int> */
    private const array DEFAULT_REMOTE_PORTS = [80, 443];

    /**
     * Create a strict reader for FileMagic runtime configuration.
     */
    public function __construct(private Config $config) {}

    /**
     * Return the default storage disk name.
     */
    public function disk(): string
    {
        return $this->nonEmptyString('file-magic.disk', self::DEFAULT_DISK);
    }

    /**
     * Return the default relative storage directory, including disk root.
     */
    public function directory(): string
    {
        $directory = $this->config->get('file-magic.directory', self::DEFAULT_DIRECTORY);

        if (\is_string($directory) === false) {
            $this->invalid('file-magic.directory', 'a string');
        }

        return $directory;
    }

    /**
     * Return the maximum accepted file size in bytes.
     */
    public function maximumSize(): int
    {
        return $this->positiveInteger('file-magic.max_size', self::DEFAULT_MAXIMUM_SIZE);
    }

    /**
     * Return the default temporary URL lifetime in minutes.
     */
    public function temporaryUrlTtl(): int
    {
        return $this->positiveInteger('file-magic.temporary_url_ttl', self::DEFAULT_TEMPORARY_URL_TTL);
    }

    /**
     * Return the stored-file database table name.
     */
    public function table(): string
    {
        return $this->nonEmptyString('file-magic.table', self::DEFAULT_TABLE);
    }

    /**
     * Return the default image maximum width.
     */
    public function imageMaximumWidth(): int
    {
        return $this->positiveInteger('file-magic.image.max_width', self::DEFAULT_IMAGE_MAXIMUM_WIDTH);
    }

    /**
     * Return the default image output quality.
     */
    public function imageQuality(): int
    {
        return $this->boundedInteger(
            'file-magic.image.quality',
            self::DEFAULT_IMAGE_QUALITY,
            self::MINIMUM_IMAGE_QUALITY,
            self::MAXIMUM_IMAGE_QUALITY,
        );
    }

    /**
     * Return the maximum records allowed in one ZIP download.
     */
    public function zipMaximumFiles(): int
    {
        return $this->positiveInteger('file-magic.zip.max_files', self::DEFAULT_ZIP_MAXIMUM_FILES);
    }

    /**
     * Return the maximum uncompressed bytes allowed in one ZIP download.
     */
    public function zipMaximumSize(): int
    {
        return $this->positiveInteger('file-magic.zip.max_size', self::DEFAULT_ZIP_MAXIMUM_SIZE);
    }

    /**
     * Return the remote connection timeout in seconds.
     */
    public function remoteConnectTimeout(): int
    {
        return $this->positiveInteger(
            'file-magic.remote.connect_timeout',
            RemoteFileOptions::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        );
    }

    /**
     * Return a total remote timeout no shorter than the connection timeout.
     */
    public function remoteTimeout(int $connectTimeout): int
    {
        $timeout = $this->positiveInteger(
            'file-magic.remote.timeout',
            RemoteFileOptions::DEFAULT_TIMEOUT_SECONDS,
        );

        if ($timeout < $connectTimeout) {
            $this->invalid('file-magic.remote.timeout', 'greater than or equal to the connect timeout');
        }

        return $timeout;
    }

    /**
     * Return the maximum redirects allowed for remote downloads.
     */
    public function remoteMaximumRedirects(): int
    {
        return $this->boundedInteger(
            'file-magic.remote.max_redirects',
            RemoteFileOptions::DEFAULT_MAX_REDIRECTS,
            0,
            RemoteFileOptions::MAX_REDIRECTS,
        );
    }

    /**
     * Return the exact remote host allowlist.
     *
     * @return list<string>
     */
    public function remoteAllowedHosts(): array
    {
        return $this->stringList('file-magic.remote.allowed_hosts');
    }

    /**
     * Return the remote destination port allowlist.
     *
     * @return non-empty-list<int>
     */
    public function remoteAllowedPorts(): array
    {
        $ports = $this->config->get('file-magic.remote.allowed_ports', self::DEFAULT_REMOTE_PORTS);

        if (\is_array($ports) === false || \array_is_list($ports) === false || $ports === []) {
            $this->invalid('file-magic.remote.allowed_ports', 'a non-empty list of integers between 1 and 65535');
        }

        foreach ($ports as $port) {
            if (
                \is_int($port) === false ||
                $port < self::MINIMUM_REMOTE_PORT ||
                $port > self::MAXIMUM_REMOTE_PORT
            ) {
                $this->invalid('file-magic.remote.allowed_ports', 'a non-empty list of integers between 1 and 65535');
            }
        }

        return $ports;
    }

    /**
     * Return the default stored-file visibility.
     */
    public function visibility(): FileVisibility
    {
        $visibility = $this->config->get('file-magic.visibility', FileVisibility::Private->value);

        if (\is_string($visibility) === false || FileVisibility::tryFrom($visibility) === null) {
            $this->invalid('file-magic.visibility', '[public] or [private]');
        }

        return FileVisibility::from($visibility);
    }

    /**
     * Return the default collision policy.
     */
    public function collisionPolicy(): CollisionPolicy
    {
        $policy = $this->config->get('file-magic.collision', CollisionPolicy::Unique->value);

        if (\is_string($policy) === false || CollisionPolicy::tryFrom($policy) === null) {
            $this->invalid('file-magic.collision', '[unique], [error], or [overwrite]');
        }

        return CollisionPolicy::from($policy);
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return $this->stringList('file-magic.allowed_mime_types');
    }

    /**
     * @return list<string>
     */
    public function blockedMimeTypes(): array
    {
        return $this->stringList('file-magic.blocked_mime_types');
    }

    /**
     * Return a configured supported checksum algorithm.
     */
    public function checksumAlgorithm(): string
    {
        $algorithm = $this->config->get('file-magic.checksum_algorithm', 'sha256');

        if (
            \is_string($algorithm) === false ||
            $algorithm === '' ||
            \in_array($algorithm, \hash_algos(), true) === false
        ) {
            throw new InvalidConfiguration(
                'The [file-magic.checksum_algorithm] configuration must be a supported hash algorithm.',
            );
        }

        return $algorithm;
    }

    /**
     * Return a required non-empty string setting.
     */
    private function nonEmptyString(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        if (\is_string($value) === false || $value === '') {
            $this->invalid($key, 'a non-empty string');
        }

        return $value;
    }

    /**
     * Return a required positive integer setting.
     */
    private function positiveInteger(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        if (\is_int($value) === false || $value < 1) {
            $this->invalid($key, 'a positive integer');
        }

        return $value;
    }

    /**
     * Return an integer setting constrained to an inclusive range.
     */
    private function boundedInteger(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $this->config->get($key, $default);

        if (\is_int($value) === false || $value < $minimum || $value > $maximum) {
            $this->invalid($key, "an integer between {$minimum} and {$maximum}");
        }

        return $value;
    }

    /**
     * Return a list containing only non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = $this->config->get($key, []);

        if (\is_array($values) === false || \array_is_list($values) === false) {
            $this->invalid($key, 'a list of non-empty strings');
        }

        foreach ($values as $value) {
            if (\is_string($value) === false || $value === '') {
                $this->invalid($key, 'a list of non-empty strings');
            }
        }

        return $values;
    }

    /**
     * Reject one invalid setting without exposing its value.
     */
    private function invalid(string $key, string $expected): never
    {
        throw new InvalidConfiguration("The [{$key}] configuration must be {$expected}.");
    }
}
