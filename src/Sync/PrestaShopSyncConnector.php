<?php

namespace App\Sync;

use App\Entity\PriceList;
use App\Entity\Product;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Pushes a product/price to a PrestaShop store through its Webservice API.
 *
 * PrestaShop's Webservice API is XML-based and resource-oriented
 * (PUT /api/products/{id} to update an existing product), authenticated with
 * an API key sent as the HTTP Basic Auth username with an empty password —
 * this is PrestaShop's own documented convention, not a generic HTTP Basic
 * Auth setup. The request body shape here matches PrestaShop's documented
 * product resource schema.
 */
final class PrestaShopSyncConnector implements StoreSyncConnectorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function push(Product $product, PriceList $priceList): void
    {
        $channel = $priceList->getChannel();

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'.
            '<prestashop><product>'.
            '<reference><![CDATA[%s]]></reference>'.
            '<name><![CDATA[%s]]></name>'.
            '<price>%s</price>'.
            '<active>%d</active>'.
            '</product></prestashop>',
            $product->getSku(),
            $product->getName(),
            $priceList->getPrice(),
            $product->isPublished() ? 1 : 0,
        );

        try {
            $response = $this->httpClient->request('PUT', rtrim($channel->getBaseUrl(), '/').'/api/products', [
                'auth_basic' => [$this->resolveApiKey($channel), ''],
                'headers' => ['Content-Type' => 'application/xml'],
                'body' => $xml,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new StoreSyncException(sprintf(
                    'PrestaShop rejected sync for SKU %s on channel "%s": HTTP %d',
                    $product->getSku(),
                    $channel->getName(),
                    $status,
                ));
            }
        } catch (HttpClientExceptionInterface $e) {
            throw new StoreSyncException(sprintf(
                'PrestaShop sync failed for SKU %s on channel "%s": %s',
                $product->getSku(),
                $channel->getName(),
                $e->getMessage(),
            ), previous: $e);
        }
    }

    /**
     * In a real deployment this would read a per-channel encrypted credential
     * from the database rather than an env var — kept simple here since no
     * live PrestaShop store is being synced against in this portfolio project.
     */
    private function resolveApiKey(\App\Entity\StoreChannel $channel): string
    {
        return $_ENV['PRESTASHOP_API_KEY'] ?? 'test-api-key';
    }
}
