<?php
declare(strict_types=1);

namespace DigitalWarehouse\Wock\Controller\Adminhtml\Test;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use DigitalWarehouse\Wock\Api\ProductServiceInterface;
use DigitalWarehouse\Wock\Model\Config;

class Connection extends Action
{
    public const ADMIN_RESOURCE = 'DigitalWarehouse_Wock::config';

    public function __construct(
        Context                            $context,
        private readonly JsonFactory             $resultJsonFactory,
        private readonly ProductServiceInterface $productService,
        private readonly Config                  $config
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            if (!$this->config->isEnabled()) {
                return $result->setData([
                    'success' => false,
                    'error'   => 'Module is disabled in configuration. Enable it to test.'
                ]);
            }

            if (!$this->config->getClientId() || !$this->config->getClientSecret()) {
                return $result->setData([
                    'success' => false,
                    'error'   => 'Missing OAuth credentials. Fill them out and click "Save Config" first.'
                ]);
            }

            // Full end-to-end test by pulling 1 product.
            // Triggers: Config check -> Token retrieval -> GraphQL execute -> Parsing
            $pageData = $this->productService->getProducts(0, 1);
            
            $env = $this->config->getEnvironment() === 'production' ? 'Production' : 'Sandbox';

            return $result->setData([
                'success' => true,
                'message' => sprintf('%s (Found %s products)', $env, $pageData['totalCount'] ?? 0)
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage()
            ]);
        }
    }
}
