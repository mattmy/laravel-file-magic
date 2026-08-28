<?php

declare(strict_types=1);
use Mattmy\FileMagic\Models\StoredFile;

return [
    'disk' => \env('FILE_MAGIC_DISK', \env('FILESYSTEM_DISK', 'local')),
    'directory' => \env('FILE_MAGIC_DIRECTORY', 'files'),
    'visibility' => \env('FILE_MAGIC_VISIBILITY', 'private'),
    'max_size' => 100 * 1024 * 1024,
    'allowed_mime_types' => [],
    'blocked_mime_types' => [
        'application/x-httpd-php',
        'application/x-php',
    ],
    'collision' => 'unique',
    'collision_lock' => [
        'enabled' => false,
        'store' => null,
        'lease_seconds' => 300,
        'wait_seconds' => 10,
    ],
    'checksum_algorithm' => 'sha256',
    'temporary_url_ttl' => 5,
    'model' => StoredFile::class,
    'table' => 'stored_files',
    'image' => [
        'quality' => 80,
        'max_width' => 1920,
    ],
    'zip' => [
        'max_files' => 100,
        'max_size' => 1024 * 1024 * 1024,
    ],
    'remote' => [
        'connect_timeout' => 5,
        'timeout' => 30,
        'max_redirects' => 3,
        'allowed_hosts' => [],
        'allowed_ports' => [80, 443],
    ],
];
