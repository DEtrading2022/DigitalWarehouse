<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Centralised access to WoCK module configuration.
 */
class Config
{
    private const XML_PATH_ENABLED           = 'wock/general/enabled';
    private const XML_PATH_ENVIRONMENT       = 'wock/general/environment';
    private const XML_PATH_SANDBOX_ENDPOINT  = 'wock/general/sandbox_endpoint';
    private const XML_PATH_PROD_ENDPOINT     = 'wock/general/production_endpoint';
    private const XML_PATH_TOKEN_TTL         = 'wock/general/token_ttl';

    private const XML_PATH_TOKEN_URL         = 'wock/auth/token_url';
    private const XML_PATH_TENANT_ID         = 'wock/auth/tenant_id';
    private const XML_PATH_CLIENT_ID         = 'wock/auth/client_id';
    private const XML_PATH_CLIENT_SECRET     = 'wock/auth/client_secret';
    private const XML_PATH_SCOPE             = 'wock/auth/scope';

    private const XML_PATH_PRODUCTS_CRON_EN  = 'wock/sync/products_cron_enabled';
    private const XML_PATH_ORDERS_CRON_EN    = 'wock/sync/orders_cron_enabled';
    private const XML_PATH_PAGE_SIZE         = 'wock/sync/page_size';

    private const XML_PATH_WH_SECRET_NAME    = 'wock/webhook/secret_header_name';
    private const XML_PATH_WH_SECRET_VALUE   = 'wock/webhook/secret_header_value';

    private const XML_PATH_FULFILL_STATUS    = 'wock/orders/fulfillment_status';
    private const XML_PATH_KEY_EMAIL_TPL     = 'wock/orders/key_email_template';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface   $encryptor,
    ) {}

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function getEnvironment(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ENVIRONMENT);
    }

    public function isSandbox(): bool
    {
        return $this->getEnvironment() === 'sandbox';
    }

    public function getGraphQlEndpoint(): string
    {
        return $this->isSandbox()
            ? (string) $this->scopeConfig->getValue(self::XML_PATH_SANDBOX_ENDPOINT)
            : (string) $this->scopeConfig->getValue(self::XML_PATH_PROD_ENDPOINT);
    }

    public function getTokenTtl(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_TOKEN_TTL) ?: 300;
    }

    // --- Auth ---

    public function getTokenUrl(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_TOKEN_URL);
    }

    public function getTenantId(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_TENANT_ID);
    }

    public function getClientId(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_CLIENT_ID);
    }

    public function getClientSecret(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_CLIENT_SECRET);
        return $this->encryptor->decrypt($encrypted);
    }

    public function getScope(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_SCOPE);
    }

    // --- Sync ---

    public function isProductsCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PRODUCTS_CRON_EN);
    }

    public function isOrdersCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ORDERS_CRON_EN);
    }

    public function getPageSize(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_PATH_PAGE_SIZE) ?: 100;
    }

    // --- Order Fulfillment ---

    public function getOrderFulfillmentStatus(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_FULFILL_STATUS);
    }

    public function getKeyEmailTemplate(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_KEY_EMAIL_TPL) ?: 'wock_order_keys_email';
    }

    // --- Webhook ---

    public function getWebhookSecretHeaderName(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_WH_SECRET_NAME);
    }

    public function getWebhookSecretHeaderValue(): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_WH_SECRET_VALUE);
        return $this->encryptor->decrypt($encrypted);
    }
}
