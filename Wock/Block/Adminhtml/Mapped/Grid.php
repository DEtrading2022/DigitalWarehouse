<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Block\Adminhtml\Mapped;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Model\Config;

/**
 * Provides the mapped products data to the grid template,
 * enriched with live WoCK product name and price.
 */
class Grid extends Template
{
    /**
     * Indexed cache of WoCK product data keyed by WoCK product ID.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $wockProducts = null;

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductServiceInterface $productService,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Retrieves all Magento products actively mapped to a WoCK product.
     *
     * @return Collection
     */
    public function getMappedProducts(): Collection
    {
        $collection = $this->collectionFactory->create();

        $collection->addAttributeToSelect(['name', 'wock_product_id', 'wock_platform']);
        $collection->addAttributeToFilter('is_wock_product', 1);
        $collection->addAttributeToFilter('wock_product_id', ['notnull' => true]);

        // Sorting by Name
        $collection->setOrder('name', Collection::SORT_ORDER_ASC);

        return $collection;
    }

    /**
     * Loads WoCK product data for the given mapped product collection (lazy, once).
     *
     * @param Collection $magentoProducts
     * @return void
     */
    public function loadWockData(Collection $magentoProducts): void
    {
        if ($this->wockProducts !== null) {
            return;
        }

        $this->wockProducts = [];

        if (!$this->config->isEnabled()) {
            return;
        }

        // Collect all unique WoCK product IDs from the Magento collection
        $wockIds = [];
        foreach ($magentoProducts as $product) {
            $wockId = (int) $product->getData('wock_product_id');
            if ($wockId > 0) {
                $wockIds[$wockId] = true;
            }
        }

        if (empty($wockIds)) {
            return;
        }

        try {
            $apiProducts = $this->productService->getProductsByIds(array_keys($wockIds));
            foreach ($apiProducts as $apiProduct) {
                $id = (int) ($apiProduct['id'] ?? 0);
                if ($id > 0) {
                    $this->wockProducts[$id] = $apiProduct;
                }
            }
        } catch (\Exception $e) {
            // Silently degrade — columns will show '—' if the API is unreachable
        }
    }

    /**
     * Returns the WoCK product name for the given WoCK product ID.
     *
     * @param int|string|null $wockProductId
     * @return string
     */
    public function getWockProductName($wockProductId): string
    {
        $id = (int) $wockProductId;
        return (string) ($this->wockProducts[$id]['name'] ?? '—');
    }

    /**
     * Returns the lowest WoCK bulk price for the given WoCK product ID.
     * Falls back to '—' if no pricing data is available.
     *
     * @param int|string|null $wockProductId
     * @return string
     */
    public function getWockProductPrice($wockProductId): string
    {
        $id = (int) $wockProductId;
        $product = $this->wockProducts[$id] ?? null;

        if ($product === null) {
            return '—';
        }

        $bulkPrices = $product['bulkPrices'] ?? [];
        if (empty($bulkPrices)) {
            return '—';
        }

        // Get the price with the lowest minimumQuantity (i.e. the base / unit price)
        $lowestQtyPrice = null;
        $lowestQty = PHP_INT_MAX;
        foreach ($bulkPrices as $tier) {
            $qty = (int) ($tier['minimumQuantity'] ?? PHP_INT_MAX);
            if ($qty < $lowestQty) {
                $lowestQty = $qty;
                $lowestQtyPrice = $tier['price'] ?? null;
            }
        }

        if ($lowestQtyPrice === null) {
            return '—';
        }

        $currency = $product['currency'] ?? '';
        return $currency
            ? sprintf('%s %.2f', $currency, (float) $lowestQtyPrice)
            : sprintf('%.2f', (float) $lowestQtyPrice);
    }
}
