<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Helper;

use DigitalWarehouse\Wock\Api\OrderServiceInterface;

/**
 * Fluent builder for WoCK CreateOrderInput payloads.
 *
 * Usage:
 *
 *   $input = (new OrderBuilder())
 *       ->setPartnerOrderId($magentoIncrementId)
 *       ->addProduct(productId: 1234, quantity: 1, unitPrice: 9.99)
 *       ->addProduct(productId: 5678, quantity: 2, unitPrice: 4.99, keyType: 20)
 *       ->build();
 *
 *   $this->orderService->createOrder($input);
 */
class OrderBuilder
{
    private ?string $partnerOrderId = null;

    /** @var array<int, array<string, mixed>> */
    private array $products = [];

    public function setPartnerOrderId(string $id): self
    {
        // WoCK constraint: max 50 chars, alphanumeric + hyphens, underscores, slashes
        $sanitised = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $id);
        if (strlen($sanitised) > 50) {
            $sanitised = substr($sanitised, 0, 50);
        }
        $this->partnerOrderId = $sanitised;
        return $this;
    }

    /**
     * @param int      $productId   WoCK product ID
     * @param int      $quantity    1–500 (hard limit per WoCK)
     * @param float    $unitPrice   Must match current WoCK product price exactly
     * @param int|null $keyType     null = auto, 10 = image, 20 = text
     * @param string|null $partnerProductId  Your own product reference
     */
    public function addProduct(
        int     $productId,
        int     $quantity,
        float   $unitPrice,
        ?int    $keyType          = null,
        ?string $partnerProductId = null
    ): self {
        if ($quantity < 1 || $quantity > 500) {
            throw new \InvalidArgumentException(
                sprintf('Quantity must be between 1 and 500 (got %d for product %d).', $quantity, $productId)
            );
        }

        $line = [
            'productId' => $productId,
            'quantity'  => $quantity,
            'unitPrice' => $unitPrice,
        ];

        if ($keyType !== null) {
            if (!in_array($keyType, [10, 20], true)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid keyType %d. Allowed: 10 (image), 20 (text) or null (auto).', $keyType)
                );
            }
            $line['keyType'] = $keyType;
        }

        if ($partnerProductId !== null) {
            $line['partnerProductId'] = $partnerProductId;
        }

        $this->products[] = $line;
        return $this;
    }

    /**
     * Build the input array ready for OrderServiceInterface::createOrder().
     *
     * @return array<string, mixed>
     * @throws \LogicException when no products have been added
     */
    public function build(): array
    {
        if (empty($this->products)) {
            throw new \LogicException('Cannot build a WoCK order with no products.');
        }

        $input = ['products' => $this->products];

        if ($this->partnerOrderId !== null) {
            $input['partnerOrderId'] = $this->partnerOrderId;
        }

        return $input;
    }

    /** Reset the builder for reuse. */
    public function reset(): self
    {
        $this->partnerOrderId = null;
        $this->products       = [];
        return $this;
    }
}
