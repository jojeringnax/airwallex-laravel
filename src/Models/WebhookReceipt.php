<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

class WebhookReceipt extends BillingModel
{
    protected $table = 'airwallex_webhook_receipts';

    protected $casts = ['body' => 'encrypted', 'lease_until' => 'immutable_datetime'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'event_id', 'digest', 'event_name', 'version', 'resource_id'];
    }
}
