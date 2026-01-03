<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use App\MediaS3\ValueObject\ProfileDefinition;
use App\MediaS3\ValueObject\VariantDefinition;

/**
 * GD-based resizing (downscale-only). Outputs JPG and optionally WEBP.
 */
final class ImageProcessorGd
{
    /** Estimated memory multiplier for image processing (bytes per pixel) */
    private const MEMORY_MULTIPLIER = 5;

    public function isWebpSupported(): bool
    {
        return (bool) (gd_info()['WebP Support'] ?? false);
    }

    public function isAvifSupported(): bool
    {
        return function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false);
    }

    public function isPngSupported(): bool
    {
        return function_exists('imagepng');
    }

    /**
     * Check if there's enough memory to process an image
     */
    private function checkMemoryForImage(int $width, int $height): void
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return; // Unlimited memory
        }

        // Parse memory limit to bytes
        $limit = $this->parseMemoryLimit($memoryLimit);
        if ($limit === -1) {
            return;
        }

        // Estimate memory needed (width * height * channels * multiplier)
        $estimatedMemory = $width * $height * self::MEMORY_MULTIPLIER;
        $currentMemory = memory_get_usage(true);
        $availableMemory = $limit - $currentMemory;

        if ($estimatedMemory > $availableMemory) {
            throw new \RuntimeException(
                sprintf(
                    'Insufficient memory to process image %dx%d (needs ~%dMB, available ~%dMB)',
                    $width,
                    $height,
                    (int)($estimatedMemory / 1024 / 1024),
                    (int)($availableMemory / 1024 / 1024)
                )
            );
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /** @return array{w:int,h:int,type:int} */
    private function getSizeFromBytes(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        if ($info === false) {
            throw new \RuntimeException('Unsupported image bytes');
        }
        return ['w' => (int)$info[0], 'h' => (int)$info[1], 'type' => (int)$info[2]];
    }

    /** @return \GdImage|resource */
    private function createImageFromBytes(string $bytes, int $type)
    {
        // Prefer imagecreatefromstring for broad support.
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \RuntimeException('GD cannot decode image');
        }
        return $img;
    }

    /** @return array{body:string,w:int,h:int,contentType:string} */
    public function renderVariant(string $srcBytes, VariantDefinition $def, string $format, int $quality, bool $noUpscale = true): array
    {
        $meta = $this->getSizeFromBytes($srcBytes);
        $srcW = $meta['w']; $srcH = $meta['h'];

        // Check memory before processing
        $this->checkMemoryForImage($srcW, $srcH);

        $srcImg = $this->createImageFromBytes($srcBytes, $meta['type']);

        $targetW = $def->w;
        $targetH = $def->h;

        // No-upscale: if source smaller, keep within source bounds
        if ($noUpscale) {
            $targetW = min($targetW, $srcW);
            $targetH = min($targetH, $srcH);
        }

        // Compute crop/contain
        if ($def->fit === 'cover') {
            $srcRatio = $srcW / $srcH;
            $dstRatio = $targetW / $targetH;

            if ($srcRatio > $dstRatio) {
                $newH = $srcH;
                $newW = (int) round($srcH * $dstRatio);
                $srcX = (int) round(($srcW - $newW) / 2);
                $srcY = 0;
            } else {
                $newW = $srcW;
                $newH = (int) round($srcW / $dstRatio);
                $srcX = 0;
                $srcY = (int) round(($srcH - $newH) / 2);
            }

            $dstW = max(1, $targetW);
            $dstH = max(1, $targetH);
        } else {
            $scale = min($targetW / $srcW, $targetH / $srcH);
            $dstW = max(1, (int) floor($srcW * $scale));
            $dstH = max(1, (int) floor($srcH * $scale));

            $srcX = 0; $srcY = 0; $newW = $srcW; $newH = $srcH;
        }

        $dstImg = imagecreatetruecolor($dstW, $dstH);
        imagealphablending($dstImg, true);
        imagesavealpha($dstImg, true);

        imagecopyresampled($dstImg, $srcImg, 0,0, $srcX,$srcY, $dstW,$dstH, $newW,$newH);

        ob_start();
        switch ($format) {
            case 'webp':
                if (!function_exists('imagewebp')) {
                    imagedestroy($srcImg); imagedestroy($dstImg);
                    throw new \RuntimeException('GD webp functions missing');
                }
                if (!imagewebp($dstImg, null, $quality)) {
                    imagedestroy($srcImg); imagedestroy($dstImg);
                    throw new \RuntimeException('imagewebp failed');
                }
                $ct = 'image/webp';
                break;

            case 'avif':
                if (!function_exists('imageavif')) {
                    imagedestroy($srcImg); imagedestroy($dstImg);
                    throw new \RuntimeException('GD avif functions missing');
                }
                if (!imageavif($dstImg, null, $quality)) {
                    imagedestroy($srcImg); imagedestroy($dstImg);
                    throw new \RuntimeException('imageavif failed');
                }
                $ct = 'image/avif';
                break;

            case 'png':
                if (!function_exists('imagepng')) {
                    imagedestroy($srcImg); imagedestroy($dstImg);
                    throw new \RuntimeException('GD png functions missing');
                }
                // PNG quality is 0-9 (compression level), convert from 0-100
                $pngQuality = (int) (9 - ($quality / 100 * 9));
                if (!imagepng($dstImg, null, $pngQuality)) {
                    imagedestroy($srcImg); imagedestroy($dstImg);
                    throw new \RuntimeException('imagepng failed');
                }
                $ct = 'image/png';
                break;

            default:
                // JPG - flatten alpha onto white
                $jpgImg = imagecreatetruecolor($dstW, $dstH);
                $white = imagecolorallocate($jpgImg, 255,255,255);
                imagefilledrectangle($jpgImg, 0,0, $dstW,$dstH, $white);
                imagecopy($jpgImg, $dstImg, 0,0, 0,0, $dstW,$dstH);

                imageinterlace($jpgImg, true);
                imagejpeg($jpgImg, null, $quality);
                imagedestroy($jpgImg);
                $ct = 'image/jpeg';
                break;
        }
        $body = (string) ob_get_clean();

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return ['body' => $body, 'w' => $dstW, 'h' => $dstH, 'contentType' => $ct];
    }

    /** Create a downscaled "original" within maxLongEdge (no-upscale). */
    /** @return array{bodyJpg:string, bodyWebp:?string, w:int, h:int} */
    public function renderOriginal(string $srcBytes, int $maxLongEdge, int $qualityJpg = 82, int $qualityWebp = 80): array
    {
        $meta = $this->getSizeFromBytes($srcBytes);
        $srcW = $meta['w']; $srcH = $meta['h'];

        // Check memory before processing
        $this->checkMemoryForImage($srcW, $srcH);

        $long = max($srcW, $srcH);

        $scale = $long > $maxLongEdge ? ($maxLongEdge / $long) : 1.0;
        $dstW = max(1, (int) floor($srcW * $scale));
        $dstH = max(1, (int) floor($srcH * $scale));

        $srcImg = $this->createImageFromBytes($srcBytes, $meta['type']);
        $dstImg = imagecreatetruecolor($dstW, $dstH);
        imagealphablending($dstImg, true);
        imagesavealpha($dstImg, true);
        imagecopyresampled($dstImg, $srcImg, 0,0, 0,0, $dstW,$dstH, $srcW,$srcH);

        // jpg
        ob_start();
        $jpgImg = imagecreatetruecolor($dstW, $dstH);
        $white = imagecolorallocate($jpgImg, 255,255,255);
        imagefilledrectangle($jpgImg, 0,0, $dstW,$dstH, $white);
        imagecopy($jpgImg, $dstImg, 0,0, 0,0, $dstW,$dstH);
        imageinterlace($jpgImg, true);
        imagejpeg($jpgImg, null, $qualityJpg);
        imagedestroy($jpgImg);
        $bodyJpg = (string) ob_get_clean();

        $bodyWebp = null;
        if ($this->isWebpSupported()) {
            ob_start();
            imagewebp($dstImg, null, $qualityWebp);
            $bodyWebp = (string) ob_get_clean();
        }

        $bodyAvif = null;
        if ($this->isAvifSupported()) {
            ob_start();
            imageavif($dstImg, null, $qualityWebp);
            $bodyAvif = (string) ob_get_clean();
        }

        $bodyPng = null;
        if ($this->isPngSupported()) {
            ob_start();
            $pngQuality = (int) (9 - ($qualityWebp / 100 * 9));
            imagepng($dstImg, null, $pngQuality);
            $bodyPng = (string) ob_get_clean();
        }

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return [
            'bodyJpg' => $bodyJpg,
            'bodyWebp' => $bodyWebp,
            'bodyAvif' => $bodyAvif,
            'bodyPng' => $bodyPng,
            'w' => $dstW,
            'h' => $dstH,
        ];
    }
}
