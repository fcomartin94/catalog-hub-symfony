<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Product;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Restricts GET /api/products to published products for every role except
 * ROLE_ADMIN.
 *
 * ProductVoter protects a single product (GET /api/products/{id}), but a
 * voter never runs on a collection: API Platform loads the list straight from
 * the database. Without this extension, a ROLE_MOBILE_APP token could list
 * the drafts it is forbidden to open one by one.
 */
final class PublishedProductsExtension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (Product::class !== $resourceClass || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $queryBuilder->andWhere(sprintf('%s.published = true', $queryBuilder->getRootAliases()[0]));
    }
}
