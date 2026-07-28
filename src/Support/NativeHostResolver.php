<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Mattmy\FileMagic\Contracts\HostResolver;
use Mattmy\FileMagic\Exceptions\RemoteDownloadFailed;

final class NativeHostResolver implements HostResolver
{
    /**
     * Resolve every IPv4 and IPv6 address advertised by a host.
     *
     * @return non-empty-list<string>
     */
    public function resolve(string $host): array
    {
        if (\filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = \dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            throw new RemoteDownloadFailed('The remote host could not be resolved.');
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (
                \is_string($address) &&
                \filter_var($address, FILTER_VALIDATE_IP) !== false &&
                \in_array($address, $addresses, true) === false
            ) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            throw new RemoteDownloadFailed('The remote host did not resolve to an IP address.');
        }

        return $addresses;
    }
}
