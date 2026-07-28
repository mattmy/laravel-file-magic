<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Contracts;

interface HostResolver
{
    /**
     * Resolve every IPv4 and IPv6 address currently advertised by a host.
     *
     * @return non-empty-list<string>
     */
    public function resolve(string $host): array;
}
