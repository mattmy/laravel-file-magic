<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Data;

final readonly class RemoteEndpoint
{
    /**
     * Create a validated and resolved remote endpoint.
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ipAddress,
    ) {}

    /**
     * Return the cURL host-to-address binding for this request.
     */
    public function curlResolveEntry(): string
    {
        $address = \str_contains($this->ipAddress, ':')
            ? "[{$this->ipAddress}]"
            : $this->ipAddress;

        return "{$this->host}:{$this->port}:{$address}";
    }
}
