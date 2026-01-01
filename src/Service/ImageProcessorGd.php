<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use App\MediaS3\ValueObject\ProfileDefinition;
use App\MediaS3\ValueObject\VariantDefinition;

/**
 * GD-based resizing (downscale-only). Outputs JPG and optionally WEBP.
 */
final class ImageProcessorGd
{
    public function isWebpSupported(): bool
    {
        return (bool) (gd_info()['WebP Support'] ?? false);
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
        if ($format === 'webp') {
            if (!function_exists('imagewebp')) {
                imagedestroy($srcImg); imagedestroy($dstImg);
                throw new \RuntimeException('GD webp functions missing');
            }
            if (!imagewebp($dstImg, null, $quality)) {
                imagedestroy($srcImg); imagedestroy($dstImg);
                throw new \RuntimeException('imagewebp failed');
            }
            $ct = 'image/webp';
        } else {
            // Flatten alpha onto white for jpg
            $jpgImg = imagecreatetruecolor($dstW, $dstH);
            $white = imagecolorallocate($jpgImg, 255,255,255);
            imagefilledrectangle($jpgImg, 0,0, $dstW,$dstH, $white);
            imagecopy($jpgImg, $dstImg, 0,0, 0,0, $dstW,$dstH);

            imageinterlace($jpgImg, true);
            imagejpeg($jpgImg, null, $quality);
            imagedestroy($jpgImg);
            $ct = 'image/jpeg';
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
        if ($this->isWebpSupported() && function_exists('imagewebp')) {
            ob_start();
            imagewebp($dstImg, null, $qualityWebp);
            $bodyWebp = (string) ob_get_clean();
        }

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return ['bodyJpg' => $bodyJpg, 'bodyWebp' => $bodyWebp, 'w' => $dstW, 'h' => $dstH];
    }
}
