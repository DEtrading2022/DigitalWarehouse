<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Api;

use DigitalWarehouse\Wock\Api\OrderServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Model\Config;

class OrderService implements OrderServiceInterface
{
    private const ORDER_PRODUCT_FIELDS = <<<'GQL'
        id
        name
        isBundle
        isSubBundle
        quantity
        price
        children {
            id
            name
            quantity
            price
        }
    GQL;

    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
    ) {}

    public function getOrders(
        int    $skip  = 0,
        int    $take  = 100,
        ?array $where = null,
        ?array $order = null
    ): array {
        $query = <<<GQL
            query orders(
                \$skip:  Int,
                \$take:  Int,
                \$where: OrderResponseFilterInput,
                \$order: [OrderResponseSortInput!]
            ) {
                orders(skip: \$skip, take: \$take, where: \$where, order: \$order) {
                    pageInfo {
                        hasNextPage
                        hasPreviousPage
                    }
                    totalCount
                    items {
                        id
                        legacyId
                        createdAt
                        identifier
                        partnerOrderId
                        status
                        products {
                            %s
                        }
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

        $data = $this->client->execute(sprintf($query, self::ORDER_PRODUCT_FIELDS), $variables);

        return $data['orders'] ?? ['pageInfo' => [], 'items' => [], 'totalCount' => 0];
    }

    public function getAllOrders(?array $where = null): array
    {
        $pageSize = $this->config->getPageSize();
        $skip     = 0;
        $all      = [];

        do {
            $result  = $this->getOrders($skip, $pageSize, $where);
            $items   = $result['items'] ?? [];
            $all     = array_merge($all, $items);
            $skip   += $pageSize;
            $hasMore = $result['pageInfo']['hasNextPage'] ?? false;
        } while ($hasMore);

        return $all;
    }

    public function createOrder(array $input): array
    {
        $query = <<<'GQL'
            mutation createOrder($input: CreateOrderInput!) {
                createOrder(input: $input) {
                    orderId
                    partnerOrderId
                    products {
                        productId
                        price
                        quantity
                        keyType
                        partnerProductId
                    }
                }
            }
            GQL;

        // Ensure numeric types match GraphQL schema expectations
        $products = array_map(function (array $p): array {
            $line = [
                'productId' => (int)   $p['productId'],
                'quantity'  => (int)   $p['quantity'],
                'unitPrice' => (float) $p['unitPrice'],
            ];
            if (isset($p['keyType'])) {
                $line['keyType'] = (int) $p['keyType'];
            }
            if (!empty($p['partnerProductId'])) {
                $line['partnerProductId'] = (string) $p['partnerProductId'];
            }
            return $line;
        }, $input['products']);

        $variables = ['input' => ['products' => $products]];

        if (!empty($input['partnerOrderId'])) {
            $variables['input']['partnerOrderId'] = (string) $input['partnerOrderId'];
        }

        $data = $this->client->execute($query, $variables);

        return $data['createOrder'] ?? [];
    }

    public function deleteOrder(string $orderId): bool
    {
        $query = <<<'GQL'
            mutation deleteOrder($orderId: UUID!) {
                deleteOrder(orderId: $orderId)
            }
            GQL;

        $data = $this->client->execute($query, ['orderId' => $orderId]);

        return (bool) ($data['deleteOrder'] ?? false);
    }
}
