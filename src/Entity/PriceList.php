<?php

namespace App\Entity;

use App\Repository\PriceListRepository;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: PriceListRepository::class)]
#[ApiResource(
    normalizationContext: ['groups' => ['pricelist:read']],
    denormalizationContext: ['groups' => ['pricelist:write']],
    security: "is_granted('ROLE_ADMIN')",
)]
#[ApiFilter(SearchFilter::class, properties: ['product' => 'exact', 'channel' => 'exact'])]
class PriceList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['pricelist:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pricelist:read', 'pricelist:write'])]
    private ?Product $product = null;

    #[ORM\ManyToOne(inversedBy: 'priceLists')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pricelist:read', 'pricelist:write'])]
    private ?StoreChannel $channel = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['pricelist:read', 'pricelist:write'])]
    private ?string $price = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getChannel(): ?StoreChannel
    {
        return $this->channel;
    }

    public function setChannel(?StoreChannel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;

        return $this;
    }
}