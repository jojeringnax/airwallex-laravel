<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\BillingModel;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\PaymentSource;

/** Read recovery is deliberately separate from financial command execution. */
class RecoveryManager
{
    public function __construct(private readonly BillingContext $context) {}

    public function operation(Operation $operation, ?string $candidateId = null): BillingModel|PaymentSource
    {
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->where($this->context->attributes())->findOrFail($operation->id);
        if (app(CreationRetries::class)->quarantined($operation, $candidateId)) {
            return $operation;
        }
        if (! in_array($operation->status, ['in_flight', 'ambiguous', 'acknowledged', 'succeeded'], true)) {
            throw new ConflictException('Recovery cannot submit an unattempted or rejected financial command.', $operation->id);
        }
        if ($candidateId !== null && ! in_array($operation->kind, ['subscription.create', 'checkout.subscription', 'checkout.setup', 'credit_note.create', 'payment_source.create'], true)) {
            throw new ConflictException('A candidate resource is supported only for correlated creation recovery.', $operation->id);
        }
        if ($operation->kind === 'payment_source.create') {
            return app(SourceManager::class)->recover($operation, $candidateId);
        }
        if (str_starts_with($operation->kind, 'customer.')) {
            return app(CustomerManager::class)->reconcileOperation($operation);
        }
        if ($operation->kind === 'subscription.create') {
            return app(SubscriptionManager::class)->recoverCreation($operation, $candidateId);
        }
        if (str_starts_with($operation->kind, 'subscription.')) {
            return app(SubscriptionManager::class)->reconcileOperation($operation);
        }
        if (str_starts_with($operation->kind, 'checkout.')) {
            return app(CheckoutManager::class)->reconcileOperation($operation, $candidateId);
        }
        if (str_starts_with($operation->kind, 'credit_note.')) {
            return app(InvoiceCreditManager::class)->recover($operation, $candidateId);
        }

        throw new ConflictException('Operation kind has no supported read recovery.', $operation->id);
    }
}
