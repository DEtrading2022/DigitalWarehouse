# DigitalWarehouse_Wock

Magento **2.4.8** integration module for the **World of CD Keys (WoCK)** GraphQL API.

---

## Features

| Feature | Details |
|---|---|
| **Authentication** | Azure AD client-credentials flow (OAuth 2.0). Token cached in Magento cache with configurable TTL. Auto-refreshes on 401. |
| **Products** | Full & incremental sync via `products` query (pagination, `lastUpdateDateTime` filter). |
| **Orders** | Create orders (`createOrder` mutation) with fluent `OrderBuilder` helper. Cancel orders (`deleteOrder`). |
| **Deliveries** | Fetch delivery keys (`delivery` query) including nested `subKeys` for DLC/bundles. Text and image key decoding via `KeyDecoder` helper. |
| **Partner** | `partner` query to check credit line / available balance. |
| **Webhooks** | `POST wock/webhook/product` and `POST wock/webhook/delivery` endpoints. Secret-header validation. Fires Magento events for downstream observers. |
| **Cron** | Configurable cron for product and order polling. Follows WoCK best-practices (incremental by `lastUpdateDateTime`). |
| **DB tables** | `wock_sync_log` (activity log) and `wock_order_map` (Magento ↔ WoCK order cross-reference). |
| **CLI** | `wock:sync:products`, `wock:sync:orders`, `wock:test:connection` |
| **Logging** | Dedicated `var/log/wock.log` channel via a Monolog virtual type. |

---

## Requirements

- PHP **8.1+**
- Magento Open Source / Adobe Commerce **2.4.8**

---

## Installation

### Via Composer (recommended)

```bash
composer require digitalwarehouse/module-wock
bin/magento module:enable DigitalWarehouse_Wock
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual

1. Copy this directory to `app/code/DigitalWarehouse/Wock/`
2. Run the setup commands above.

---

## Configuration

**Stores → Configuration → Digital Warehouse → WoCK**

### General

| Field | Description |
|---|---|
| Enabled | Toggle the entire integration on/off |
| Environment | `Sandbox` (staging) or `Production` |
| Sandbox Endpoint | Default: `https://api.stage.wock.digitalwarehou.se/graphql` |
| Production Endpoint | Default: `https://api.worldofcdkeys.com/graphql` |
| Token Cache TTL | How long (seconds) to cache the Azure AD token. Default: 300 |

### Azure AD Authentication

All values are provided by the WoCK helpdesk per environment.

| Field | Description |
|---|---|
| Access Token URL | Full Azure AD token endpoint URL |
| Tenant ID | Azure AD tenant GUID |
| Client ID | OAuth2 client ID |
| Client Secret | OAuth2 client secret (stored encrypted) |
| Scope | OAuth2 scope string |

> **Tip:** Leave *Access Token URL* empty and only fill *Tenant ID* — the module
> will build the default Azure AD URL automatically.

### Sync

| Field | Description |
|---|---|
| Enable Product Sync Cron | Enables the product polling cron job |
| Product Sync Cron Expression | Default: `*/5 * * * *` (every 5 minutes) |
| Enable Order Sync Cron | Enables the order polling cron job |
| Order Sync Cron Expression | Default: `*/10 * * * *` (every 10 minutes) |
| Page Size | Items per API page (max recommended: 100) |

### Webhooks

| Field | Description |
|---|---|
| Secret Header Name | HTTP header WoCK must include (e.g. `X-Webhook-Secret`) |
| Secret Header Value | Expected value (stored encrypted). Leave blank to disable validation. |

Register these two URLs in the WoCK system (via your account manager):

```
Product webhook:  https://your-store.com/wock/webhook/product
Delivery webhook: https://your-store.com/wock/webhook/delivery
```

Webhook endpoints respond with **HTTP 204 No Content** as required by WoCK.

---

## CLI Commands

```bash
# Test connectivity and credentials
bin/magento wock:test:connection

# Run product sync (incremental by default)
bin/magento wock:sync:products

# Force a full product re-sync (ignores last-sync timestamp)
bin/magento wock:sync:products --full

# Run order/delivery sync
bin/magento wock:sync:orders
```

---

## Developer Guide

### Architecture

