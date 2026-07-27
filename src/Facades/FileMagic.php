<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Mattmy\FileMagic\PendingFile fromUpload(\Illuminate\Http\UploadedFile $file)
 * @method static \Mattmy\FileMagic\PendingFile fromPath(string $path)
 * @method static \Mattmy\FileMagic\PendingFile fromContent(string $contents, string|null $originalFilename = null, string|null $mimeType = null)
 * @method static \Mattmy\FileMagic\PendingFile fromBase64(string $base64, string|null $originalFilename = null)
 * @method static \Mattmy\FileMagic\PendingFile text(string $text)
 * @method static \Mattmy\FileMagic\PendingFile json(array<array-key, mixed>|\JsonSerializable $data)
 * @method static \Mattmy\FileMagic\PendingFile csv(iterable<array-key, array<array-key, scalar|null>> $rows)
 * @method static \Mattmy\FileMagic\FileQuery find(int|string|\Mattmy\FileMagic\Models\StoredFile|array<array-key, int|string|\Mattmy\FileMagic\Models\StoredFile>|\Illuminate\Support\Collection<array-key, int|string|\Mattmy\FileMagic\Models\StoredFile> ...$targets)
 */
final class FileMagic extends Facade
{
    /**
     * Return the service container binding name.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Mattmy\FileMagic\FileMagic::class;
    }
}
