<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Model\Config;
use Magento\Framework\App\CacheInterface;

class WockProductSource extends AbstractSource
{
    private ?array $options = null;

    public function __construct(
        private readonly ProductServiceInterface $productService,
        private readonly Config $config,
        private readonly CacheInterface $cache
    ) {}

    /**
     * Get all options for the WoCK Product dropdown natively in Magento.
     */
    public function getAllOptions(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $cacheKey = 'wock_eav_source_products_v2';
        $cachedData = $this->cache->load($cacheKey);

        if ($cachedData) {
            $this->options = json_decode($cachedData, true);
            return $this->options;
        }

        $options = [];
        $options[] = ['value' => '', 'label' => '— Please Select a WoCK Product —'];

        try {
            if ($this->config->isEnabled() && $this->config->getClientId()) {
                // Fetch up to 3000 active products directly
                $apiData = $this->productService->getProducts(0, 3000);
                if (!empty($apiData['items'])) {
                    foreach ($apiData['items'] as $item) {
                        if (empty($item['isDisabled'])) {
                            $platform = !empty($item['platform']) ? $item['platform'] : 'Global';
                            
                            $priceText = '';
                            $bulkPrices = $item['bulkPrices'] ?? [];
                            $costPrice = null;
                            
                            foreach ($bulkPrices as $bp) {
                                if ((int)$bp['minimumQuantity'] === 1) {
                                    $costPrice = (float)$bp['price'];
                                    break;
                                }
                            }
                            // Fallback to the absolute first pricing index if 1+ doesn't explicitly exist
                            if ($costPrice === null && !empty($bulkPrices)) {
                                $costPrice = (float)$bulkPrices[0]['price'];
                            }

                            if ($costPrice !== null) {
                                $currency = $item['currency'] ?? '';
                                $priceText = sprintf(' - %s %s', trim($currency), number_format($costPrice, 2));
                            }

                            $options[] = [
                                'value' => (string)$item['id'],
                                'label' => sprintf('%s - %s (%s)%s', $item['id'], $item['name'], $platform, $priceText)
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $options[] = ['value' => '', 'label' => 'API Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')];
        }

        $this->options = $options;
        
        // Cache the parsed array for 15 minutes to keep Magento admin ultra fast
        $this->cache->save(json_encode($this->options), $cacheKey, [], 900);

        return $this->options;
    }
}
