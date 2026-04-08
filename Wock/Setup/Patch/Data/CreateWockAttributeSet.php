<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use DigitalWarehouse\Wock\Model\Config\Source\WockProductSource;

/**
 * Creates the "WOCK Product" Attribute Set based on the Magento Default set,
 * and seamlessly transforms the wock_product_id field into a dynamic, API-sourced dropdown.
 */
class CreateWockAttributeSet implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly \Magento\Eav\Model\Entity\Attribute\SetFactory $attributeSetFactory
    ) {}

    public function apply(): self
    {
        try {
            $this->moduleDataSetup->getConnection()->startSetup();

            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
            $defaultSetId = $eavSetup->getDefaultAttributeSetId($entityTypeId);

            // 1. Create the new Attribute Set named "WOCK Product" if it doesn't exist
            $wockSetId = null;
            try {
                $wockSetId = $eavSetup->getAttributeSetId($entityTypeId, 'WOCK Product');
            } catch (\Exception $e) {
                $wockSetId = null;
            }
            
            if (!$wockSetId) {
                $attributeSet = $this->attributeSetFactory->create();
                $attributeSet->setData([
                    'attribute_set_name' => 'WOCK Product',
                    'entity_type_id'     => $entityTypeId,
                    'sort_order'         => 200,
                ]);
                $attributeSet->validate();
                $attributeSet->save();

                // Inherit all attributes mapped from the Default Magento attribute set
                $attributeSet->initFromSkeleton($defaultSetId);
                $attributeSet->save();
                
                $wockSetId = $attributeSet->getId();
            }

            // 2. Modify the base wock_product_id trait correctly matching native EAV columns
            $eavSetup->updateAttribute(Product::ENTITY, 'wock_product_id', 'frontend_input', 'select');
            $eavSetup->updateAttribute(Product::ENTITY, 'wock_product_id', 'source_model', WockProductSource::class);
            
            // 3. Anchor it inside a discrete Group
            $eavSetup->addAttributeGroup($entityTypeId, $wockSetId, 'WoCK Setup', 10);
            $groupId = $eavSetup->getAttributeGroupId($entityTypeId, $wockSetId, 'WoCK Setup');
            
            $eavSetup->addAttributeToSet($entityTypeId, $wockSetId, $groupId, 'wock_product_id');

            $this->moduleDataSetup->getConnection()->endSetup();
        } catch (\Throwable $e) {
            echo "\n>>>>> CRITICAL SETUP ERROR <<<<<\n";
            echo $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            // Print error loudly without throwing to avoid mask mechanism
            die(1);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            CreateWockProductAttributes::class
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
