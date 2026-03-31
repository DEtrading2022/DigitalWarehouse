<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Api;

/**
 * Retrieves a valid JWT Bearer token for WoCK API calls.
 */
interface TokenManagerInterface
{
    /**
     * Returns a valid access token, refreshing it when expired.
     *
     * @throws \DigitalWarehouse\Wock\Exception\AuthenticationException
     */
    public function getAccessToken(): string;

    /**
     * Forces a fresh token to be fetched, bypassing any cached value.
     *
     * @throws \DigitalWarehouse\Wock\Exception\AuthenticationException
     */
    public function refreshToken(): string;
}
