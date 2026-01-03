<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use App\MediaS3\ValueObject\ProfileDefinition;
use App\MediaS3\ValueObject\VariantDefinition;

final class ProfileRegistry
{
    /** @var array<string, ProfileDefinition> */
    private array $profiles = [];

    /** @param array<string, mixed> $cfg */
    public function __construct(array $cfg)
    {
        foreach ($cfg as $name => $p) {
            $p = $this->toArray($p);
            $variantsRaw = $this->toArray($p['variants'] ?? []);

            $variants = [];
            foreach ($variantsRaw as $vName => $v) {
                $v = $this->toArray($v);
                $variants[$vName] = new VariantDefinition(
                    (string) $vName,
                    (int) ($v['w'] ?? 0),
                    (int) ($v['h'] ?? 0),
                    (string) ($v['fit'] ?? 'contain'),
                );
            }

            $formats = $this->toArray($p['formats'] ?? ['jpg', 'webp']);
            $formats = array_values(array_filter(
                $formats,
                fn($x) => in_array($x, ['jpg', 'webp', 'png', 'avif'], true)
            ));

            $this->profiles[(string) $name] = new ProfileDefinition(
                (string) $name,
                (string) ($p['prefix'] ?? ''),
                (bool) ($p['keepOriginal'] ?? false),
                (int) ($p['maxOriginalLongEdge'] ?? 3000),
                $formats,
                $variants,
            );
        }
    }

    /** @return array<mixed> */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        return [];
    }

    public function get(string $name): ProfileDefinition
    {
        if (!isset($this->profiles[$name])) {
            throw new \InvalidArgumentException("Unknown media profile: {$name}");
        }
        return $this->profiles[$name];
    }
}
