<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;

/**
 * Conditionally shows/hides the "WoCK" attribute group on the product form
 * based on the `is_wock_product` toggle.
 *
 * When is_wock_product = No (0):  the WoCK fieldset is hidden
 * When is_wock_product = Yes (1): the WoCK fieldset is visible
 */
class WockAttributes extends AbstractModifier
{
    /**
     * The container name for the WoCK attribute group.
     *
     * Magento converts group name "WoCK" via:
     *   strtolower → replace non-alphanum with dash → replace dash with underscore
     * Result: "wock"
     */
    private const WOCK_GROUP_CONTAINER = 'wock';

    public function __construct(
        private readonly ArrayManager $arrayManager,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function modifyData(array $data): array
    {
        // No data modifications needed
        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function modifyMeta(array $meta): array
    {
        $meta = $this->addSwitcherToToggle($meta);
        $meta = $this->setWockGroupInitialVisibility($meta);

        return $meta;
    }

    /**
     * Add a switcherConfig to the is_wock_product field.
     * This tells the UI component to show/hide the WoCK fieldset
     * based on the toggle value.
     */
    private function addSwitcherToToggle(array $meta): array
    {
        // Find the is_wock_product field across all groups
        foreach ($meta as $groupCode => $groupConfig) {
            $path = $groupCode . '/children/container_is_wock_product/children/is_wock_product/arguments/data/config';
            $fieldConfig = $this->arrayManager->get($path, $meta);

            if ($fieldConfig !== null) {
                $switcherConfig = [
                    'enabled' => true,
                    'rules'   => [
                        // When toggle = Yes (1) → show WoCK group
                        [
                            'value' => '1',
                            'actions' => [
                                [
                                    'target'   => 'product_form.product_form.' . self::WOCK_GROUP_CONTAINER,
                                    'callback' => 'show',
                                ],
                            ],
                        ],
                        // When toggle = No (0) → hide WoCK group
                        [
                            'value' => '0',
                            'actions' => [
                                [
                                    'target'   => 'product_form.product_form.' . self::WOCK_GROUP_CONTAINER,
                                    'callback' => 'hide',
                                ],
                            ],
                        ],
                    ],
                ];

                $meta = $this->arrayManager->merge($path, $meta, [
                    'switcherConfig' => $switcherConfig,
                ]);

                break;
            }
        }

        return $meta;
    }

    /**
     * Set the WoCK attribute group to be hidden by default.
     * The switcherConfig will show it when is_wock_product = 1.
     */
    private function setWockGroupInitialVisibility(array $meta): array
    {
        if (isset($meta[self::WOCK_GROUP_CONTAINER])) {
            $meta[self::WOCK_GROUP_CONTAINER]['arguments']['data']['config']['visible'] = false;
        }

        return $meta;
    }
}
