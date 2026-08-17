<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use Throwable;

final readonly class CollisionLock
{
    private const string HASH_ALGORITHM = 'sha256';

    private const string KEY_PREFIX = 'file-magic:collision:';

    private ?LockProvider $locks;

    private int $leaseSeconds;

    private int $waitSeconds;

    /**
     * Configure optional atomic collision locking.
     */
    public function __construct(CacheFactory $cache, FileMagicConfig $config)
    {
        if ($config->collisionLockEnabled() === false) {
            $this->locks = null;
            $this->leaseSeconds = 0;
            $this->waitSeconds = 0;

            return;
        }

        $storeName = $config->collisionLockStore();
        $this->leaseSeconds = $config->collisionLockLeaseSeconds();
        $this->waitSeconds = $config->collisionLockWaitSeconds();

        try {
            $store = $cache->store($storeName)->getStore();
        } catch (Throwable $exception) {
            throw new InvalidConfiguration(
                'The [file-magic.collision_lock.store] configuration must resolve to a cache store that supports atomic locks.',
                previous: $exception,
            );
        }

        if ($store instanceof LockProvider === false || $store instanceof NullStore) {
            throw new InvalidConfiguration(
                'The [file-magic.collision_lock.store] configuration must resolve to a cache store that supports atomic locks.',
            );
        }

        $this->locks = $store;
    }

    /**
     * Run a callback while owning its disk and path identity when locking is enabled.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function run(string $disk, string $path, callable $callback): mixed
    {
        if ($this->locks === null) {
            return $callback();
        }

        $callbackStarted = false;

        try {
            return $this->locks
                ->lock($this->key($disk, $path), $this->leaseSeconds)
                ->block($this->waitSeconds, function () use ($callback, &$callbackStarted): mixed {
                    $callbackStarted = true;

                    return $callback();
                });
        } catch (Throwable $exception) {
            if ($callbackStarted) {
                throw $exception;
            }

            throw new FileWriteFailed(
                "The collision lock could not be acquired for disk [{$disk}].",
                previous: $exception,
            );
        }
    }

    /**
     * Create a stable cache key without exposing the storage path.
     */
    private function key(string $disk, string $path): string
    {
        return self::KEY_PREFIX . \hash(self::HASH_ALGORITHM, $disk . "\0" . $path);
    }
}
