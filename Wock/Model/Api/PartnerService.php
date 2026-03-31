<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Model\Api;

use DigitalWarehouse\Wock\Api\PartnerServiceInterface;
use DigitalWarehouse\Wock\Exception\ApiException;

class PartnerService implements PartnerServiceInterface
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function getPartner(): array
    {
        $query = <<<'GQL'
            query partner {
                partner {
                    id
                    available
                    used
                    partnerType
                    stockLimit
                }
            }
            GQL;

        $data = $this->client->execute($query);

        return $data['partner'] ?? [];
    }
}
