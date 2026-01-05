<?php declare(strict_types=1);

namespace MediaS3\DTO;

/**
 * Result of rendering a single image variant
 */
final class VariantRenderResult
{
    public function __construct(
        public readonly string $body,
        public readonly int $w,
        public readonly int $h,
        public readonly string $contentType,
    ) {}
}
