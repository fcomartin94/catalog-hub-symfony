<?php

namespace App\Sync;

use App\Entity\StoreChannel;
use App\Repository\PriceListRepository;

/**
 * Entry point for syncing a channel's full catalog outward. Selects the
 * right connector for the channel's platform and pushes every PriceList
 * belonging to that channel, one product at a time.
 *
 * Failures are collected per-product rather than aborting the whole sync on
 * the first error — a channel with 50 products shouldn't fail the other 49
 * because one has a transient network error or a validation rejection on
 * the remote platform. The caller decides what to do with partial failures.
 */
final class StoreSyncService
{
    /**
     * @param iterable<StoreSyncConnectorInterface> $connectors
     */
    public function __construct(
        private readonly PriceListRepository $priceListRepository,
        private readonly PrestaShopSyncConnector $prestaShopConnector,
        private readonly WooCommerceSyncConnector $wooCommerceConnector,
    ) {
    }

    /**
     * @return array{synced: int, failed: array<int, string>}
     */
    public function syncChannel(StoreChannel $channel): array
    {
        $connector = $this->resolveConnector($channel);
        $priceLists = $this->priceListRepository->findBy(['channel' => $channel]);

        $synced = 0;
        $failed = [];

        foreach ($priceLists as $priceList) {
            try {
                $connector->push($priceList->getProduct(), $priceList);
                ++$synced;
            } catch (StoreSyncException $e) {
                $failed[] = $e->getMessage();
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    private function resolveConnector(StoreChannel $channel): StoreSyncConnectorInterface
    {
        return match ($channel->getPlatform()) {
            'prestashop' => $this->prestaShopConnector,
            'woocommerce' => $this->wooCommerceConnector,
            default => throw new StoreSyncException(sprintf(
                'No sync connector registered for platform "%s"',
                $channel->getPlatform(),
            )),
        };
    }
}
