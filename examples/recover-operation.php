<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\RecoveryManager;

// Restrict this to an authorized operator. A candidate ID remains subject to canonical proof.
return static function (string $localOperationId, ?string $candidateId = null): Operation {
    $operation = ModelRegistry::query('operation')->where(app(BillingContext::class)->attributes())->findOrFail($localOperationId);
    app(RecoveryManager::class)->operation($operation, $candidateId);

    return $operation->refresh();
};
