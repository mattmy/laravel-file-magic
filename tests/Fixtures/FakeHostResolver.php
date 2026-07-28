<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Tests\Fixtures;

use Mattmy\FileMagic\Contracts\HostResolver;
use Mattmy\FileMagic\Exceptions\RemoteDownloadFailed;

final readonly class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, non-empty-list<string>>  $addresses
     */
    public function __construct(private array $addresses) {}

    /**
     * Return deterministic addresses without performing DNS queries.
     *
     * @return non-empty-list<string>
     */
    public function resolve(string $host): array
    {
        return $this->addresses[$host]
            ?? throw new RemoteDownloadFailed('The fake remote host could not be resolved.');
    }
}
