<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Block\Adminhtml\Catalogue;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Model\Config;

/**
 * Provides live WoCK API products data to the explorer grid template.
 */
class Grid extends Template
{
    private const PAGE_SIZE = 50;

    public function __construct(
        Context $context,
        private readonly ProductServiceInterface $productService,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Retrieves the current page number from the request parameter.
     */
    public function getCurrentPage(): int
    {
        return (int) $this->getRequest()->getParam('p', 1);
    }

    /**
     * Retrieves live data from the WoCK API.
     * Returns an array with 'items', 'totalCount', and 'pageInfo'.
     */
    public function getApiData(): array
    {
        if (!$this->config->isEnabled()) {
             return ['error' => 'Module is disabled in configuration. Enable the module to query the WoCK API.'];
        }

        if (!$this->config->getClientId() || !$this->config->getClientSecret()) {
            return ['error' => 'Missing OAuth credentials. Fill them out in the configuration first.'];
        }

        try {
            $page = max(1, $this->getCurrentPage());
            $skip = ($page - 1) * self::PAGE_SIZE;

            return $this->productService->getProducts($skip, self::PAGE_SIZE);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Builds the pagination URL for Previous or Next page buttons.
     */
    public function getPaginationUrl(int $pageNumber): string
    {
        return $this->getUrl('*/*/*', ['p' => $pageNumber]);
    }

    /**
     * Helper to retrieve specific language format since it is returned as JSON.
     */
    public function formatJsonArray(mixed $jsonString): string
    {
        if (empty($jsonString)) {
            return '—';
        }
        $data = json_decode((string)$jsonString, true);
        if (is_array($data)) {
            return implode(', ', $data);
        }
        return (string) $jsonString;
    }
}
