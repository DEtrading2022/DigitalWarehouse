<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Exception;

use Magento\Framework\Exception\LocalizedException;

class ApiException extends LocalizedException
{
    /** @var array<string, mixed> */
    private array $errors = [];

    public function setErrors(array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns the first error extension code if present, e.g. PRODUCT_UNIQUE_ERR
     */
    public function getFirstErrorCode(): string
    {
        return $this->errors[0]['extensions']['code'] ?? '';
    }
}
