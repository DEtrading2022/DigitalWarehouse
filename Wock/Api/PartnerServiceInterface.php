<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Api;

use DigitalWarehouse\Wock\Exception\ApiException;

/**
 * Retrieves partner account information from WoCK (credit line, balance, stock limits).
 */
interface PartnerServiceInterface
{
    /**
     * Fetch the authenticated partner's account details.
     *
     * @return array{id: string, available: string, used: string, partnerType: string, stockLimit: int|null}
     * @throws ApiException
     */
    public function getPartner(): array;
}
