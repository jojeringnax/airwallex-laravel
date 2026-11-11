<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Throwable;

final class FailureClassification
{
    public static function of(Throwable $error): string
    {
        return match (true) {
            $error instanceof AmbiguousOperationException => $error->causeCategory,
            $error instanceof OwnershipException => 'ownership',
            $error instanceof ConflictException => 'conflict',
            $error instanceof EvidenceException => 'evidence',
            $error instanceof SdkException => 'sdk',
            $error instanceof BillingException => 'configuration',
            default => 'retryable_local_failure',
        };
    }
}
