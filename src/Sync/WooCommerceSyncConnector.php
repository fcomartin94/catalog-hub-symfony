<?php

namespace App\Sync;

use App\Entity\PriceList;
use App\Entity\Product;
use App\Entity\StoreChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pushes a product/price to a WooCommerce store through its REST API v3.
 *
 * WooCommerce's REST API is JSON-based and authenticated with a consumer
 * key/secret pair (sent as HTTP Basic Auth over HTTPS). Unlike PrestaShop it
 * lets us look a product up by SKU, so a push is:
 *
 *   1. GET  /wp-json/wc/v3/products?sku=SKU   -> does it exist already?
 *   2. PUT  /wp-json/wc/v3/products/{id}      -> yes: update it
 *      POST /wp-json/wc/v3/products           -> no: create it
 */
final class WooCommerceSyncConnector implements StoreSyncConnectorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::WOOCOMMERCE_CONSUMER_KEY')]
        private readonly ?string $consumerKey = null,
        #[Autowire(env: 'default::WOOCOMMERCE_CONSUMER_SECRET')]
        private readonly ?string $consumerSecret = null,
    ) {
    }

    public function push(Product $product, PriceList $priceList): void
    {
        $channel = $priceList->getChannel();
        $base = rtrim($channel->getBaseUrl(), '/').'/wp-json/wc/v3/products';
        $options = ['auth_basic' => $this->requireCredentials()];

        try {
            $existing = $this->request('GET', $base, $options + [
                'query' => ['sku' => $product->getSku()],
            ], $product, $channel);

            $remoteId = (int) ($existing[0]['id'] ?? 0);

            $this->request(
                $remoteId > 0 ? 'PUT' : 'POST',
                $remoteId > 0 ? $base.'/'.$remoteId : $base,
                $options + ['json' => [
                    'sku' => $product->getSku(),
                    'name' => $product->getName(),
                    'regular_price' => $priceList->getPrice(),
                    'status' => $product->isPublished() ? 'publish' : 'draft',
                ]],
                $product,
                $channel,
            );
        } catch (HttpClientExceptionInterface $e) {
            throw new StoreSyncException(sprintf(
                'WooCommerce sync failed for SKU %s on channel "%s": %s',
                $product->getSku(),
                $channel->getName(),
                $e->getMessage(),
            ), previous: $e);
        }
    }

    /**
     * @return array<mixed>
     */
    private function request(string $method, string $url, array $options, Product $product, StoreChannel $channel): array
    {
        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new StoreSyncException(sprintf(
                'WooCommerce rejected sync for SKU %s on channel "%s": HTTP %d',
                $product->getSku(),
                $channel->getName(),
                $status,
            ));
        }

        return $response->toArray(false);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function requireCredentials(): array
    {
        if (!$this->consumerKey || !$this->consumerSecret) {
            throw new StoreSyncException('WOOCOMMERCE_CONSUMER_KEY / WOOCOMMERCE_CONSUMER_SECRET are not configured.');
        }

        return [$this->consumerKey, $this->consumerSecret];
    }
}
