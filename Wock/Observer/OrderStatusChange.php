<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Helper\OrderBuilder;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Model\WockOrderKey;
use Psr\Log\LoggerInterface;

/**
 * Listens to sales_order_save_after.
 *
 * When a Magento order's status changes to the configured fulfillment
 * status (e.g. "complete"), this observer:
 *
 * 1. Finds all pending WoCK key rows for the order.
 * 2. Places a WoCK order via the API for each pending item.
 * 3. Polls/retrieves the delivery keys.
 * 4. Updates the wock_order_keys rows with the real keys.
 * 5. Sends the keys to the customer by email.
 */
class OrderStatusChange implements ObserverInterface
{
    public function __construct(
        private readonly WockOrderKey             $wockOrderKey,
        private readonly OrderServiceInterface    $orderService,
        private readonly DeliveryServiceInterface $deliveryService,
        private readonly OrderBuilder             $orderBuilder,
        private readonly Config                   $config,
        private readonly TransportBuilder         $transportBuilder,
        private readonly StoreManagerInterface    $storeManager,
        private readonly ScopeConfigInterface     $scopeConfig,
        private readonly LoggerInterface          $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $fulfillmentStatus = $this->config->getOrderFulfillmentStatus();
        if (empty($fulfillmentStatus)) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getId()) {
            return;
        }

        // Only act when the order reaches the configured fulfillment status
        if ($order->getStatus() !== $fulfillmentStatus) {
            return;
        }

        // Check if we have pending WoCK key rows
        $pendingKeys = $this->wockOrderKey->getPendingByOrderId((int) $order->getId());
        if (empty($pendingKeys)) {
            return;
        }

        $this->logger->info('WoCK OrderStatusChange: fulfilling keys', [
            'order'  => $order->getIncrementId(),
            'status' => $fulfillmentStatus,
            'count'  => count($pendingKeys),
        ]);

        $fulfilledKeys = [];

        foreach ($pendingKeys as $keyRow) {
            try {
                $fulfilledKey = $this->fulfillSingleKey($keyRow, $order);
                if ($fulfilledKey !== null) {
                    $fulfilledKeys[] = $fulfilledKey;
                }
            } catch (\Throwable $e) {
                $this->logger->error('WoCK OrderStatusChange: fulfillment failed for key_id ' . $keyRow['key_id'], [
                    'order' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);

                $this->wockOrderKey->markError(
                    (int) $keyRow['key_id'],
                    'API Error: ' . mb_substr($e->getMessage(), 0, 480)
                );
            }
        }

        // Send email with all fulfilled keys
        if (!empty($fulfilledKeys)) {
            $this->sendKeysEmail($order, $fulfilledKeys);
        }
    }

