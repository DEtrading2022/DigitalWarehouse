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
 * POST wock/webhook/delivery
 *
 * WoCK sends this when a delivery is ready for download.
 *
 * Expected payload:
 * {
 *   "id": "d121c283-7634-479a-ac5f-402f4d3a337f",
 *   "createdAt": "2025-04-17T04:20:00.0000000"
 * }
 *
 * Per WoCK best-practices: the payload is treated as a trigger only.
 * The observer re-fetches the delivery from the API as the source of truth.
 * IMPORTANT: orders must be downloaded within 96 hours or they are auto-cancelled.
 */
class Delivery extends AbstractWebhook
{
    public function __construct(
        RequestInterface              $request,
        ResponseInterface             $response,
        Config                        $config,
        Json                          $json,
        JsonFactory                   $resultJsonFactory,
        LoggerInterface               $logger,
        private readonly EventManager $eventManager,
    ) {
        parent::__construct($request, $response, $config, $json, $resultJsonFactory, $logger);
    }

    protected function handlePayload(array $payload): void
    {
        $orderId   = (string) ($payload['id'] ?? '');
        $createdAt = (string) ($payload['createdAt'] ?? '');

        if (empty($orderId)) {
            $this->logger->warning('WoCK delivery webhook: missing id in payload', $payload);
            return;
        }

        $this->logger->info('WoCK delivery webhook received', [
            'orderId'   => $orderId,
            'createdAt' => $createdAt,
        ]);

        $this->eventManager->dispatch('wock_delivery_webhook_received', [
            'order_id'   => $orderId,
            'created_at' => $createdAt,
        ]);
    }
}
