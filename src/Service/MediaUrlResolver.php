<?php declare(strict_types=1);

namespace MediaS3\Service;

use MediaS3\Entity\MediaAsset;

final class MediaUrlResolver
{
    public function __construct(
        private S3Storage $storage,
    ) {}

    /**
     * Returns best URL for a given variant name.
     * Prefer WEBP if exists and $preferWebp true.
     */
    public function variantUrl(MediaAsset $asset, string $variantName, bool $preferWebp = true): ?string
    {
        $best = null;
        foreach ($asset->getVariants() as $v) {
            if ($v->getVariant() !== $variantName) continue;
            if ($preferWebp && $v->getFormat() === 'webp') {
                return $this->storage->publicUrl($v->getS3Key());
            }
            if ($best === null && $v->getFormat() === 'jpg') {
                $best = $this->storage->publicUrl($v->getS3Key());
            }
        }
        return $best;
    }

    public function originalUrl(MediaAsset $asset, bool $preferWebp = true): ?string
    {
        if ($preferWebp && $asset->getOriginalKeyWebp() !== null) {
            return $this->storage->publicUrl($asset->getOriginalKeyWebp());
        }
        if ($asset->getOriginalKeyJpg() !== null) {
            return $this->storage->publicUrl($asset->getOriginalKeyJpg());
        }
        return null;
    }
}
