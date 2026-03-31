<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Plugin;

use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http;

/**
 * Marks all wock/webhook/* responses as non-cacheable so that Varnish /
 * the built-in FPC never serves a cached response to WoCK's POST requests.
 *
 * Wired via Plugin in etc/di.xml (see NotCacheableWebhookPlugin entry).
 */
class NotCacheableWebhookPlugin
{
    public function __construct(
        private readonly Http $request,
    ) {}

    /**
     * After the controller executes, tag the response non-cacheable
     * if the current route is a WoCK webhook.
     */
    public function afterExecute(
        \Magento\Framework\App\ActionInterface $subject,
        mixed $result
    ): mixed {
        if ($this->request->getModuleName() === 'wock'
            && $this->request->getControllerName() === 'webhook'
        ) {
            if ($result instanceof \Magento\Framework\Controller\ResultInterface) {
                $result->setHttpResponseCode($result->getHttpResponseCode() ?? 204);
            }
        }

        return $result;
    }
}
