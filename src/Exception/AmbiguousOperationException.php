<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Exception;

use Jojeringnax\Airwallex\Exception\SdkException;

class AmbiguousOperationException extends BillingException
{
    public readonly string $causeCategory;

    public function __construct(public readonly string $operationId, ?\Throwable $previous = null)
    {
        $this->causeCategory = match (true) {
            $previous instanceof OwnershipException => 'ownership',
            $previous instanceof EvidenceException => 'evidence',
            $previous instanceof ConflictException => 'conflict',
            $previous instanceof SdkException => 'sdk',
            default => 'unconfirmed',
        };
        parent::__construct('Billing operation requires reconciliation; do not repeat the remote mutation.', 0, $previous instanceof SdkException ? $previous : null);
    }
}
