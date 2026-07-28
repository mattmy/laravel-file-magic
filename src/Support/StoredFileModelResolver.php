<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Mattmy\FileMagic\Exceptions\InvalidStoredFileModel;
use Mattmy\FileMagic\Models\StoredFile;

final readonly class StoredFileModelResolver
{
    /**
     * Create the configured StoredFile model resolver.
     */
    public function __construct(private Config $config) {}

    /**
     * Return the validated configured StoredFile model class.
     *
     * @return class-string<StoredFile>
     */
    public function resolve(): string
    {
        $modelClass = $this->config->get('file-magic.model', StoredFile::class);

        if (
            \is_string($modelClass) === false ||
            \is_a($modelClass, StoredFile::class, true) === false
        ) {
            throw new InvalidStoredFileModel('The configured file model must extend StoredFile.');
        }

        return $modelClass;
    }
}
