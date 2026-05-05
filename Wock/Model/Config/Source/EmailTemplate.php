<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the WoCK "Product Keys Email Template" system-config dropdown.
 *
 * We intentionally do NOT use Magento\Config\Model\Config\Source\Email\Template here,
 * because that class calls Email\Model\Template\Config::getTemplateData() for every
 * option at page-load time, which throws UnexpectedTemplateIdValueException if the
 * module's email_templates.xml is not yet (fully) merged into the Magento config.
 *
 * Instead we return a static list that exactly mirrors what is declared in
 * Wock/etc/email_templates.xml — safe to call at any point in the lifecycle.
 */
class EmailTemplate implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '',                        'label' => __('-- Please Select --')],
            ['value' => 'wock_order_keys_email',   'label' => __('WoCK Product Keys – Dark Gaming (Default)')],
            ['value' => 'wock_order_keys_light',   'label' => __('WoCK Product Keys – Light / White')],
            ['value' => 'wock_order_keys_gamesyn', 'label' => __('WoCK Product Keys – GameSync Blue/Orange')],
            ['value' => 'wock_order_keys_gamesync_nl', 'label' => __('WoCK Product Keys – GameSync.nl Style')],
        ];
    }
}
