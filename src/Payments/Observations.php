<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\PaymentModel;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;

class Observations
{
    public function __construct(private readonly Context $context) {}

    public function record(PaymentModel $resource, array $payload): Observation
    {
        $this->context->assertModel($resource);
        app(CommitGuard::class)->assertMayCommit();
        if (DB::transactionLevel() < 1) {
            throw new ConflictException('Payments observations must commit with their canonical projection.');
        }
        $allowed = ['remote_id', 'status', 'amount', 'currency', 'captured_amount', 'payment_intent_id', 'financial_identity'];
        if (array_diff(array_keys($payload), $allowed) !== [] || array_filter($payload, fn ($value) => $value !== null && ! is_string($value)) !== []) {
            throw new ConflictException('Payments observation payload must contain safe financial classifications.');
        }
        $identity = [...$this->context->attributes(), 'observation_key' => hash('sha256', CanonicalJson::encode([$resource->getTable(), $resource->id, $payload]))];

        return Observation::query()->firstOrCreate($identity, ['resource_type' => $resource->getTable(), 'resource_id' => $resource->id, 'payload' => $payload]);
    }
}
