<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Block\Adminhtml\Mapped;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Provides the mapped products data to the grid template.
 */
class Grid extends Template
{
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
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
}
