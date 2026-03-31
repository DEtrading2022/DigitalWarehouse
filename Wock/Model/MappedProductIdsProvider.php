<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Retrieves an array of all WoCK Product IDs that are currently mapped
 * to active products in the Magento catalogue.
 */
class MappedProductIdsProvider
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
    ) {}

    /**
     * Get a list of WoCK Product IDs mapped in the Magento catalog.
     *
     * @return int[]
     */
    public function execute(): array
    {
        $collection = $this->collectionFactory->create();
        
        // Filter products where is_wock_product toggle is Yes
        $collection->addAttributeToFilter('is_wock_product', 1);
        
        // Filter products where wock_product_id is not null
        $collection->addAttributeToFilter('wock_product_id', ['notnull' => true]);

        // Only select the wock_product_id attribute to keep the query extremely lightweight
        $collection->addAttributeToSelect('wock_product_id');

        $ids = [];
        foreach ($collection as $product) {
            $wockId = (int) $product->getData('wock_product_id');
            if ($wockId > 0) {
                $ids[] = $wockId;
            }
        }

        // Return unique values to prevent duplicates
        return array_unique($ids);
    }
}
