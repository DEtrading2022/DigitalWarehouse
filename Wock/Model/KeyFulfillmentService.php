<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Model\Config;
use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Helper\OrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;

/**
 * Shared service for fetching WoCK delivery keys and emailing them to customers.
 *
 * Used by:
 *   - Cron/SyncOrders (batch processing of awaiting_delivery rows)
 *   - Controller/Adminhtml/Orders/FetchKey (manual single-row retrieval)
 *   - Controller/Adminhtml/Orders/SendKey  (manual re-send of stored key)
 */
class KeyFulfillmentService
{
    public function __construct(
        private readonly WockOrderKey              $wockOrderKey,
        private readonly OrderServiceInterface     $orderService,
        private readonly DeliveryServiceInterface  $deliveryService,
        private readonly OrderBuilder              $orderBuilder,
        private readonly OrderRepositoryInterface  $orderRepository,
        private readonly TransportBuilder          $transportBuilder,
        private readonly StoreManagerInterface     $storeManager,
        private readonly ScopeConfigInterface      $scopeConfig,
        private readonly Config                    $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface           $logger,
    ) {}

    // ── Key retrieval ──────────────────────────────────────────────────

    /**
     * Fetch and store the WoCK delivery key for a single key row.
     *
     * If the row does not yet have a wock_order_id (status = pending), a WoCK
     * order is placed first and the ID saved.  Then the delivery is polled once.
     *
     * Returns a result array:
     *   ['success' => true,  'key' => '...', 'status' => 'fulfilled']
     *   ['success' => false, 'message' => '...']
     *
     * @param  array<string, mixed> $keyRow
     * @return array<string, mixed>
     */
    public function fetchKeyForRow(array $keyRow): array
    {
        $keyId   = (int) $keyRow['key_id'];
        $orderId = (int) $keyRow['order_id'];

        // ── Step 1: place a WoCK order if we don't have one yet ──────────
        $wockOrderId = (string) ($keyRow['wock_order_id'] ?? '');

        if (empty($wockOrderId)) {
            $placeResult = $this->placeWockOrder($keyRow);
            if (!$placeResult['success']) {
                return $placeResult;
            }
            $wockOrderId = $placeResult['wock_order_id'];
        }

        // ── Step 2: fetch the delivery ────────────────────────────────────
        try {
            $delivery = $this->deliveryService->getDelivery($wockOrderId);
        } catch (ApiException $e) {
            $this->wockOrderKey->markError($keyId, 'Delivery fetch failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Delivery fetch failed: ' . $e->getMessage()];
        }

        $ready = $delivery['status']['ready'] ?? false;
        $error = $delivery['status']['error'] ?? null;

        if ($error) {
            $this->wockOrderKey->markError($keyId, 'Delivery error: ' . $error);
            return ['success' => false, 'message' => 'Delivery error: ' . $error];
        }

        if (!$ready) {
            return [
                'success' => false,
                'message' => 'Delivery not ready yet — WoCK is still processing. Try again in a few seconds.',
            ];
        }

        // ── Step 3: extract and store keys ────────────────────────────────
        $keyStrings = [];
        foreach ($delivery['products'] ?? [] as $dp) {
            foreach ($dp['keys'] ?? [] as $keyData) {
                $value = trim((string) ($keyData['key'] ?? ''));
                if ($value !== '') {
                    $keyStrings[] = $value;
                }
            }
        }

        if (empty($keyStrings)) {
            return ['success' => false, 'message' => 'Delivery was ready but contained no keys.'];
        }

        $productKey = implode("\n", $keyStrings);
        $this->wockOrderKey->fulfill($keyId, $productKey, $wockOrderId);

        $this->logger->info('WoCK KeyFulfillmentService: key stored', [
            'key_id'        => $keyId,
            'wock_order_id' => $wockOrderId,
            'key_count'     => count($keyStrings),
        ]);

        return [
            'success'  => true,
            'key'      => $productKey,
            'status'   => 'fulfilled',
            'key_count' => count($keyStrings),
        ];
    }

