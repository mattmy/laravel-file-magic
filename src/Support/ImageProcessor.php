<?php

declare(strict_types=1);

namespace Mattmy\FileMagic\Support;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Mattmy\FileMagic\Contracts\FileSource;
use Mattmy\FileMagic\Data\ImageOptions;
use Mattmy\FileMagic\Exceptions\ImageProcessingUnavailable;
use Mattmy\FileMagic\Sources\ContentFileSource;

final class ImageProcessor
{
    /**
     * Resize and encode a supported raster image.
     */
    public function process(FileSource $source, string $mimeType, ImageOptions $options): FileSource
    {
        if (\class_exists(ImageManager::class) === false) {
            throw new ImageProcessingUnavailable('Install intervention/image to process images.');
        }

        if (\in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'], true) === false) {
            throw new ImageProcessingUnavailable("Image processing is unavailable for {$mimeType}.");
        }

        $manager = ImageManager::usingDriver($this->driver());
        $stream = $source->openStream();

        try {
            $image = $manager->decodeStream($stream)->scaleDown(width: $options->maxWidth);
            $encoded = $image->encodeUsingMediaType($mimeType, quality: $options->quality);

            return new ContentFileSource(
                (string) $encoded,
                $source->originalFilename(),
                $mimeType,
            );
        } finally {
            \fclose($stream);
        }
    }

    /**
     * Resolve the first available Intervention Image driver.
     *
     * @return class-string<GdDriver|ImagickDriver>
     */
    private function driver(): string
    {
        return match (true) {
            \extension_loaded('imagick') => ImagickDriver::class,
            \extension_loaded('gd') => GdDriver::class,
            default => throw new ImageProcessingUnavailable('Install the GD or Imagick PHP extension to process images.'),
        };
    }
}
