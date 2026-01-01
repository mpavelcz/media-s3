<?php declare(strict_types=1);

namespace App\MediaS3\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'media_asset')]
#[ORM\HasLifecycleCallbacks]
class MediaAsset
{
    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_READY = 'READY';
    public const STATUS_FAILED = 'FAILED';

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_REMOTE = 'remote';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $profile;

    #[ORM\Column(type: 'string', length: 16)]
    private string $source;

    #[ORM\Column(type: 'string', length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $originalKeyJpg = null;

    #[ORM\Column(type: 'string', length: 1024, nullable: true)]
    private ?string $originalKeyWebp = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $originalWidth = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $originalHeight = null;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $checksumSha1 = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, MediaVariant> */
    #[ORM\OneToMany(mappedBy: 'asset', targetEntity: MediaVariant::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $variants;

    public function __construct(string $profile, string $source, ?string $sourceUrl = null)
    {
        $this->profile = $profile;
        $this->source = $source;
        $this->sourceUrl = $sourceUrl;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->variants = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addVariant(MediaVariant $v): void
    {
        $this->variants->add($v);
    }

    public function markReady(): void
    {
        $this->status = self::STATUS_READY;
        $this->lastError = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->lastError = $error;
        $this->attempts++;
    }

    public function setStatus(string $status): void { $this->status = $status; }
    public function setOriginal(string $keyJpg, ?string $keyWebp, int $w, int $h, ?string $sha1): void
    {
        $this->originalKeyJpg = $keyJpg;
        $this->originalKeyWebp = $keyWebp;
        $this->originalWidth = $w;
        $this->originalHeight = $h;
        $this->checksumSha1 = $sha1;
    }

    public function getId(): int { return $this->id; }
    public function getProfile(): string { return $this->profile; }
    public function getSource(): string { return $this->source; }
    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getOriginalKeyJpg(): ?string { return $this->originalKeyJpg; }
    public function getOriginalKeyWebp(): ?string { return $this->originalKeyWebp; }
    public function getVariants(): Collection { return $this->variants; }
    public function getChecksumSha1(): ?string { return $this->checksumSha1; }
}
