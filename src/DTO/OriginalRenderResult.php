<?php declare(strict_types=1);

namespace MediaS3\DTO;

/**
 * Result of rendering original image with multiple formats
 */
final class OriginalRenderResult
{
    public function __construct(
        public readonly string $bodyJpg,
        public readonly ?string $bodyWebp,
        public readonly ?string $bodyAvif,
        public readonly ?string $bodyPng,
        public readonly int $w,
        public readonly int $h,
    ) {}
}
