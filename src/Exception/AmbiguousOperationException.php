<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Exception;

use Jojeringnax\Airwallex\Exception\SdkException;

class AmbiguousOperationException extends BillingException
{
    public function __construct(public readonly string $operationId, ?\Throwable $previous = null)
    {
        parent::__construct('Billing operation requires reconciliation; do not repeat the remote mutation.', 0, $previous instanceof SdkException ? $previous : null);
    }
}
