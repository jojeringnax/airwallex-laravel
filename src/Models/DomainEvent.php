<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

class DomainEvent extends BillingModel
{
    protected $table = 'airwallex_domain_events';

    protected $casts = ['payload' => 'array', 'dispatched_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
}
