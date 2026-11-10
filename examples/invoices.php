<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

// Authorize the local billable before this server-side read or exposing either link.
// Draft/absent links are null. SDK errors propagate; retrieval promises no renewal.
return static function (Model $billable, string $invoiceId): array {
    $invoice = $billable->findInvoice($invoiceId);

    return ['pdf_url' => $invoice->pdfUrl(), 'hosted_url' => $invoice->hostedUrl()];
};
