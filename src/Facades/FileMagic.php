<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Facades;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;
use Mattmy\FileMagic\Data\RemoteFileOptions;
use Mattmy\FileMagic\FileMagic as FileMagicManager;
use Mattmy\FileMagic\FileQuery;
use Mattmy\FileMagic\Models\StoredFile;
use Mattmy\FileMagic\PendingFile;
use Override;

/**
 * @method static PendingFile fromUpload(UploadedFile $file)
 * @method static PendingFile fromPath(string $path)
 * @method static PendingFile fromContent(string $contents, string|null $originalFilename = null, string|null $mimeType = null)
 * @method static PendingFile fromGeneratedContent(string $contents, string|null $originalFilename = null, string|null $mimeType = null)
 * @method static PendingFile fromBase64(string $base64, string|null $originalFilename = null)
 * @method static PendingFile fromUrl(string $url, RemoteFileOptions|null $options = null)
 * @method static PendingFile text(string $text)
 * @method static PendingFile json(array<array-key, mixed>|JsonSerializable $data)
 * @method static PendingFile csv(iterable<array-key, array<array-key, scalar|null>> $rows)
 * @method static FileQuery find(int|string|StoredFile|array<array-key, int|string|StoredFile>|Collection<array-key, covariant int|string|StoredFile> ...$targets)
 */
final class FileMagic extends Facade
{
    /**
     * Return the service container binding name.
     */
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return FileMagicManager::class;
    }
}