```
Api/                    ← PHP interfaces (contracts)
  TokenManagerInterface
  ProductServiceInterface
  OrderServiceInterface
  DeliveryServiceInterface
  PartnerServiceInterface

Model/
  Config.php            ← All config getters
  SyncLog.php           ← DB activity logger
  OrderMap.php          ← Magento↔WoCK order cross-reference
  Token/
    Manager.php         ← Azure AD OAuth2 + Magento cache
  Api/
    Client.php          ← GraphQL HTTP client (curl, error handling)
    ProductService.php  ← products query + pagination
    OrderService.php    ← orders, createOrder, deleteOrder
    DeliveryService.php ← delivery query + subKeys
    PartnerService.php  ← partner query

Controller/Webhook/
  AbstractWebhook.php   ← Secret-header validation, JSON parsing, 204 response
  Product.php           ← POST wock/webhook/product
  Delivery.php          ← POST wock/webhook/delivery

Observer/
  ProductWebhookObserver.php   ← Re-fetches product after webhook
  DeliveryWebhookObserver.php  ← Fetches delivery keys after webhook

Cron/
  SyncProducts.php      ← Incremental / full product sync
  SyncOrders.php        ← Polls for ready orders and fetches keys

Helper/
  KeyDecoder.php        ← Text/image key decoding, subKey flattening
  OrderBuilder.php      ← Fluent builder for CreateOrderInput
  Logger.php / LogHandler.php  ← var/log/wock.log

Setup/
  InstallSchema.php     ← wock_sync_log + wock_order_map tables
```

### Creating an Order

```php
use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Helper\OrderBuilder;

// Inject via constructor
public function __construct(
    private readonly OrderServiceInterface $orderService,
    private readonly OrderBuilder $orderBuilder,
) {}

// Build and submit
$input = $this->orderBuilder
    ->setPartnerOrderId($magentoOrder->getIncrementId())
    ->addProduct(
        productId: 1234,
        quantity:  1,
        unitPrice: 9.99,     // must match WoCK price exactly
        keyType:   20,        // 20 = text key, 10 = image, null = auto
        partnerProductId: 'SKU-001'
    )
    ->build();

$result = $this->orderService->createOrder($input);
$wockOrderId = $result['orderId'];
```

### Fetching Delivery Keys

```php
use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Helper\KeyDecoder;

$delivery = $this->deliveryService->getDelivery($wockOrderId);

if ($delivery['status']['ready']) {
    foreach ($delivery['products'] as $product) {
        foreach ($product['keys'] as $key) {
            // Flatten key + all nested subKeys (DLC, bundles)
            foreach ($this->keyDecoder->flatten($key) as $k) {
                if ($this->keyDecoder->isTextKey($k)) {
                    $plainText = $this->keyDecoder->getValue($k);
                } elseif ($this->keyDecoder->isImageKey($k)) {
                    $imageBytes = $this->keyDecoder->getImageData($k);
                    $ext        = $this->keyDecoder->getFileExtension($k);
                }
            }
        }
    }
}
```

### Custom Events

| Event | Data | When |
|---|---|---|
| `wock_product_webhook_received` | `product_id`, `type`, `updated_at` | WoCK sends a product change |
| `wock_delivery_webhook_received` | `order_id`, `created_at` | WoCK sends a delivery-ready signal |

Attach your own observer in `etc/events.xml` to handle fulfilment in your
specific context (e.g. send keys by email, update order status, etc.).

### Error Codes

WoCK GraphQL errors are surfaced as `ApiException`. Access the error code:

```php
use DigitalWarehouse\Wock\Exception\ApiException;

try {
    $result = $this->orderService->createOrder($input);
} catch (ApiException $e) {
    $code = $e->getFirstErrorCode();
    // PRODUCT_PRICE_MISMATCH → refresh price and retry
    // PRODUCT_STOCK_MISMATCH → refresh stock and retry
    // PARTNER_BALANCE_ERR   → insufficient credit line
    // PRODUCT_UNIQUE_ERR    → duplicate product in same order
}
```

Full error code list is documented in `Api/OrderServiceInterface.php` and
in the [WoCK API docs](https://docs.wock.digitalwarehou.se/).

---

## Production Checklist

WoCK will only grant production credentials after a successful sandbox review.
Ensure your implementation covers:

- [ ] Uses `products` query (not deprecated `product`)
- [ ] Handles `lastUpdateDateTime` for incremental syncs
- [ ] Uses webhooks **or** polling (not both simultaneously for the same data)
- [ ] Handles `subKeys` on delivery keys (DLC / bundle keys)
- [ ] Downloads deliveries within **96 hours** (orders auto-cancel after that)
- [ ] Only cancels orders in status 30 (`deleteOrder`)
- [ ] Validates `unitPrice` against current API price before `createOrder`
- [ ] Validates stock before `createOrder` (`quantity.all > 0`)
- [ ] Webhook endpoints return **2xx** (module returns 204) to avoid endpoint disabling
- [ ] Secret header configured for webhook authentication

---

## Logs

All module activity is written to:

```
var/log/wock.log
```

Adjust verbosity in Magento's logging configuration or extend `LogHandler`.

---

## License

Proprietary — © Digital Warehouse. All rights reserved.
