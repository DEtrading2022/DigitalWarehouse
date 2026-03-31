<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Api;

use DigitalWarehouse\Wock\Api\TokenManagerInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Exception\AuthenticationException;
use DigitalWarehouse\Wock\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Low-level GraphQL HTTP client.
 *
 * Handles token injection, error parsing, and JSON (de)serialisation.
 */
class Client
{
    public function __construct(
        private readonly Config                $config,
        private readonly TokenManagerInterface $tokenManager,
        private readonly Curl                  $curl,
        private readonly Json                  $json,
        private readonly LoggerInterface       $logger,
    ) {}

    /**
     * Execute a GraphQL operation (query or mutation).
     *
     * @param  string               $query     GraphQL query/mutation string
     * @param  array<string, mixed> $variables Operation variables
     * @return array<string, mixed> The `data` portion of the GraphQL response
     *
     * @throws ApiException
     * @throws AuthenticationException
     */
    public function execute(string $query, array $variables = []): array
    {
        $endpoint = $this->config->getGraphQlEndpoint();
        $token    = $this->tokenManager->getAccessToken();

        $payload = $this->json->serialize([
            'query'     => $query,
            'variables' => $variables,
        ]);

        $this->curl->setTimeout(30);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Authorization', 'Bearer ' . $token);
        $this->curl->post($endpoint, $payload);

        $statusCode = (int) $this->curl->getStatus();
        $body       = $this->curl->getBody();

        // Token might have expired mid-request; retry once with a fresh token
        if ($statusCode === 401) {
            $this->logger->warning('WoCK: received 401, refreshing token and retrying');
            $token = $this->tokenManager->refreshToken();
            $this->curl->addHeader('Authorization', 'Bearer ' . $token);
            $this->curl->post($endpoint, $payload);
            $statusCode = (int) $this->curl->getStatus();
            $body       = $this->curl->getBody();
        }

        if ($statusCode >= 500) {
            $this->logger->error('WoCK: server error', ['status' => $statusCode, 'body' => $body]);
            throw new ApiException(__('WoCK API returned HTTP %1. Try again later.', $statusCode));
        }

        try {
            $response = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('WoCK: non-JSON response', ['body' => $body]);
            throw new ApiException(__('WoCK API returned a non-JSON response.'));
        }

        // GraphQL errors are always 200 but carry an "errors" key
        if (!empty($response['errors'])) {
            $this->logger->warning('WoCK: GraphQL errors', ['errors' => $response['errors']]);
            $firstMessage = $response['errors'][0]['message'] ?? 'Unknown GraphQL error';
            throw (new ApiException(__('WoCK GraphQL error: %1', $firstMessage)))
                ->setErrors($response['errors']);
        }

        return $response['data'] ?? [];
    }
}
