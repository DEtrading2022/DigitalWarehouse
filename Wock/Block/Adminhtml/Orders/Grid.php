<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Block\Adminhtml\Orders;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use DigitalWarehouse\Wock\Model\WockOrderKey;

/**
 * Provides WoCK order key data and action URLs to the admin grid template.
 */
class Grid extends Template
{
    public function __construct(
        Context $context,
        private readonly WockOrderKey $wockOrderKey,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get all WoCK order key rows for the grid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderKeys(): array
    {
        return $this->wockOrderKey->getAll();
    }

    /**
     * Get store name from store ID.
     */
    public function getStoreName(int $storeId): string
    {
        try {
            $store   = $this->storeManager->getStore($storeId);
            $website = $this->storeManager->getWebsite($store->getWebsiteId());
            return $website->getName() . ' / ' . $store->getName();
        } catch (\Exception $e) {
            return 'Store #' . $storeId;
        }
    }

    /**
     * Get the CSS class for a status badge.
     */
    public function getStatusClass(string $status, bool $hasError = false): string
    {
        return match (true) {
            $status === 'fulfilled'          => 'wock-status-fulfilled',
            $status === 'awaiting_delivery'  => 'wock-status-awaiting',
            $status === 'error'              => 'wock-status-error',
            $status === 'pending' && $hasError => 'wock-status-retrying',
            default                          => 'wock-status-pending',
        };
    }

    /**
     * Return a human-readable status label.
     */
    public function getStatusLabel(string $status, bool $hasError = false): string
    {
        return match (true) {
            $status === 'fulfilled'                => 'Fulfilled',
            $status === 'awaiting_delivery'        => 'Awaiting Delivery',
            $status === 'error'                    => 'Error',
            $status === 'pending' && $hasError     => 'Pending (retrying)',
            default                                => 'Pending',
        };
    }

    /**
     * Get the Magento admin order view URL.
     */
    public function getOrderViewUrl(int $orderId): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * Get the AJAX URL for fetching a WoCK key row.
     */
    public function getFetchKeyUrl(): string
    {
        return $this->getUrl('wock/orders/fetchKey');
    }

    /**
     * Get the AJAX URL for sending a key email.
     */
    public function getSendKeyUrl(): string
    {
        return $this->getUrl('wock/orders/sendKey');
    }
}
