<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Mattmy\FileMagic\Data\RemoteFileOptions;

final readonly class RemoteFileOptionsFactory
{
    /**
     * Create the remote options factory.
     */
    public function __construct(private Config $config) {}

    /**
     * Build safe remote defaults from package configuration.
     */
    public function defaults(): RemoteFileOptions
    {
        return new RemoteFileOptions(
            connectTimeoutSeconds: (int) $this->config->get(
                'file-magic.remote.connect_timeout',
                RemoteFileOptions::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            ),
            timeoutSeconds: (int) $this->config->get(
                'file-magic.remote.timeout',
                RemoteFileOptions::DEFAULT_TIMEOUT_SECONDS,
            ),
            maxRedirects: (int) $this->config->get(
                'file-magic.remote.max_redirects',
                RemoteFileOptions::DEFAULT_MAX_REDIRECTS,
            ),
            allowedHosts: $this->stringList('file-magic.remote.allowed_hosts'),
            allowedPorts: $this->integerList('file-magic.remote.allowed_ports', [80, 443]),
        );
    }

    /**
     * Return string list configuration without coercing invalid members.
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = $this->config->get($key, []);

        return \is_array($values) ? \array_values($values) : [];
    }

    /**
     * Return integer list configuration without coercing invalid members.
     *
     * @param  non-empty-list<int>  $default
     * @return list<int>
     */
    private function integerList(string $key, array $default): array
    {
        $values = $this->config->get($key, $default);

        return \is_array($values) ? \array_values($values) : [];
    }
}
