<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Api;

use DigitalWarehouse\Wock\Api\DeliveryServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;

class DeliveryService implements DeliveryServiceInterface
{
    /**
     * Key fragment is nested recursively to capture DLC / bundle sub-keys.
     * The API docs require covering subKeys on OutboundDeliveryKeyResponse.
     */
    private const KEY_FRAGMENT = <<<'GQL'
        id
        key
        downloadedAt
        mimeType
        subKeys {
            id
            key
            downloadedAt
            mimeType
            subKeys {
                id
                key
                downloadedAt
                mimeType
            }
        }
    GQL;

    public function __construct(
        private readonly Client $client,
    ) {}

    public function getDelivery(?string $orderId = null, ?string $legacyOrderId = null): array
    {
        if ($orderId === null && $legacyOrderId === null) {
            throw new \InvalidArgumentException('Either orderId or legacyOrderId must be provided.');
        }

        $query = <<<GQL
            query delivery(\$orderId: UUID, \$legacyOrderId: String) {
                delivery(orderId: \$orderId, legacyOrderId: \$legacyOrderId) {
                    orderId
                    legacyOrderId
                    status {
                        ready
                        error
                    }
                    archives {
                        link
                        password
                    }
                    products {
                        deliveryIdentifier
                        partnerProductId
                        details {
                            id
                            name
                            platform
                            region
                            language
                        }
                        keys {
                            %s
                        }
                    }
                }
            }
            GQL;

        $variables = [];
        if ($orderId !== null) {
            $variables['orderId'] = $orderId;
        }
        if ($legacyOrderId !== null) {
            $variables['legacyOrderId'] = $legacyOrderId;
        }

        $data = $this->client->execute(sprintf($query, self::KEY_FRAGMENT), $variables);

        return $data['delivery'] ?? [];
    }

    public function isDeliveryReady(string $orderId): bool
    {
        $query = <<<'GQL'
            query deliveryStatus($orderId: UUID) {
                delivery(orderId: $orderId) {
                    status {
                        ready
                        error
                    }
                }
            }
            GQL;

        $data = $this->client->execute($query, ['orderId' => $orderId]);

        return (bool) ($data['delivery']['status']['ready'] ?? false);
    }
}
