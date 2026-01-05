<?php declare(strict_types=1);

namespace MediaS3\DTO;

/**
 * Result of downloading remote file
 */
final class DownloadResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $contentType,
        public readonly int $size,
    ) {}
}
