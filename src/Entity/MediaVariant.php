<?php declare(strict_types=1);

namespace MediaS3\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'media_variant')]
class MediaVariant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MediaAsset $asset;

    #[ORM\Column(type: 'string', length: 64)]
    private string $variant;

    #[ORM\Column(type: 'string', length: 10)]
    private string $format;

    #[ORM\Column(type: 'string', length: 1024)]
    private string $s3Key;

    #[ORM\Column(type: 'integer')]
    private int $width;

    #[ORM\Column(type: 'integer')]
    private int $height;

    #[ORM\Column(type: 'integer')]
    private int $bytes;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(MediaAsset $asset, string $variant, string $format, string $s3Key, int $w, int $h, int $bytes)
    {
        $this->asset = $asset;
        $this->variant = $variant;
        $this->format = $format;
        $this->s3Key = $s3Key;
        $this->width = $w;
        $this->height = $h;
        $this->bytes = $bytes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getVariant(): string { return $this->variant; }
    public function getFormat(): string { return $this->format; }
    public function getS3Key(): string { return $this->s3Key; }
}
