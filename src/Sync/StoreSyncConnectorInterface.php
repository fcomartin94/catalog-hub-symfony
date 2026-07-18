<?php

namespace App\Sync;

use App\Entity\PriceList;
use App\Entity\Product;

/**
 * Contract for pushing a product's catalog data out to an external ecommerce
 * platform, scoped to a single channel-specific price.
 *
 * This is the outbound counterpart to the connector pattern already used in
 * stock-sync-hub-laravel's StoreConnectorInterface, which reads products,
 * stock, and orders *in* from PrestaShop/WooCommerce. Here, this Symfony
 * project is the source of truth for the catalog, and each connector's job
 * is to translate that catalog into the specific API shape each platform
 * expects to receive.
 */
interface StoreSyncConnectorInterface
{
    /**
     * @throws StoreSyncException if the remote platform rejects or fails the push
     */
    public function push(Product $product, PriceList $priceList): void;
}