    /**
     * Place a WoCK API order for a key row and save the returned wock_order_id.
     *
     * @param  array<string, mixed> $keyRow
     * @return array<string, mixed>
     */
    private function placeWockOrder(array $keyRow): array
    {
        $keyId         = (int)    $keyRow['key_id'];
        $wockProductId = (int)    $keyRow['wock_product_id'];
        $qty           = (int)    $keyRow['qty'];
        $orderId       = (int)    $keyRow['order_id'];
        $incrementId   = (string) $keyRow['order_increment_id'];

        // Resolve unit price from the Magento order item
        $unitPrice = 0.0;
        try {
            $magentoOrder = $this->orderRepository->get($orderId);
            foreach ($magentoOrder->getAllVisibleItems() as $item) {
                if ((int) $item->getItemId() === (int) $keyRow['order_item_id']) {
                    $unitPrice = (float) $item->getPrice();
                    break;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('WoCK KeyFulfillmentService: could not load order for unit price', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }

        try {
            $this->orderBuilder->reset();
            $this->orderBuilder->setPartnerOrderId($incrementId . '-' . $keyId);
            $this->orderBuilder->addProduct(
                productId: $wockProductId,
                quantity:  $qty,
                unitPrice: $unitPrice
            );

            $result      = $this->orderService->createOrder($this->orderBuilder->build());
            $wockOrderId = $result['orderId'] ?? null;

            if (!$wockOrderId) {
                $msg = 'WoCK API createOrder returned no orderId.';
                $this->wockOrderKey->markCreateOrderFailed($keyId, $msg);
                return ['success' => false, 'message' => $msg];
            }

            $this->wockOrderKey->markWockOrderPlaced($keyId, $wockOrderId);

            $this->logger->info('WoCK KeyFulfillmentService: WoCK order placed', [
                'key_id'        => $keyId,
                'wock_order_id' => $wockOrderId,
            ]);

            return ['success' => true, 'wock_order_id' => $wockOrderId];

        } catch (\Throwable $e) {
            // Keep status as 'pending' — may be transient (out of stock, price mismatch).
            // The cron will retry; the admin can also use the Fetch Key button.
            $msg = 'Order placement failed: ' . $e->getMessage();
            $this->wockOrderKey->markCreateOrderFailed($keyId, $msg);
            return ['success' => false, 'message' => $msg];
        }
    }

    // ── Email sending ──────────────────────────────────────────────────

    /**
     * Send the stored product key(s) for a single key row to the customer.
     *
     * Returns a result array:
     *   ['success' => true]
     *   ['success' => false, 'message' => '...']
     *
     * @param  array<string, mixed> $keyRow
     * @return array<string, mixed>
     */
    public function sendKeyEmail(array $keyRow): array
    {
        if (($keyRow['status'] ?? '') !== 'fulfilled') {
            return [
                'success' => false,
                'message' => 'Key has not been fulfilled yet (status: ' . ($keyRow['status'] ?? 'unknown') . '). Fetch it first.',
            ];
        }

        $orderId = (int) $keyRow['order_id'];

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Could not load Magento order: ' . $e->getMessage()];
        }

        $fulfilledKeys = [[
            'product_name'    => (string) ($keyRow['product_name'] ?? ''),
            'wock_product_id' => (int)    $keyRow['wock_product_id'],
            'product_key'     => (string) $keyRow['product_key'],
            'qty'             => (int)    $keyRow['qty'],
        ]];

        try {
            $this->dispatchEmail($order, $fulfilledKeys);
        } catch (\Throwable $e) {
            $this->logger->error('WoCK KeyFulfillmentService: email send failed', [
                'key_id' => $keyRow['key_id'],
                'error'  => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Email failed: ' . $e->getMessage()];
        }

        $this->logger->info('WoCK KeyFulfillmentService: key email sent manually', [
            'key_id'    => $keyRow['key_id'],
            'order'     => $order->getIncrementId(),
            'email'     => $order->getCustomerEmail(),
        ]);

        return ['success' => true];
    }

    /**
     * Send keys to the customer for a batch of fulfilled key rows (used by cron).
     *
     * @param OrderInterface                   $order
     * @param array<int, array<string, mixed>> $fulfilledKeys
     */
    public function sendBatchEmail(OrderInterface $order, array $fulfilledKeys): void
    {
        $this->dispatchEmail($order, $fulfilledKeys);
    }

    /**
     * Build and dispatch the keys email via Magento's TransportBuilder.
     *
     * @param array<int, array<string, mixed>> $fulfilledKeys
     */
    private function dispatchEmail(OrderInterface $order, array $fulfilledKeys): void
    {
        $storeId       = (int) $order->getStoreId();
        $customerEmail = $order->getCustomerEmail();

        if (!$customerEmail) {
            throw new \RuntimeException('Order ' . $order->getIncrementId() . ' has no customer email address.');
        }

        // ── Build key rows (dark-themed inline HTML for email) ──────────
        $keysHtml = '';
        foreach ($fulfilledKeys as $keyData) {
            $keyLines  = array_filter(array_map('trim', explode("\n", (string) $keyData['product_key'])));
            // Neutral teal palette — readable on both dark-navy and white email backgrounds
            $keyBlocks = implode('', array_map(
                static fn(string $k) => '<div style="margin:4px 0;">'
                    . '<code class="key-mono" style="display:block;'
                    . 'font-family:\'Courier New\',Courier,monospace;'
                    . 'font-size:15px;font-weight:700;letter-spacing:2px;'
                    . 'color:#0c7a8a;background:#e8f9fc;'
                    . 'border:1px solid #a5e8f2;'
                    . 'border-radius:4px;padding:8px 12px;word-break:break-all;">'
                    . htmlspecialchars($k, ENT_QUOTES, 'UTF-8')
                    . '</code></div>',
                $keyLines
            ));

            // Row uses CSS-property-safe neutrals — border and text work on dark and light cards
            $keysHtml .= '<tr>';
            $keysHtml .= '<td style="padding:14px 16px;border-bottom:1px solid #d1e4e8;'
                . 'font-family:\'Segoe UI\',Helvetica,Arial,sans-serif;'
                . 'font-size:14px;color:#1e293b;vertical-align:top;">'
                . htmlspecialchars((string) $keyData['product_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $keysHtml .= '<td style="padding:14px 10px;border-bottom:1px solid #d1e4e8;'
                . 'font-family:\'Segoe UI\',Helvetica,Arial,sans-serif;'
                . 'font-size:14px;color:#64748b;text-align:center;vertical-align:top;">'
                . (int) $keyData['qty'] . '</td>';
            $keysHtml .= '<td style="padding:14px 16px;border-bottom:1px solid #d1e4e8;vertical-align:top;">'
                . $keyBlocks . '</td>';
            $keysHtml .= '</tr>';
        }

        // ── Build related products (from first fulfilled Magento product ID) ──
        $relatedHtml = '';
        try {
            $relatedHtml = $this->buildRelatedProductsHtml($order, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('WoCK: could not build related products for email', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }

        $senderIdentity = $this->scopeConfig->getValue(
            'trans_email/ident_sales/email',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ? 'sales' : 'general';

        $customerName = trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());

        $vars = [
            'order'                  => $order,
            'increment_id'           => $order->getIncrementId(),
            'customer_name'          => $customerName,
            'keys_html'              => $keysHtml,
            'store'                  => $this->storeManager->getStore($storeId),
        ];
        if (!empty($relatedHtml)) {
            $vars['related_products_html'] = $relatedHtml;
        }

        $transport = $this->transportBuilder
            ->setTemplateIdentifier($this->config->getKeyEmailTemplate())
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars($vars)
            ->setFromByScope($senderIdentity, $storeId)
            ->addTo($customerEmail, $customerName)
            ->getTransport();

        $transport->sendMessage();
    }
    /**
     * Build up to 3 related product cells for the email.
     * Scans all visible order items, loads their Magento products, and collects
     * up to 3 related products that are not already in the order.
     *
     * Returns a string of <td> cells (one per product) for the 3-column row in
     * the email template, or empty string if none found.
     */
    private function buildRelatedProductsHtml(OrderInterface $order, int $storeId): string
    {
        // Collect product IDs in this order so we can exclude them
        $orderedIds = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $orderedIds[(int) $item->getProductId()] = true;
        }

        $related = [];

        foreach ($order->getAllVisibleItems() as $item) {
            if (count($related) >= 3) {
                break;
            }

            $productId = (int) $item->getProductId();
            if (!$productId) {
                continue;
            }

            try {
                $product = $this->productRepository->getById($productId, false, $storeId);
            } catch (\Exception $e) {
                continue;
            }

            foreach ($product->getRelatedProducts() as $rp) {
                if (count($related) >= 3) {
                    break;
                }
                $rpId = (int) $rp->getId();
                if (isset($orderedIds[$rpId]) || isset($related[$rpId])) {
                    continue;
                }
                if ((int) $rp->getStatus() !== 1) {
                    continue;
                }
                $related[$rpId] = $rp;
            }
        }

        if (empty($related)) {
            return '';
        }

        $storeBase = $this->storeManager->getStore($storeId)->getBaseUrl();
        $mediaBase = $this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        $html = '';
        foreach (array_values($related) as $rp) {
            $name    = htmlspecialchars((string) $rp->getName(), ENT_QUOTES, 'UTF-8');
            $rawUrl  = $storeBase . $rp->getUrlKey() . '.html';
            // Whitelist http/https to prevent javascript: injection via crafted URL keys
            $url     = preg_match('#^https?://#i', $rawUrl)
                ? htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8')
                : htmlspecialchars($storeBase, ENT_QUOTES, 'UTF-8'); // fall back to store root

            // Thumbnail — use small_image or placeholder
            $imgFile    = $rp->getSmallImage();
            if ($imgFile && $imgFile !== 'no_selection') {
                $imgUrl = htmlspecialchars(
                    $mediaBase . 'catalog/product' . $imgFile,
                    ENT_QUOTES, 'UTF-8'
                );
            } else {
                // Generic dark placeholder
                $imgUrl = 'https://via.placeholder.com/160x100/111122/4a5568?text=Game';
            }

            // Price
            $price = $rp->getFinalPrice();
            $priceFormatted = $price > 0
                ? '&euro;' . number_format((float) $price, 2)
                : '';

            $html .= '<td class="rp-cell" valign="top"'
                . ' style="width:33.33%;padding:0 6px;text-align:center;vertical-align:top;">';
            $html .= '<a href="' . $url . '" style="display:block;text-decoration:none;">';
            $html .= '<img class="rp-img" src="' . $imgUrl . '" alt="' . $name . '"'
                . ' width="160" height="100"'
                . ' style="width:100%;max-width:160px;height:100px;object-fit:cover;'
                . 'border-radius:6px;border:1px solid #1e1e38;display:block;margin:0 auto 10px;">';
            $html .= '<span style="display:block;font-family:\'Segoe UI\',Helvetica,Arial,sans-serif;'
                . 'font-size:13px;font-weight:600;color:#c8d3e8;line-height:1.4;margin-bottom:6px;">'
                . $name . '</span>';
            if ($priceFormatted) {
                $html .= '<span style="display:inline-block;padding:3px 10px;'
                    . 'background:rgba(124,58,237,0.15);border:1px solid rgba(124,58,237,0.3);'
                    . 'border-radius:4px;font-family:\'Segoe UI\',Helvetica,Arial,sans-serif;'
                    . 'font-size:12px;font-weight:700;color:#a78bfa;">'
                    . $priceFormatted . '</span>';
            }
            $html .= '</a>';
            $html .= '</td>';
        }

        return $html;
    }
}
