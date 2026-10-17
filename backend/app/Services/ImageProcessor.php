<?php

namespace App\Services;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use SplFileInfo;

class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Build the web and thumbnail WebP files and the JPEG download copy from an original upload.
     *
     * Re-encoding through GD drops all metadata (EXIF, GPS, device info), and
     * the orientation is applied before encoding so the output is upright.
     * width and height are those of the web image, the one the gallery shows.
     *
     * @return array{width: int, height: int, web: string, thumb: string, download: string}
     */
    public function process(string $sourcePath): array
    {
        $image = $this->manager->read(new SplFileInfo($sourcePath))->orient();

        $webEdge = (int) config('photos.web.max_edge');
        $thumbSize = (int) config('photos.thumb.size');

        $webImage = (clone $image)->scaleDown(width: $webEdge, height: $webEdge);
        $web = $webImage->encode(new WebpEncoder(quality: (int) config('photos.web.quality')));

        $thumb = (clone $image)
            ->cover($thumbSize, $thumbSize)
            ->encode(new WebpEncoder(quality: (int) config('photos.thumb.quality')));

        $downloadEdge = (int) config('photos.download.max_edge');

        $download = (clone $image)
            ->scaleDown(width: $downloadEdge, height: $downloadEdge)
            ->encode(new JpegEncoder(quality: (int) config('photos.download.quality')));

        return [
            'width' => $webImage->width(),
            'height' => $webImage->height(),
            'web' => (string) $web,
            'thumb' => (string) $thumb,
            'download' => (string) $download,
        ];
    }
}
