<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates product attributes that map to WoCK GraphQL product fields.
 *
 * All attributes are placed in the "WoCK" attribute group within the default
 * attribute set. They are not visible on the storefront by default — they
 * serve as internal data fields for the integration.
 *
 * WoCK field mapping:
 *   id                        → wock_product_id
 *   platform                  → wock_platform
 *   region                    → wock_region
 *   currency                  → wock_currency
 *   language                  → wock_language
 *   languages                 → wock_languages          (JSON)
 *   regions                   → wock_regions             (JSON)
 *   excludedLanguages         → wock_excluded_languages  (JSON)
 *   excludedRegions           → wock_excluded_regions    (JSON)
 *   isDisabled                → wock_is_disabled
 *   lastUpdateDateTime        → wock_last_update
 *   lastUpdatedPriceDateTime  → wock_last_price_update
 *   lastIncreasedStockDateTime→ wock_last_stock_increase
 *   productPartnerIds         → wock_partner_ids         (JSON)
 *   quantity.text             → wock_stock_text
 *   quantity.image            → wock_stock_image
 *   quantity.all              → wock_stock_all
 *   bulkPrices                → wock_bulk_prices         (JSON)
 */
class CreateWockProductAttributes implements DataPatchInterface
{
    private const GROUP_NAME = 'WoCK';
    private const SORT_ORDER_START = 100;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory          $eavSetupFactory,
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $entityTypeId    = $eavSetup->getEntityTypeId(Product::ENTITY);
        $attributeSetId  = $eavSetup->getDefaultAttributeSetId($entityTypeId);

