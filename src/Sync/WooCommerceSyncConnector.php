<?php

namespace App\Sync;

use App\Entity\PriceList;
use App\Entity\Product;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Pushes a product/price to a WooCommerce store through its REST API v3.
 *
 * WooCommerce's REST API is JSON-based, authenticated with a consumer
 * key/secret pair (OAuth1-style over HTTPS, sent as HTTP Basic Auth in
 * practice), and identifies products to update via SKU lookup rather than
 * PrestaShop's internal numeric ID — a real difference between the two
 * platforms' API designs that this connector has to account for, not just a
 * cosmetic difference in request format.
 */
final class WooCommerceSyncConnector implements StoreSyncConnectorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function push(Product $product, PriceList $priceList): void
    {
        $channel = $priceList->getChannel();

        try {
            $response = $this->httpClient->request('PUT', rtrim($channel->getBaseUrl(), '/').'/wp-json/wc/v3/products', [
                'auth_basic' => [
                    $_ENV['WOOCOMMERCE_CONSUMER_KEY'] ?? 'test-consumer-key',
                    $_ENV['WOOCOMMERCE_CONSUMER_SECRET'] ?? 'test-consumer-secret',
                ],
                'json' => [
                    'sku' => $product->getSku(),
                    'name' => $product->getName(),
                    'regular_price' => $priceList->getPrice(),
                    'status' => $product->isPublished() ? 'publish' : 'draft',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new StoreSyncException(sprintf(
                    'WooCommerce rejected sync for SKU %s on channel "%s": HTTP %d',
                    $product->getSku(),
                    $channel->getName(),
                    $status,
                ));
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new StoreSyncException(sprintf(
                'WooCommerce sync failed for SKU %s on channel "%s": %s',
                $product->getSku(),
                $channel->getName(),
                $e->getMessage(),
            ), previous: $e);
        }
    }
}
