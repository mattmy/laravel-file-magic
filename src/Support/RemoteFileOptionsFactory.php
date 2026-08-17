<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Mattmy\FileMagic\Exceptions\InvalidRemoteOptions;

final readonly class RemoteFileOptionsFactory
{
    /**
     * Create the remote options factory.
     */
    public function __construct(private FileMagicConfig $config) {}

    /**
     * Build safe remote defaults from package configuration.
     */
    public function defaults(): RemoteFileOptions
    {
        $connectTimeout = $this->config->remoteConnectTimeout();

        try {
            return new RemoteFileOptions(
                connectTimeoutSeconds: $connectTimeout,
                timeoutSeconds: $this->config->remoteTimeout($connectTimeout),
                maxRedirects: $this->config->remoteMaximumRedirects(),
                allowedHosts: $this->config->remoteAllowedHosts(),
                allowedPorts: $this->config->remoteAllowedPorts(),
            );
        } catch (InvalidRemoteOptions $exception) {
            throw new InvalidConfiguration(
                'The [file-magic.remote.allowed_hosts] configuration must contain valid hostnames.',
                previous: $exception,
            );
        }
    }
}
