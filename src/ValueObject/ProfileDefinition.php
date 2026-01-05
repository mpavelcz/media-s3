<?php declare(strict_types=1);

namespace MediaS3\ValueObject;

final class ProfileDefinition
{
    /** @param array<string,VariantDefinition> $variants */
    public function __construct(
        public readonly string $name,
        public readonly string $prefix,
        public readonly bool $keepOriginal,
        public readonly int $maxOriginalLongEdge,
        /** @var list<'jpg'|'webp'> */
        public readonly array $formats,
        public readonly array $variants,
    ) {}
}
