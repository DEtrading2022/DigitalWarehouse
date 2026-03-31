<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Api;

use DigitalWarehouse\Wock\Exception\ApiException;

interface ProductServiceInterface
{
    /**
     * Fetch a paginated list of products.
     *
     * @param  int                       $skip
     * @param  int                       $take
     * @param  array<string, mixed>|null $where  GraphQL filter input
     * @param  array<string, mixed>|null $order  GraphQL sort input
     * @return array{pageInfo: array, items: array, totalCount: int}
     * @throws ApiException
     */
    public function getProducts(
        int   $skip  = 0,
        int   $take  = 100,
        ?array $where = null,
        ?array $order = null
    ): array;

    /**
     * Fetch all products using automatic pagination.
     *
     * @param  array<string, mixed>|null $where
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getAllProducts(?array $where = null): array;

    /**
     * Fetch products updated since the given datetime.
     *
     * @param  \DateTimeInterface $since
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getProductsUpdatedSince(\DateTimeInterface $since): array;

    /**
     * Fetch products by a list of IDs (for webhook-triggered refreshes).
     *
     * @param  int[] $ids
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getProductsByIds(array $ids): array;

    /**
     * Fetch all products matching a specific list of IDs that have been updated since a specific time.
     *
     * @param int[] $ids
     * @param \DateTimeInterface $since
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function getProductsByIdsAndUpdatedSince(array $ids, \DateTimeInterface $since): array;
}
