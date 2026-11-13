<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Facades\DB;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use SensitiveParameter;

/** Set-once original Discount identity shared by direct writes and verified hosted results. */
class DiscountProof
{
    public function __construct(private readonly BillingContext $context) {}

    public function record(Operation $operation, Subscription $subscription, #[SensitiveParameter] array $resource, FreePeriodEvidence $evidence): void
    {
        $this->context->assertModel($operation);
        $proof = $evidence->proof($subscription, $resource, $operation->intent['coupon_terms']);
        DB::transaction(function () use ($operation, $proof, $evidence): void {
            app(CommitGuard::class)->assertMayCommit();
            ModelRegistry::query('customer')->whereKey($operation->customer_id)->lockForUpdate()->firstOrFail();
            $current = ModelRegistry::query('operation')->findOrFail($operation->id);
            $this->context->assertModel($current);
            if ($current->observation !== null) {
                if (! $evidence->sameProof($proof, $current->observation['discount_proof'] ?? [])) {
                    throw new EvidenceException('Original Discount response evidence cannot be replaced.');
                }
            } else {
                $current->observation = ['discount_proof' => $proof];
                $current->save();
            }
        });
        $operation->refresh();
    }
}
