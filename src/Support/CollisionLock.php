<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Mattmy\FileMagic\Exceptions\FileWriteFailed;
use Mattmy\FileMagic\Exceptions\InvalidConfiguration;
use RuntimeException;
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
        return $this->runMany([
            ['disk' => $disk, 'path' => $path],
        ], $callback);
    }

    /**
     * Run a callback while owning every distinct disk and path identity.
     *
     * @template TResult
     *
     * @param  list<array{disk: string, path: string}>  $locations
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function runMany(array $locations, callable $callback): mixed
    {
        if ($this->locks === null) {
            return $callback();
        }

        $keys = \array_values(\array_unique(\array_map(
            fn (array $location): string => $this->key($location['disk'], $location['path']),
            $locations,
        )));
        \sort($keys, SORT_STRING);

        /** @var list<Lock> $acquired */
        $acquired = [];
        $callbackStarted = false;
        $failure = null;

        /** @var array{value?: TResult} $result */
        $result = [];

        try {
            foreach ($keys as $key) {
                $lock = $this->locks->lock($key, $this->leaseSeconds);

                if ($lock->block($this->waitSeconds) !== true) {
                    throw new RuntimeException('The cache lock provider did not acquire the requested lock.');
                }

                $acquired[] = $lock;
            }

            $callbackStarted = true;
            $result['value'] = $callback();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        foreach (\array_reverse($acquired) as $lock) {
            try {
                if ($lock->release() === false) {
                    $failure ??= new RuntimeException('A collision lock could not be released by its owner.');
                }
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure instanceof Throwable) {
            if ($callbackStarted) {
                throw $failure;
            }

            throw new FileWriteFailed(
                'One or more collision locks could not be acquired.',
                previous: $failure,
            );
        }

        if (\array_key_exists('value', $result) === false) {
            throw new RuntimeException('The collision lock callback did not produce a result.');
        }

        return $result['value'];
    }

    /**
     * Create a stable cache key without exposing the storage path.
     */
    private function key(string $disk, string $path): string
    {
        return self::KEY_PREFIX.\hash(self::HASH_ALGORITHM, $disk."\0".$path);
    }
}
