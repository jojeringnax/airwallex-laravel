<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jojeringnax\AirwallexLaravel\ModelRegistry;

class Checkout extends BillingModel
{
    protected $table = 'airwallex_checkouts';

    protected $casts = ['url' => 'encrypted'];

    protected function immutable(): array
    {
        return [...parent::immutable(), 'customer_id', 'operation_id', 'name', 'mode', 'remote_id'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('customer'), 'customer_id');
    }
}
