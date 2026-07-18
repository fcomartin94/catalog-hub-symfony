<?php

namespace App\Entity;

use App\Controller\SyncStoreChannelController;
use App\Repository\StoreChannelRepository;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: StoreChannelRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['channel:read']],
    denormalizationContext: ['groups' => ['channel:write']],
    security: "is_granted('ROLE_ADMIN')",
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
        new Post(
            uriTemplate: '/store_channels/{id}/sync',
            controller: SyncStoreChannelController::class,
            name: 'store_channel_sync',
            read: true,
            deserialize: false,
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
)]
class StoreChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['channel:read', 'pricelist:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Groups(['channel:read', 'channel:write', 'pricelist:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 30)]
    #[Groups(['channel:read', 'channel:write', 'pricelist:read'])]
    private ?string $platform = null;

    #[ORM\Column(length: 255)]
    #[Groups(['channel:read', 'channel:write'])]
    private ?string $baseUrl = null;

    #[ORM\OneToMany(mappedBy: 'channel', targetEntity: PriceList::class)]
    private Collection $priceLists;

    public function __construct()
    {
        $this->priceLists = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): static
    {
        $this->platform = $platform;

        return $this;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * @return Collection<int, PriceList>
     */
    public function getPriceLists(): Collection
    {
        return $this->priceLists;
    }
}