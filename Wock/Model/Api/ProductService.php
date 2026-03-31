<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Api;

use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\Config;

class ProductService implements ProductServiceInterface
{
    /**
     * Full product fragment used across all product queries.
     * Excludes deprecated fields (isBundle, bundleItems, productPartnerId).
     */
    private const PRODUCT_FIELDS = <<<'GQL'
        id
        name
        platform
        region
        currency
        language
        languages
        regions
        excludedLanguages
        excludedRegions
        isDisabled
        lastUpdateDateTime
        lastUpdatedPriceDateTime
        lastIncreasedStockDateTime
        productPartnerIds
        quantity {
            text
            image
            all
        }
        bulkPrices {
            price
            minimumQuantity
        }
    GQL;

    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
    ) {}

    public function getProducts(
        int    $skip  = 0,
        int    $take  = 100,
        ?array $where = null,
        ?array $order = null
    ): array {
        $query = <<<GQL
            query products(
                \$skip:  Int,
                \$take:  Int,
                \$where: ProductResponseFilterInput,
                \$order: [ProductResponseSortInput!]
            ) {
                products(skip: \$skip, take: \$take, where: \$where, order: \$order) {
                    pageInfo {
                        hasNextPage
                        hasPreviousPage
                    }
                    totalCount
                    items {
                        %s
                    }
                }
            }
            GQL;

        $variables = ['skip' => $skip, 'take' => $take];
        if ($where !== null) {
            $variables['where'] = $where;
        }
        if ($order !== null) {
            $variables['order'] = $order;
        }

        $data = $this->client->execute(sprintf($query, self::PRODUCT_FIELDS), $variables);

        return $data['products'] ?? ['pageInfo' => [], 'items' => [], 'totalCount' => 0];
    }

    public function getAllProducts(?array $where = null): array
    {
        $pageSize = $this->config->getPageSize();
        $skip     = 0;
        $all      = [];

        do {
            $result  = $this->getProducts($skip, $pageSize, $where);
            $items   = $result['items'] ?? [];
            $all     = array_merge($all, $items);
            $skip   += $pageSize;
            $hasMore = $result['pageInfo']['hasNextPage'] ?? false;
        } while ($hasMore);

        return $all;
    }

    public function getProductsUpdatedSince(\DateTimeInterface $since): array
    {
        $where = [
            'lastUpdateDateTime' => [
                'gt' => $since->format(\DateTimeInterface::ATOM),
            ],
        ];

        return $this->getAllProducts($where);
    }

    public function getProductsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $where = [
            'id' => [
                'in' => array_map('intval', $ids),
            ],
        ];

        return $this->getAllProducts($where);
    }
}
