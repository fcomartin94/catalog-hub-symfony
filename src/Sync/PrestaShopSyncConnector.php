<?php

namespace App\Sync;

use App\Entity\PriceList;
use App\Entity\Product;
use App\Entity\StoreChannel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pushes a product's price and published state to a PrestaShop store through
 * its Webservice API.
 *
 * PrestaShop's Webservice is XML-based and resource-oriented, and identifies
 * products by its own numeric id, not by SKU. So a push is three requests:
 *
 *   1. GET /api/products?filter[reference]=[SKU]  -> find the remote id
 *   2. GET /api/products/{id}                     -> fetch the full resource
 *   3. PUT /api/products/{id}                     -> send it back with the
 *                                                    synced fields updated
 *
 * Step 2 exists because a PUT replaces the whole resource: sending only the
 * fields we care about would be rejected (or blank the rest). Fields PrestaShop
 * computes itself are read-only and must be removed before the PUT.
 *
 * Authentication is PrestaShop's convention: the API key is the HTTP Basic
 * username and the password is empty.
 *
 * Only products that already exist on the store (matched by reference) are
 * updated; creating products remotely is deliberately out of scope.
 */
final class PrestaShopSyncConnector implements StoreSyncConnectorInterface
{
    /** Fields PrestaShop returns on GET but refuses on PUT. */
    private const READ_ONLY_FIELDS = ['manufacturer_name', 'quantity', 'position_in_category', 'type'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default::PRESTASHOP_API_KEY')]
        private readonly ?string $apiKey = null,
    ) {
    }

    public function push(Product $product, PriceList $priceList): void
    {
        $channel = $priceList->getChannel();
        $base = rtrim($channel->getBaseUrl(), '/').'/api';
        $options = ['auth_basic' => [$this->requireApiKey(), '']];

        try {
            $remoteId = $this->findRemoteId($base, $options, $product, $channel);

            $resource = $this->parseXml(
                $this->send('GET', $base.'/products/'.$remoteId, $options, $product, $channel)
            );
            $node = $resource->product ?? null;
            if (!$node) {
                throw $this->failure($product, $channel, 'unexpected product resource');
            }

            foreach (self::READ_ONLY_FIELDS as $field) {
                unset($node->{$field});
            }
            $node->price = number_format((float) $priceList->getPrice(), 6, '.', '');
            $node->active = $product->isPublished() ? 1 : 0;

            $this->send('PUT', $base.'/products/'.$remoteId, $options + [
                'headers' => ['Content-Type' => 'application/xml'],
                'body' => $resource->asXML(),
            ], $product, $channel);
        } catch (HttpClientExceptionInterface $e) {
            throw new StoreSyncException(sprintf(
                'PrestaShop sync failed for SKU %s on channel "%s": %s',
                $product->getSku(),
                $channel->getName(),
                $e->getMessage(),
            ), previous: $e);
        }
    }

    private function findRemoteId(string $base, array $options, Product $product, StoreChannel $channel): int
    {
        $xml = $this->parseXml($this->send('GET', $base.'/products', $options + [
            'query' => ['filter[reference]' => '['.$product->getSku().']', 'display' => '[id]'],
        ], $product, $channel));

        $found = $xml->products->product ?? null;
        $id = $found ? (int) ($found->id ?? $found['id']) : 0;

        if ($id <= 0) {
            throw $this->failure($product, $channel, 'no product with this reference exists on the store');
        }

        return $id;
    }

    private function send(string $method, string $url, array $options, Product $product, StoreChannel $channel): string
    {
        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw $this->failure($product, $channel, sprintf('HTTP %d on %s', $status, $method));
        }

        return $response->getContent();
    }

    private function parseXml(string $body): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (false === $xml) {
            throw new StoreSyncException('PrestaShop returned a response that is not valid XML.');
        }

        return $xml;
    }

    private function requireApiKey(): string
    {
        if (null === $this->apiKey || '' === $this->apiKey) {
            throw new StoreSyncException('PRESTASHOP_API_KEY is not configured.');
        }

        return $this->apiKey;
    }

    private function failure(Product $product, StoreChannel $channel, string $reason): StoreSyncException
    {
        return new StoreSyncException(sprintf(
            'PrestaShop rejected sync for SKU %s on channel "%s": %s',
            $product->getSku(),
            $channel->getName(),
            $reason,
        ));
    }
}
