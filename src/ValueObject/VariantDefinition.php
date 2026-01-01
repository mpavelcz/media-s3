<?php declare(strict_types=1);

namespace App\MediaS3\ValueObject;

final class VariantDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly int $w,
        public readonly int $h,
        public readonly string $fit, // 'contain'|'cover'
    ) {}
}
