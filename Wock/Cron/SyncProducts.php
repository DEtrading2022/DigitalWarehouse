<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Cron;

use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Model\SyncLog;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron job: synchronise WoCK products with local Magento catalogue.
 *
 * Follows the WoCK best-practice "Querying by pooling" approach:
 *   - On first run (no last-sync date), fetches all products.
 *   - On subsequent runs, fetches only products updated since last sync.
 *
 * The last-sync timestamp is stored in core_config_data so it survives
 * cache flushes (unlike the previous cache-based approach).
 */
class SyncProducts
{
    private const CONFIG_LAST_SYNC = 'wock/internal/products_last_sync';

    public function __construct(
        private readonly Config                 $config,
        private readonly ProductServiceInterface $productService,
        private readonly ScopeConfigInterface   $scopeConfig,
        private readonly ConfigWriter           $configWriter,
        private readonly SyncLog                $syncLog,
        private readonly LoggerInterface        $logger,
        private readonly \DigitalWarehouse\Wock\Model\MappedProductIdsProvider $mappedIdsProvider,
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isProductsCronEnabled()) {
            return;
        }

        $mappedIds = $this->mappedIdsProvider->execute();
        if (empty($mappedIds)) {
            $this->logger->info('WoCK SyncProducts: skipping, no mapped products found in Magento.');
            return;
        }

        $startTime   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lastSyncRaw = $this->scopeConfig->getValue(self::CONFIG_LAST_SYNC);
        $totalCount  = 0;

        // Chunk API requests to avoid breaching max query length limits
        $chunks = array_chunk($mappedIds, 100);

        try {
            foreach ($chunks as $chunk) {
                if ($lastSyncRaw) {
                    $lastSync = new \DateTimeImmutable($lastSyncRaw, new \DateTimeZone('UTC'));
                    $this->logger->info('WoCK SyncProducts: incremental sync batch', [
                        'since'      => $lastSync->format(\DateTimeInterface::ATOM),
                        'batch_size' => count($chunk),
                    ]);
                    $products = $this->productService->getProductsByIdsAndUpdatedSince($chunk, $lastSync);
                } else {
                    $this->logger->info('WoCK SyncProducts: initial full sync batch', [
                        'batch_size' => count($chunk),
                    ]);
                    $products = $this->productService->getProductsByIds($chunk);
                }

                $count = count($products);
                $totalCount += $count;

                if ($count > 0) {
                    $this->processProducts($products);
                }
            }
        } catch (ApiException $e) {
            $this->logger->error('WoCK SyncProducts: API error', ['error' => $e->getMessage()]);
            $this->syncLog->error('product', null, 'sync', $e->getMessage());
            return;
        }

        $this->logger->info('WoCK SyncProducts: sync completed', [
            'total_mapped'   => count($mappedIds),
            'total_received' => $totalCount,
        ]);

        // Save the start time so the next run picks up any changes
        // that occurred while this run was executing
        $this->configWriter->save(
            self::CONFIG_LAST_SYNC,
            $startTime->format(\DateTimeInterface::ATOM)
        );

        $this->syncLog->success('product', null, 'sync', sprintf(
            'Processed %d updated products out of %d mapped', 
            $totalCount, 
            count($mappedIds)
        ));
    }

    /**
     * Clear the last-sync timestamp to force a full re-sync on next run.
     * Called by the CLI command with --full flag.
     */
    public function clearLastSync(): void
    {
        $this->configWriter->delete(self::CONFIG_LAST_SYNC);
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
                $productId = (string) ($product['id'] ?? 'unknown');
                $this->logger->error('WoCK SyncProducts: failed to process product', [
                    'product_id' => $productId,
                    'error'      => $e->getMessage(),
                ]);
                $this->syncLog->error('product', $productId, 'sync', $e->getMessage());
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
        $productId = (string) $product['id'];

        $this->logger->debug('WoCK SyncProducts: processing product', [
            'id'         => $product['id'],
            'name'       => $product['name'],
            'platform'   => $product['platform'],
            'region'     => $product['region'],
            'stock_all'  => $product['quantity']['all'] ?? 0,
            'disabled'   => $product['isDisabled'],
        ]);

        $this->syncLog->success('product', $productId, 'sync', sprintf(
            '%s | stock: %d | disabled: %s',
            $product['name'],
            $product['quantity']['all'] ?? 0,
            $product['isDisabled'] ? 'yes' : 'no'
        ));

        // TODO: implement catalogue upsert
        // $this->catalogSync->upsert($product);
    }
}
