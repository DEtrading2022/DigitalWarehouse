<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Api;

use DigitalWarehouse\Wock\Exception\ApiException;

interface OrderServiceInterface
{
    /**
     * Fetch a paginated list of orders.
     *
     * @param  int                       $skip
     * @param  int                       $take
     * @param  array<string, mixed>|null $where
     * @param  array<string, mixed>|null $order
     * @return array{pageInfo: array, items: array, totalCount: int}
     * @throws ApiException
     */
    public function getOrders(
        int    $skip  = 0,
        int    $take  = 100,
        ?array $where = null,
        ?array $order = null
    ): array;

    /**
     * Fetch all orders using automatic pagination.
     *
     * @param  array<string, mixed>|null $where
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getAllOrders(?array $where = null): array;

    /**
     * Create a new order on WoCK.
     *
     * @param  array{
     *     products: array<int, array{
     *         productId: int,
     *         quantity: int,
     *         unitPrice: float,
     *         keyType?: int|null,
     *         partnerProductId?: string|null
     *     }>,
     *     partnerOrderId?: string|null
     * } $input
     * @return array{orderId: string, partnerOrderId: string|null, products: array}
     * @throws ApiException
     */
    public function createOrder(array $input): array;

    /**
     * Delete / cancel an order that is in New or Ready-for-download status.
     *
     * @param  string $orderId UUID
     * @return bool
     * @throws ApiException
     */
    public function deleteOrder(string $orderId): bool;
}