        // Create the "WoCK" attribute group in the default attribute set
        $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, self::GROUP_NAME, 200);

        $sortOrder = self::SORT_ORDER_START;

        // ── Toggle: Is WoCK Product ───────────────────────────────────────
        // Placed in General group so it's always visible. The WoCK attribute
        // group visibility is controlled by a UI component modifier based
        // on this toggle value.
        $eavSetup->addAttribute(Product::ENTITY, 'is_wock_product', [
            'type'                       => 'int',
            'label'                      => 'Is WoCK Product',
            'input'                      => 'boolean',
            'source'                     => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
            'global'                     => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible'                    => true,
            'required'                   => false,
            'user_defined'               => true,
            'default'                    => 0,
            'sort_order'                 => 90,
            'is_used_in_grid'            => true,
            'is_visible_in_grid'         => true,
            'is_filterable_in_grid'      => true,
            'searchable'                 => false,
            'filterable'                 => true,
            'comparable'                 => false,
            'visible_on_front'           => false,
            'used_in_product_listing'    => true,
            'unique'                     => false,
            'note'                       => 'Enable to show WoCK integration attributes for this product',
        ]);

        // Place the toggle in the General group
        $generalGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, 'General');
        $eavSetup->addAttributeToGroup(
            $entityTypeId,
            $attributeSetId,
            $generalGroupId,
            'is_wock_product',
            90
        );

        // ── Identity ──────────────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_product_id', [
            'type'        => 'int',
            'label'       => 'WoCK Product ID',
            'input'       => 'text',
            'note'        => 'Unique product identifier in the WoCK system',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
            'searchable'  => true,
        ]);

        // ── Platform & Region ─────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_platform', [
            'type'        => 'varchar',
            'label'       => 'Platform',
            'input'       => 'text',
            'note'        => 'Game platform (e.g. Steam, Origin, Xbox, PlayStation)',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
            'searchable'  => true,
        ]);

        $this->addAttribute($eavSetup, 'wock_region', [
            'type'        => 'varchar',
            'label'       => 'Region',
            'input'       => 'text',
            'note'        => 'Primary region code',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
        ]);

        $this->addAttribute($eavSetup, 'wock_currency', [
            'type'        => 'varchar',
            'label'       => 'Currency',
            'input'       => 'text',
            'note'        => 'Price currency code (e.g. EUR, USD)',
            'sort_order'  => $sortOrder += 10,
        ]);

        // ── Language ──────────────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_language', [
            'type'        => 'varchar',
            'label'       => 'Language',
            'input'       => 'text',
            'note'        => 'Primary language',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
        ]);

        $this->addAttribute($eavSetup, 'wock_languages', [
            'type'        => 'text',
            'label'       => 'Languages',
            'input'       => 'textarea',
            'note'        => 'JSON array of all supported languages',
            'sort_order'  => $sortOrder += 10,
        ]);

        // ── Regions (multi-value) ─────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_regions', [
            'type'        => 'text',
            'label'       => 'Regions',
            'input'       => 'textarea',
            'note'        => 'JSON array of all supported regions',
            'sort_order'  => $sortOrder += 10,
        ]);

        $this->addAttribute($eavSetup, 'wock_excluded_languages', [
            'type'        => 'text',
            'label'       => 'Excluded Languages',
            'input'       => 'textarea',
            'note'        => 'JSON array of excluded languages',
            'sort_order'  => $sortOrder += 10,
        ]);

        $this->addAttribute($eavSetup, 'wock_excluded_regions', [
            'type'        => 'text',
            'label'       => 'Excluded Regions',
            'input'       => 'textarea',
            'note'        => 'JSON array of excluded regions',
            'sort_order'  => $sortOrder += 10,
        ]);

        // ── Status ────────────────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_is_disabled', [
            'type'        => 'int',
            'label'       => 'Disabled on WoCK',
            'input'       => 'boolean',
            'note'        => 'Whether this product is disabled in the WoCK system',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
            'default'     => 0,
        ]);

        // ── Timestamps ────────────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_last_update', [
            'type'        => 'datetime',
            'label'       => 'Last Update (WoCK)',
            'input'       => 'date',
            'note'        => 'When this product was last updated on WoCK',
            'sort_order'  => $sortOrder += 10,
        ]);

        $this->addAttribute($eavSetup, 'wock_last_price_update', [
            'type'        => 'datetime',
            'label'       => 'Last Price Update (WoCK)',
            'input'       => 'date',
            'note'        => 'When pricing was last changed on WoCK',
            'sort_order'  => $sortOrder += 10,
        ]);

        $this->addAttribute($eavSetup, 'wock_last_stock_increase', [
            'type'        => 'datetime',
            'label'       => 'Last Stock Increase (WoCK)',
            'input'       => 'date',
            'note'        => 'When stock was last increased on WoCK',
            'sort_order'  => $sortOrder += 10,
        ]);

        // ── Partner IDs ───────────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_partner_ids', [
            'type'        => 'text',
            'label'       => 'Partner Product IDs',
            'input'       => 'textarea',
            'note'        => 'JSON array of your partner-specific product identifiers in WoCK',
            'sort_order'  => $sortOrder += 10,
        ]);

        // ── Stock / Quantity ──────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_stock_text', [
            'type'        => 'int',
            'label'       => 'WoCK Stock (Text Keys)',
            'input'       => 'text',
            'note'        => 'Available text-key stock on WoCK',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
            'default'     => 0,
        ]);

        $this->addAttribute($eavSetup, 'wock_stock_image', [
            'type'        => 'int',
            'label'       => 'WoCK Stock (Image Keys)',
            'input'       => 'text',
            'note'        => 'Available image-key stock on WoCK',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
            'default'     => 0,
        ]);

        $this->addAttribute($eavSetup, 'wock_stock_all', [
            'type'        => 'int',
            'label'       => 'WoCK Stock (Total)',
            'input'       => 'text',
            'note'        => 'Total available stock across all key types on WoCK',
            'sort_order'  => $sortOrder += 10,
            'filterable'  => true,
            'default'     => 0,
        ]);

        // ── Pricing ───────────────────────────────────────────────────────
        $this->addAttribute($eavSetup, 'wock_bulk_prices', [
            'type'        => 'text',
            'label'       => 'Bulk Prices',
            'input'       => 'textarea',
            'note'        => 'JSON array of WoCK bulk pricing tiers [{price, minimumQuantity}]',
            'sort_order'  => $sortOrder += 10,
        ]);

        // ── Assign all attributes to the WoCK group ──────────────────────
        $wockAttributes = [
            'wock_product_id',
            'wock_platform',
            'wock_region',
            'wock_currency',
            'wock_language',
            'wock_languages',
            'wock_regions',
            'wock_excluded_languages',
            'wock_excluded_regions',
            'wock_is_disabled',
            'wock_last_update',
            'wock_last_price_update',
            'wock_last_stock_increase',
            'wock_partner_ids',
            'wock_stock_text',
            'wock_stock_image',
            'wock_stock_all',
            'wock_bulk_prices',
        ];

        foreach ($wockAttributes as $attributeCode) {
            $eavSetup->addAttributeToGroup(
                $entityTypeId,
                $attributeSetId,
                self::GROUP_NAME,
                $attributeCode
            );
        }

        return $this;
    }

    /**
     * Add a single product attribute with sensible defaults for WoCK integration fields.
     */
    private function addAttribute(EavSetup $eavSetup, string $code, array $config): void
    {
        $defaults = [
            'global'                     => ScopedAttributeInterface::SCOPE_GLOBAL,
            'visible'                    => true,
            'required'                   => false,
            'user_defined'               => true,
            'is_used_in_grid'            => $config['filterable'] ?? false,
            'is_visible_in_grid'         => false,
            'is_filterable_in_grid'      => $config['filterable'] ?? false,
            'searchable'                 => $config['searchable'] ?? false,
            'filterable'                 => false, // Layered navigation — enable per attribute later
            'comparable'                 => false,
            'visible_on_front'           => false,
            'used_in_product_listing'    => false,
            'unique'                     => ($code === 'wock_product_id'),
            'apply_to'                   => '', // All product types
        ];

        // Remove our custom keys before passing to EAV setup
        unset($config['filterable'], $config['searchable']);

        $eavSetup->addAttribute(Product::ENTITY, $code, array_merge($defaults, $config));
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
