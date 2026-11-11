<?php

declare(strict_types=1);

use Jojeringnax\AirwallexLaravel\Invoice;

// Retrieve this Invoice through an authorized billable first. Observations are not cash allocations.
// Requires the exact SDK transaction extension identified in docs/contracts.md.
return static function (Invoice $invoice, string $transactionId, array $query = []): array {
    return ['transactions' => $invoice->transactions($query), 'transaction' => $invoice->transaction($transactionId)];
};
