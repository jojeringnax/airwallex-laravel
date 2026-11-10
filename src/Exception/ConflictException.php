<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Exception;

class ConflictException extends BillingException
{
    public function __construct(string $message = 'Billing intent conflicts with existing state.', public readonly ?string $operationId = null)
    {
        parent::__construct($message);
    }
}
