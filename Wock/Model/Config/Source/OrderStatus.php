<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Provides Magento order statuses for the WoCK fulfillment status dropdown.
 */
class OrderStatus implements OptionSourceInterface
{
    public function __construct(
        private readonly OrderConfig $orderConfig,
    ) {}

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Please Select --')],
        ];

        $statuses = $this->orderConfig->getStatuses();

        foreach ($statuses as $code => $label) {
            $options[] = [
                'value' => $code,
                'label' => $label,
            ];
        }

        return $options;
    }
}
