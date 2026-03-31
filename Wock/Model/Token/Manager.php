<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Token;

use DigitalWarehouse\Wock\Api\TokenManagerInterface;
use DigitalWarehouse\Wock\Exception\AuthenticationException;
use DigitalWarehouse\Wock\Model\Config;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Fetches and caches Azure AD access tokens for the WoCK API.
 *
 * Uses the OAuth 2.0 client-credentials flow as described at:
 * https://docs.microsoft.com/en-us/azure/active-directory/develop/v2-oauth2-client-creds-grant-flow
 */
class Manager implements TokenManagerInterface
{
    private const CACHE_KEY = 'WOCK_ACCESS_TOKEN';

    public function __construct(
        private readonly Config          $config,
        private readonly CurlFactory     $curlFactory,
        private readonly CacheInterface  $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationException
     */
    public function getAccessToken(): string
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        return $this->refreshToken();
    }

    /**
     * @throws AuthenticationException
     */
    public function refreshToken(): string
    {
        $tokenUrl = $this->config->getTokenUrl();
        if (empty($tokenUrl)) {
            // Build the default Azure AD URL from tenant ID when no explicit URL is set
            $tenantId = $this->config->getTenantId();
            if (empty($tenantId)) {
                throw new AuthenticationException(__('WoCK: Token URL and Tenant ID are both empty. Check configuration.'));
            }
            $tokenUrl = sprintf(
                'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
                urlencode($tenantId)
            );
        }

        $params = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->config->getClientId(),
            'client_secret' => $this->config->getClientSecret(),
            'scope'         => $this->config->getScope(),
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(15);
            $curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $curl->post($tokenUrl, $params);

            $statusCode = (int) $curl->getStatus();
            $body       = $curl->getBody();
        } catch (\Exception $e) {
            throw new AuthenticationException(
                __('WoCK: Failed to reach token endpoint: %1', $e->getMessage()),
                $e
            );
        }

        if ($statusCode !== 200) {
            $this->logger->error('WoCK token request failed', [
                'status' => $statusCode,
                'body'   => $body,
            ]);
            throw new AuthenticationException(
                __('WoCK: Token endpoint returned HTTP %1. Check credentials.', $statusCode)
            );
        }

        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            $this->logger->error('WoCK token response missing access_token', ['body' => $body]);
            throw new AuthenticationException(__('WoCK: Token response did not contain an access_token.'));
        }

        $token  = $data['access_token'];
        // Cache slightly below the actual TTL to avoid racing against expiry
        $ttl    = $this->config->getTokenTtl();
        $this->cache->save($token, self::CACHE_KEY, [], $ttl);

        $this->logger->debug('WoCK access token refreshed', ['ttl' => $ttl]);

        return $token;
    }
}
