<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Cron;

use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron job: synchronise WoCK products with local Magento catalogue.
 *
 * Follows the WoCK best-practice "Querying by pooling" approach:
 *   - On first run (no last-sync date), fetches all products.
 *   - On subsequent runs, fetches only products updated since last sync.
 *
 * The last-sync timestamp is stored in a Magento cache entry so it
 * survives across requests without requiring a database table.
 */
class SyncProducts
{
    private const LAST_SYNC_CACHE_KEY = 'WOCK_PRODUCTS_LAST_SYNC';
    private const CACHE_TAG           = 'WOCK';

    public function __construct(
        private readonly Config                 $config,
        private readonly ProductServiceInterface $productService,
        private readonly TypeListInterface      $cacheTypeList,
        private readonly \Magento\Framework\App\CacheInterface $cache,
        private readonly LoggerInterface        $logger,
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isProductsCronEnabled()) {
            return;
        }

        $startTime   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lastSyncRaw = $this->cache->load(self::LAST_SYNC_CACHE_KEY);

        try {
            if ($lastSyncRaw) {
                $lastSync = new \DateTimeImmutable($lastSyncRaw, new \DateTimeZone('UTC'));
                $this->logger->info('WoCK SyncProducts: incremental sync', [
                    'since' => $lastSync->format(\DateTimeInterface::ATOM),
                ]);
                $products = $this->productService->getProductsUpdatedSince($lastSync);
            } else {
                $this->logger->info('WoCK SyncProducts: initial full sync');
                $products = $this->productService->getAllProducts();
            }
        } catch (ApiException $e) {
            $this->logger->error('WoCK SyncProducts: API error', ['error' => $e->getMessage()]);
            return;
        }

        $count = count($products);
        $this->logger->info('WoCK SyncProducts: received products', ['count' => $count]);

        if ($count > 0) {
            $this->processProducts($products);
        }

        // Save the start time of this run so the next run picks up any
        // changes that occurred while this run was executing
        $this->cache->save(
            $startTime->format(\DateTimeInterface::ATOM),
            self::LAST_SYNC_CACHE_KEY,
            [self::CACHE_TAG],
            86400 * 30 // 30 days
        );
    }

    /**
     * Process a batch of products retrieved from WoCK.
     *
     * @param array<int, array<string, mixed>> $products
     */
    private function processProducts(array $products): void
    {
        foreach ($products as $product) {
            try {
                $this->processSingleProduct($product);
            } catch (\Throwable $e) {
                $this->logger->error('WoCK SyncProducts: failed to process product', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Persist a single WoCK product into Magento.
     *
     * @param array<string, mixed> $product
     *
     * TODO: Implement your catalogue upsert logic here.
     *
     * Suggested approach:
     *   - Map WoCK product fields to Magento product attributes
     *   - Use ProductRepositoryInterface to save / update
     *   - Update stock via StockItemRepositoryInterface or MSI SourceItemsSave
     *   - Disable product when $product['isDisabled'] === true
     *   - Use $product['bulkPrices'] for tier pricing
     */
    private function processSingleProduct(array $product): void
    {
        $this->logger->debug('WoCK SyncProducts: processing product', [
            'id'         => $product['id'],
            'name'       => $product['name'],
            'platform'   => $product['platform'],
            'region'     => $product['region'],
            'stock_all'  => $product['quantity']['all'] ?? 0,
            'disabled'   => $product['isDisabled'],
        ]);

        // TODO: implement catalogue upsert
        // $this->catalogSync->upsert($product);
    }
}
