<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Controller\Webhook;

use DigitalWarehouse\Wock\Model\Config;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Base class for WoCK webhook controllers.
 *
 * Validates the optional secret header before dispatching to subclasses.
 * Returns 204 No Content on success, as recommended by WoCK documentation.
 */
abstract class AbstractWebhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly ResponseInterface $response,
        protected readonly Config            $config,
        protected readonly Json              $json,
        protected readonly JsonFactory       $resultJsonFactory,
        protected readonly LoggerInterface   $logger,
    ) {}

    // ---- CsrfAwareActionInterface ----

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // Webhooks use their own header-based auth
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // Skip Magento CSRF; we validate via secret header
    }

    // ---- Main dispatch ----

    public function execute(): ResultInterface|ResponseInterface
    {
        // 1. Validate secret header when configured
        if (!$this->isRequestAuthorised()) {
            $this->logger->warning('WoCK webhook: unauthorised request', [
                'ip'     => $this->request->getClientIp(),
                'action' => static::class,
            ]);
            $this->response->setHttpResponseCode(401);
            $this->response->setBody('Unauthorized');
            return $this->response;
        }

        // 2. Parse JSON body
        $body = $this->request->getContent();
        if (empty($body)) {
            $this->logger->warning('WoCK webhook: empty body received', ['action' => static::class]);
            $this->response->setHttpResponseCode(400);
            $this->response->setBody('Bad Request: empty body');
            return $this->response;
        }

        try {
            $payload = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('WoCK webhook: invalid JSON', ['body' => $body]);
            $this->response->setHttpResponseCode(400);
            $this->response->setBody('Bad Request: invalid JSON');
            return $this->response;
        }

        // 3. Delegate to subclass
        try {
            $this->handlePayload($payload);
        } catch (\Throwable $e) {
            // Log but still return 204 to prevent WoCK from disabling the endpoint
            $this->logger->error('WoCK webhook: handler threw exception', [
                'exception' => $e->getMessage(),
                'payload'   => $payload,
            ]);
        }

        // 4. Respond 204 No Content as recommended by WoCK docs
        $this->response->setHttpResponseCode(204);
        $this->response->setBody('');
        return $this->response;
    }

    /**
     * Process a validated, parsed webhook payload.
     *
     * @param array<string, mixed> $payload
     */
    abstract protected function handlePayload(array $payload): void;

    // ---- Helpers ----

    private function isRequestAuthorised(): bool
    {
        $headerName  = $this->config->getWebhookSecretHeaderName();
        $headerValue = $this->config->getWebhookSecretHeaderValue();

        // If no secret is configured, allow all requests — but warn loudly so the
        // operator knows the endpoint is open. Configure wock/webhook/secret_header_name
        // and wock/webhook/secret_header_value in System > Configuration to lock it down.
        if (empty($headerName) || empty($headerValue)) {
            $this->logger->warning('WoCK webhook: no secret configured — endpoint is open to anyone', [
                'action' => static::class,
                'ip'     => $this->request->getClientIp(),
            ]);
            return true;
        }

        $incoming = $this->request->getHeader($headerName);

        return hash_equals($headerValue, (string) $incoming);
    }
}
