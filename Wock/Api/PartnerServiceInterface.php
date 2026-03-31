<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Api;

use DigitalWarehouse\Wock\Exception\ApiException;

interface PartnerServiceInterface
{
    /**
     * Retrieve the partner's financial balance and credit line.
     *
     * - `available` = Credit Line + Balance
     * - `used`      = Already used funds
     *
     * @return array{
     *     id: string,
     *     available: string,
     *     used: string,
     *     partnerType: string,
     *     stockLimit: int
     * }
     * @throws ApiException
     */
    public function getPartner(): array;
}
