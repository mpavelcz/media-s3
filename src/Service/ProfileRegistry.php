<?php declare(strict_types=1);

namespace App\MediaS3\Service;

use App\MediaS3\ValueObject\ProfileDefinition;
use App\MediaS3\ValueObject\VariantDefinition;

final class ProfileRegistry
{
    /** @var array<string, ProfileDefinition> */
    private array $profiles = [];

    /** @param array<string, array> $cfg */
    public function __construct(array $cfg)
    {
        foreach ($cfg as $name => $p) {
            $variants = [];
            foreach (($p['variants'] ?? []) as $vName => $v) {
                $variants[$vName] = new VariantDefinition(
                    (string) $vName,
                    (int) $v['w'],
                    (int) $v['h'],
                    (string) ($v['fit'] ?? 'contain'),
                );
            }

            $formats = array_values(array_filter(($p['formats'] ?? ['jpg','webp']), fn($x) => in_array($x, ['jpg','webp'], true)));

            $this->profiles[$name] = new ProfileDefinition(
                (string) $name,
                (string) $p['prefix'],
                (bool) ($p['keepOriginal'] ?? false),
                (int) ($p['maxOriginalLongEdge'] ?? 3000),
                $formats,
                $variants,
            );
        }
    }

    public function get(string $name): ProfileDefinition
    {
        if (!isset($this->profiles[$name])) {
            throw new \InvalidArgumentException("Unknown media profile: {$name}");
        }
        return $this->profiles[$name];
    }
}
