<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Api;

use DigitalWarehouse\Wock\Exception\ApiException;

interface DeliveryServiceInterface
{
    /**
     * Retrieve delivery keys for an order.
     *
     * The `orderId` (UUID) or `legacyOrderId` (string) must be provided.
     * Note: orders not downloaded within 96 hours are auto-cancelled.
     *
     * @param  string|null $orderId       UUID from createOrder response
     * @param  string|null $legacyOrderId Legacy platform order ID
     * @return array{
     *     orderId: string,
     *     legacyOrderId: string|null,
     *     products: array,
     *     archives: array,
     *     status: array{ready: bool, error: string|null}
     * }
     * @throws ApiException
     */
    public function getDelivery(?string $orderId = null, ?string $legacyOrderId = null): array;

    /**
     * Returns true when the delivery is ready for download.
     *
     * @throws ApiException
     */
    public function isDeliveryReady(string $orderId): bool;
}