    /**
     * Place a WoCK order for a single key row, retrieve the delivery key,
     * and update the database row.
     *
     * @return array<string, mixed>|null Fulfilled key data or null on failure
     */
    private function fulfillSingleKey(array $keyRow, Order $order): ?array
    {
        $wockProductId = (int) $keyRow['wock_product_id'];
        $qty           = (int) $keyRow['qty'];
        $keyId         = (int) $keyRow['key_id'];

        // Build the unit price: fetch the product's current WoCK cost price
        // We use price 0 here because createOrder requires a unitPrice;
        // the API will validate it against the current product price.
        // In practice the price was synced to the Magento product on save.
        $product = null;
        foreach ($order->getAllVisibleItems() as $item) {
            if ((int) $item->getItemId() === (int) $keyRow['order_item_id']) {
                $product = $item;
                break;
            }
        }

        $unitPrice = $product ? (float) $product->getPrice() : 0.0;

        // 1. Create WoCK order
        $this->orderBuilder->reset();
        $this->orderBuilder->setPartnerOrderId($order->getIncrementId() . '-' . $keyId);
        $this->orderBuilder->addProduct(
            productId: $wockProductId,
            quantity:  $qty,
            unitPrice: $unitPrice
        );

        $wockOrderResult = $this->orderService->createOrder($this->orderBuilder->build());
        $wockOrderId     = $wockOrderResult['orderId'] ?? null;

        if (!$wockOrderId) {
            $this->wockOrderKey->markError($keyId, 'WoCK createOrder returned no orderId');
            $this->logger->error('WoCK OrderStatusChange: createOrder returned no orderId', [
                'key_id' => $keyId,
            ]);
            return null;
        }

        $this->logger->info('WoCK OrderStatusChange: WoCK order created', [
            'key_id'        => $keyId,
            'wock_order_id' => $wockOrderId,
        ]);

        // 2. Retrieve delivery (keys)
        //    The API may need a short delay; we retry up to 3 times
        $deliveryKeys = [];
        $maxRetries   = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $delivery = $this->deliveryService->getDelivery(orderId: $wockOrderId);

                $ready = $delivery['status']['ready'] ?? false;
                $error = $delivery['status']['error'] ?? null;

                if ($error) {
                    $this->wockOrderKey->markError($keyId, 'Delivery error: ' . $error);
                    $this->logger->error('WoCK delivery error', [
                        'wock_order_id' => $wockOrderId,
                        'error'         => $error,
                    ]);
                    return null;
                }

                if ($ready && !empty($delivery['products'])) {
                    foreach ($delivery['products'] as $dp) {
                        foreach (($dp['keys'] ?? []) as $keyData) {
                            $deliveryKeys[] = $keyData['key'] ?? '(no key)';
                        }
                    }
                    break;
                }

                // Not ready yet, wait 2 seconds before retry
                if ($attempt < $maxRetries) {
                    sleep(2);
                }
            } catch (\Exception $e) {
                $this->logger->warning('WoCK delivery attempt ' . $attempt . ' failed', [
                    'wock_order_id' => $wockOrderId,
                    'error'         => $e->getMessage(),
                ]);
                if ($attempt < $maxRetries) {
                    sleep(2);
                }
            }
        }

        // Build the final key string
        $finalKey = !empty($deliveryKeys)
            ? implode(', ', $deliveryKeys)
            : 'AWAITING-DELIVERY-' . $wockOrderId;

        $finalStatus = !empty($deliveryKeys) ? 'fulfilled' : 'pending';

        if ($finalStatus === 'fulfilled') {
            $this->wockOrderKey->fulfill($keyId, $finalKey, $wockOrderId);
        } else {
            // Still pending but we have the WoCK order ID now
            $this->wockOrderKey->fulfill($keyId, $finalKey, $wockOrderId);
        }

        $this->logger->info('WoCK OrderStatusChange: key row updated', [
            'key_id'    => $keyId,
            'status'    => $finalStatus,
            'key_count' => count($deliveryKeys),
        ]);

        return [
            'product_name'    => $keyRow['product_name'],
            'wock_product_id' => $wockProductId,
            'product_key'     => $finalKey,
            'qty'             => $qty,
        ];
    }

    /**
     * Send the fulfilled product keys to the customer via email.
     *
     * @param Order $order
     * @param array<int, array<string, mixed>> $fulfilledKeys
     */
    private function sendKeysEmail(Order $order, array $fulfilledKeys): void
    {
        try {
            $storeId      = (int) $order->getStoreId();
            $customerEmail = $order->getCustomerEmail();

            if (!$customerEmail) {
                $this->logger->warning('WoCK: no customer email for order ' . $order->getIncrementId());
                return;
            }

            $senderIdentity = $this->scopeConfig->getValue(
                'trans_email/ident_sales/email',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ) ? 'sales' : 'general';

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('wock_order_keys_email')
                ->setTemplateOptions([
                    'area'  => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'order'          => $order,
                    'increment_id'   => $order->getIncrementId(),
                    'customer_name'  => $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname(),
                    'fulfilled_keys' => $fulfilledKeys,
                    'store'          => $this->storeManager->getStore($storeId),
                ])
                ->setFromByScope($senderIdentity, $storeId)
                ->addTo($customerEmail, $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname())
                ->getTransport();

            $transport->sendMessage();

            $this->logger->info('WoCK: keys email sent', [
                'order'     => $order->getIncrementId(),
                'email'     => $customerEmail,
                'key_count' => count($fulfilledKeys),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('WoCK: failed to send keys email', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
