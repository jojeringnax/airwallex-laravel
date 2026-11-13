<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation as PaymentOperation;
use Jojeringnax\AirwallexLaravel\Services\CreationRetries;
use Throwable;

class CreationRetryCommand extends BillingCommand
{
    protected $signature = 'airwallex:allow-creation-retry {id : Original local operation ID} {--payments : Use the configured Payments account} {--actor= : Authorized operator or consumer identifier} {--reason= : Private audit reason or ticket reference}';

    protected $description = 'Release an unknown creation for a new command, permitting a duplicate without asserting noncreation';

    public function handle(CreationRetries $retries): int
    {
        try {
            $id = $this->argument('id');
            $actor = $this->identifierOption('actor');
            $reason = $this->identifierOption('reason');
            if (! is_string($id) || $id === '' || $actor === null || $reason === null) {
                throw new ConflictException('Select an operation and supply actor/reason.');
            }
            $payments = $this->option('payments');
            $context = $payments ? app(Context::class) : app(BillingContext::class);
            $query = $payments ? PaymentOperation::query() : ModelRegistry::query('operation');
            $operation = $query->where($context->attributes())->findOrFail($id);
            $retries->allowRetry($operation, $actor, $reason);
            $this->reportRow(['id' => $id, 'outcome' => 'retry_permitted_unknown_outcome']);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
