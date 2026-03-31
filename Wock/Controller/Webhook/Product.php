<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Controller\Webhook;

use DigitalWarehouse\Wock\Model\Config;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * POST wock/webhook/product
 *
 * WoCK sends this when a product is created, updated or deleted.
 *
 * Expected payload:
 * {
 *   "id": 420,
 *   "type": "PRODUCT_UPDATED",
 *   "updatedAt": "2025-04-17T04:20:00.0000000"
 * }
 *
 * Per WoCK best-practices: the payload is treated as a trigger only.
 * The observer re-fetches the product from the API as the source of truth.
 */
class Product extends AbstractWebhook
{
    private const VALID_TYPES = [
        'PRODUCT_CREATED',
        'PRODUCT_UPDATED',
        'PRODUCT_DELETED',
    ];

    public function __construct(
        RequestInterface         $request,
        ResponseInterface        $response,
        Config                   $config,
        Json                     $json,
        JsonFactory              $resultJsonFactory,
        LoggerInterface          $logger,
        private readonly EventManager $eventManager,
    ) {
        parent::__construct($request, $response, $config, $json, $resultJsonFactory, $logger);
    }

    protected function handlePayload(array $payload): void
    {
        $id        = isset($payload['id']) ? (int) $payload['id'] : null;
        $type      = (string) ($payload['type'] ?? '');
        $updatedAt = (string) ($payload['updatedAt'] ?? '');

        if ($id === null || !in_array($type, self::VALID_TYPES, true)) {
            $this->logger->warning('WoCK product webhook: unexpected payload', $payload);
            return;
        }

        $this->logger->info('WoCK product webhook received', [
            'id'        => $id,
            'type'      => $type,
            'updatedAt' => $updatedAt,
        ]);

        $this->eventManager->dispatch('wock_product_webhook_received', [
            'product_id' => $id,
            'type'       => $type,
            'updated_at' => $updatedAt,
        ]);
    }
}
