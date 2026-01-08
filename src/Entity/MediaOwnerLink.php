<?php declare(strict_types=1);

namespace MediaS3\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'media_owner_link')]
class MediaOwnerLink
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 128)]
    private string $ownerType;

    #[ORM\Column(type: 'integer')]
    private int $ownerId;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MediaAsset $asset;

    #[ORM\Column(type: 'string', length: 64)]
    private string $role;

    #[ORM\Column(type: 'integer')]
    private int $sort = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $ownerType, int $ownerId, MediaAsset $asset, string $role, int $sort = 0)
    {
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->asset = $asset;
        $this->role = $role;
        $this->sort = $sort;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int { return $this->id; }
    public function getAsset(): MediaAsset { return $this->asset; }
    public function getSort(): int { return $this->sort; }
    public function getOwnerType(): string { return $this->ownerType; }
    public function getOwnerId(): int { return $this->ownerId; }
    public function getRole(): string { return $this->role; }
}
