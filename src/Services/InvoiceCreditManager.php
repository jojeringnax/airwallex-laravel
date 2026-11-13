<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Illuminate\Support\Facades\DB;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Resource\Billing\CreditNotes;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Support\Pages;
use Jojeringnax\AirwallexLaravel\Support\Routing;
use Jojeringnax\AirwallexLaravel\Support\SdkResponseShape;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Explicit original-Invoice credits; commercial policy and cash accounting remain application-owned. */
class InvoiceCreditManager
{
    public function __construct(private readonly Client $client, private readonly BillingContext $context, private readonly OperationManager $operations) {}

    public function create(Invoice $invoice, string $actionKey, string $type, string $reason, #[SensitiveParameter] array $options = []): Operation
    {
        $this->resource();
        if (array_diff(array_keys($options), ['memo', 'custom_reason', 'metadata']) !== []) {
            throw new ConflictException('Credit Note identity and financial fields are managed.');
        }
        $specification = ['invoice_id' => $invoice->id, 'type' => $type, 'reason' => $reason, 'options' => $options];
        [$customer, $subscription] = $this->mapping($invoice);
        $operation = $this->existing($customer, $subscription, $actionKey, 'credit_note.create', $specification);
        if ($operation === null) {
            $fresh = $this->freshInvoice($invoice);
            $this->eligible($fresh, $type);
            $payload = [...$options, 'invoice_id' => $invoice->id, 'type' => $type, 'reason' => $reason];
            $this->metadata($payload['metadata'] ?? new stdClass, 'reserved');
            $operation = $this->prepare($invoice, $actionKey, 'credit_note.create', $specification, ['payload' => $payload, 'invoice_baseline' => $this->invoiceShape($fresh)]);
        }

        return $this->dispatch($operation, $invoice);
    }

    public function addItems(Invoice $invoice, string $actionKey, string $createActionKey, #[SensitiveParameter] array $items): Operation
    {
        $this->resource();
        $root = $this->root($invoice, $createActionKey);
        $specification = ['invoice_id' => $invoice->id, 'create_action_key' => $createActionKey, 'items' => $items];
        [$customer, $subscription] = $this->mapping($invoice);
        $operation = $this->existing($customer, $subscription, $actionKey, 'credit_note.add_items', $specification);
        if ($operation === null) {
            if ($items === [] || ! array_is_list($items)) {
                throw new ConflictException('Explicit original Invoice lines are required.');
            }
            $invoiceLines = [];
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['invoice_line_item_id'] ?? null) || $item['invoice_line_item_id'] === ''
                    || array_diff(array_keys($item), ['invoice_line_item_id', 'amount', 'description', 'metadata', 'tax_included']) !== []
                    || (array_key_exists('tax_included', $item) && $item['tax_included'] !== false)
                    || self::decimal($item['amount'] ?? null) === '0') {
                    throw new ConflictException('Choose an original Invoice line and a positive exact tax-exclusive credit amount.');
                }
                $this->metadata($item['metadata'] ?? new stdClass, 'reserved');
                $invoiceLines[$item['invoice_line_item_id']] = $invoice->lineItem($item['invoice_line_item_id']);
            }
            $fresh = $this->freshInvoice($invoice);
            $this->eligible($fresh, $root->intent['payload']['type']);
            $note = $this->note($invoice, $root);
            $lines = $this->lines($note);
            $this->draft($root, $note, $lines);
            $operation = $this->prepare($invoice, $actionKey, 'credit_note.add_items', $specification, [
                'create_action_key' => $createActionKey, 'note_id' => $note['id'], 'payload' => ['line_items' => $items],
                'invoice_baseline' => $this->invoiceShape($fresh), 'invoice_lines' => $invoiceLines,
                'note_baseline' => $this->noteShape($note), 'prior_lines' => $this->lineMap($lines),
            ]);
        }

        return $this->dispatch($operation, $invoice);
    }

    public function finalize(Invoice $invoice, string $actionKey, string $createActionKey, mixed $expectedTotal, #[SensitiveParameter] array $allocation = []): Operation
    {
        $this->resource();
        $root = $this->root($invoice, $createActionKey);
        $specification = ['invoice_id' => $invoice->id, 'create_action_key' => $createActionKey, 'expected_total' => $expectedTotal, 'allocation' => $allocation];
        [$customer, $subscription] = $this->mapping($invoice);
        $operation = $this->existing($customer, $subscription, $actionKey, 'credit_note.finalize', $specification);
        if ($operation === null) {
            if (self::decimal($expectedTotal) === '0') {
                throw new ConflictException('Finalization requires an explicitly authorized positive total.');
            }
            $type = $root->intent['payload']['type'];
            if ($type === 'BEFORE_PAYMENT' && $allocation !== []) {
                throw new ConflictException('Before-payment credit finalization has no refund allocation.');
            }
            if ($type === 'AFTER_PAYMENT') {
                if (count($allocation) !== 2 || ! array_key_exists('refund_amount', $allocation) || ! array_key_exists('out_of_band_amount', $allocation)) {
                    throw new ConflictException('After-payment credits require explicit refund and external-adjustment amounts.');
                }
                self::decimal($allocation['refund_amount']);
                self::decimal($allocation['out_of_band_amount']);
            }
            $fresh = $this->freshInvoice($invoice);
            $this->eligible($fresh, $type);
            $note = $this->note($invoice, $root);
            $lines = $this->lines($note);
            $this->draft($root, $note, $lines);
            if ($lines === [] || self::decimal($note['total_amount'] ?? null) !== self::decimal($expectedTotal)) {
                throw new EvidenceException('The original draft total differs from the authorized total.');
            }
            $operation = $this->prepare($invoice, $actionKey, 'credit_note.finalize', $specification, [
                'create_action_key' => $createActionKey, 'note_id' => $note['id'], 'payload' => $allocation, 'expected_total' => $expectedTotal,
                'invoice_baseline' => $this->invoiceShape($fresh), 'note_baseline' => $this->noteShape($note), 'prior_lines' => $this->lineMap($lines),
            ]);
        }

        return $this->dispatch($operation, $invoice);
    }

    /** Explicit canonical server-side access, including refund status; no settlement inference. */
    public function retrieve(Invoice $invoice, string $createActionKey): array
    {
        $this->resource();
        $this->freshInvoice($invoice);
        $note = $this->note($invoice, $this->root($invoice, $createActionKey));
        $this->mapping($invoice);

        return SubscriptionSynchronizer::sanitize($note);
    }

    /** @internal Read-only recovery, including unknown-ID original creation candidates. */
    public function recover(Operation $operation, ?string $candidateId = null): Operation
    {
        $this->resource();
        $this->context->assertModel($operation);
        $operation = ModelRegistry::query('operation')->findOrFail($operation->id);
        $this->context->assertModel($operation);
        if (! in_array($operation->kind, ['credit_note.create', 'credit_note.add_items', 'credit_note.finalize'], true)
            || ! in_array($operation->status, ['in_flight', 'ambiguous', 'succeeded'], true)) {
            throw new ConflictException('Only attempted original Credit Note commands support read recovery.', $operation->id);
        }
        if ($operation->status === 'succeeded') {
            return $operation;
        }
        $owner = $operation->subscription_id === null ? ModelRegistry::query('customer')->findOrFail($operation->customer_id) : ModelRegistry::query('subscription')->findOrFail($operation->subscription_id);
        $invoice = app(InvoiceManager::class)->retrieve($owner, $operation->intent['invoice_id']);
        try {
            $this->confirm($invoice, $operation, $candidateId);

            return $operation->fresh();
        } catch (Throwable $error) {
            throw new AmbiguousOperationException($operation->id, $error);
        }
    }

    private function dispatch(Operation $operation, Invoice $invoice): Operation
    {
        if ($operation->status === 'succeeded') {
            return $operation;
        }
        if ($operation->status !== 'prepared') {
            return $this->recover($operation);
        }
        try {
            $fresh = $this->freshInvoice($invoice);
            $type = $operation->kind === 'credit_note.create' ? $operation->intent['payload']['type'] : $this->root($invoice, $operation->intent['create_action_key'])->intent['payload']['type'];
            $this->eligible($fresh, $type);
            if (! self::same($this->invoiceShape($fresh), $operation->intent['invoice_baseline'])) {
                throw new EvidenceException('The original Invoice financial baseline changed before dispatch.');
            }
            if ($operation->kind !== 'credit_note.create') {
                $root = $this->root($invoice, $operation->intent['create_action_key']);
                $note = $this->note($invoice, $root);
                $lines = $this->lines($note);
                $this->draft($root, $note, $lines);
                if (! self::same($this->noteShape($note), $operation->intent['note_baseline']) || ! self::same($this->lineMap($lines), $operation->intent['prior_lines'])) {
                    throw new EvidenceException('The original Credit Note changed before dispatch.');
                }
                foreach ($operation->intent['invoice_lines'] ?? [] as $line) {
                    if (CanonicalJson::encode($invoice->lineItem($line['id'])) !== CanonicalJson::encode($line)) {
                        throw new EvidenceException('An original Invoice line changed before the credit action.');
                    }
                }
            }
            $this->mapping($invoice);
        } catch (Throwable $error) {
            DB::transaction(function () use ($operation): void {
                app(CommitGuard::class)->assertMayCommit();
                if (ModelRegistry::query('operation')->whereKey($operation->id)->where('status', 'prepared')->update(['status' => 'failed', 'lock_key' => null, 'error_code' => 'preflight_rejected']) !== 1) {
                    throw new AmbiguousOperationException($operation->id);
                }
            });
            throw $error;
        }
        app(CommitGuard::class)->assertMayCommit();
        $this->operations->execute($operation, function () use ($operation, $invoice): array {
            app(CommitGuard::class)->assertMayCommit();
            $this->mapping($invoice);
            $payload = $operation->intent['payload'];
            if ($operation->kind === 'credit_note.create') {
                $payload['metadata'] = $this->metadata($payload['metadata'] ?? new stdClass, $operation->id);

                return $this->resource()->create([...$payload, 'request_id' => $operation->request_id]);
            }
            if ($operation->kind === 'credit_note.add_items') {
                foreach ($payload['line_items'] as $index => &$item) {
                    $item['metadata'] = $this->metadata($item['metadata'] ?? new stdClass, $operation->id, (string) $index);
                }

                $response = $this->resource()->addLineItems($operation->intent['note_id'], [...$payload, 'request_id' => $operation->request_id]);
            } else {
                $response = $this->resource()->finalize($operation->intent['note_id'], $payload);
            }
            if (($response['id'] ?? null) !== $operation->intent['note_id']) {
                throw new OwnershipException('Credit Note response has a different original identity.');
            }

            return $response;
        }, function (#[SensitiveParameter] array $response) use ($invoice, $operation): Operation {
            $candidate = $operation->kind === 'credit_note.create' ? $response['id'] : $operation->intent['note_id'];
            if (($response['id'] ?? null) !== $candidate) {
                throw new OwnershipException('Credit Note response has a different identity.');
            }
            $root = $operation->kind === 'credit_note.create' ? $operation : $this->root($invoice, $operation->intent['create_action_key']);
            $this->verifyNote($invoice, $root, $response, $candidate);
            $this->confirm($invoice, $operation, $candidate);

            return $operation;
        });

        return $operation->fresh();
    }

    private function confirm(Invoice $invoice, Operation $operation, ?string $candidateId): void
    {
        $this->freshInvoice($invoice);
        $proof = null;
        if ($operation->kind === 'credit_note.create') {
            $note = $this->note($invoice, $operation, $candidateId);
        } else {
            $root = $this->root($invoice, $operation->intent['create_action_key']);
            $note = $this->note($invoice, $root);
            if ($note['id'] !== $operation->intent['note_id']) {
                throw new OwnershipException('Credit Note no longer matches the original command.');
            }
            $lines = $this->lineMap($this->lines($note));
            if ($operation->kind === 'credit_note.add_items') {
                $prior = $operation->intent['prior_lines'];
                $new = array_diff_key($lines, $prior);
                if (! self::same(array_intersect_key($lines, $prior), $prior) || count($new) !== count($operation->intent['payload']['line_items'])) {
                    throw new EvidenceException('Credit Note line changes do not match the original action.');
                }
                $matched = [];
                $indexes = array_map('strval', array_keys($operation->intent['payload']['line_items']));
                foreach ($new as $id => $line) {
                    $metadata = (array) ($line['metadata'] ?? []);
                    $index = $metadata['_laravel_credit_index'] ?? null;
                    $requested = is_string($index) && in_array($index, $indexes, true) ? $operation->intent['payload']['line_items'][(int) $index] : null;
                    if (($metadata['_laravel_operation_id'] ?? null) !== $operation->id || ! is_array($requested) || isset($matched[$index])
                        || ($line['invoice_line_item_id'] ?? null) !== $requested['invoice_line_item_id']
                        || self::decimal($line['amount'] ?? null) !== self::decimal($requested['amount'])) {
                        throw new EvidenceException('Credit Note line identity, original Invoice line or amount is not proven.');
                    }
                    $matched[$index] = $id;
                }
                $proof = ['lines' => $new];
            } elseif (! self::same($lines, $operation->intent['prior_lines']) || ($note['status'] ?? null) !== 'FINALIZED'
                || self::decimal($note['total_amount'] ?? null) !== self::decimal($operation->intent['expected_total'])) {
                throw new EvidenceException('The original Credit Note finalization is not confirmed.');
            } else {
                $before = $root->intent['payload']['type'] === 'BEFORE_PAYMENT';
                foreach (['adjustment_amount', 'refund_amount', 'out_of_band_amount'] as $field) {
                    $expected = $before ? ($field === 'adjustment_amount' ? $operation->intent['expected_total'] : '0') : ($operation->intent['payload'][$field] ?? '0');
                    if (self::decimal($note[$field] ?? null) !== self::decimal($expected)) {
                        throw new EvidenceException('Canonical credit allocation differs from the original authorized allocation.');
                    }
                }
            }
        }
        DB::transaction(function () use ($invoice, $operation, $note, $proof): void {
            app(CommitGuard::class)->assertMayCommit();
            ModelRegistry::query('customer')->whereKey($operation->customer_id)->lockForUpdate()->firstOrFail();
            [$customer] = $this->mapping($invoice);
            $current = ModelRegistry::query('operation')->findOrFail($operation->id);
            $this->context->assertModel($current);
            if ($customer->id !== $current->customer_id || ! in_array($current->status, ['in_flight', 'ambiguous', 'succeeded'], true)
                || ($current->remote_id !== null && $current->remote_id !== $note['id'])) {
                throw new OwnershipException('Original Credit Note command identity cannot be replaced.');
            }
            if ($proof !== null && $current->observation !== null && ! self::same($current->observation, $proof)) {
                throw new EvidenceException('Original credit line proof cannot be replaced.');
            }
            $current->remote_id ??= $note['id'];
            if ($proof !== null) {
                $current->observation ??= $proof;
            }
            $current->save();
            $this->operations->succeed($current);
        });
    }

    private function root(Invoice $invoice, string $actionKey): Operation
    {
        [$customer] = $this->mapping($invoice);
        $root = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'customer_id' => $customer->id, 'action_key' => $actionKey, 'kind' => 'credit_note.create', 'status' => 'succeeded'])->first();
        if ($root === null || ($root->intent['invoice_id'] ?? null) !== $invoice->id) {
            throw new OwnershipException('Credit Note action has no confirmed original owned Invoice.');
        }

        return $root;
    }

    private function note(Invoice $invoice, Operation $root, ?string $candidateId = null): array
    {
        $id = $root->remote_id ?? $candidateId;
        if (! is_string($id) || $id === '' || ($candidateId !== null && $candidateId !== $id)) {
            throw new AmbiguousOperationException($root->id);
        }
        $note = SdkResponseShape::normalize($this->resource()->retrieve($id));
        $this->verifyNote($invoice, $root, $note, $id);

        return $note;
    }

    private function verifyNote(Invoice $invoice, Operation $root, #[SensitiveParameter] array $note, string $id): void
    {
        if (($note['id'] ?? null) !== $id || ($note['invoice_id'] ?? null) !== $invoice->id
            || ($note['billing_customer_id'] ?? null) !== $invoice->billingCustomerId || ($note['currency'] ?? null) !== $root->intent['invoice_baseline']['currency']
            || ($note['type'] ?? null) !== $root->intent['payload']['type'] || ($note['reason'] ?? null) !== $root->intent['payload']['reason']
            || (((array) ($note['metadata'] ?? []))['_laravel_operation_id'] ?? null) !== $root->id) {
            throw new OwnershipException('Credit Note ownership, identity or original command correlation is unproven.');
        }
        Routing::assertSubscription($note);
        $this->remoteContext($note);
    }

    private function lines(#[SensitiveParameter] array $note): array
    {
        $lines = Pages::all(fn (array $page) => $this->resource()->listLineItems($note['id'], $page));
        foreach ($lines as $line) {
            if (! is_string($line['id'] ?? null) || $line['id'] === '' || ($line['credit_note_id'] ?? null) !== $note['id'] || ($line['currency'] ?? null) !== $note['currency']) {
                throw new OwnershipException('Credit Note line parent or currency is unproven.');
            }
            $this->remoteContext($line);
        }

        return $lines;
    }

    private function draft(Operation $root, #[SensitiveParameter] array $note, #[SensitiveParameter] array $lines): void
    {
        if (($note['status'] ?? null) !== 'DRAFT') {
            throw new EvidenceException('Only the original DRAFT Credit Note accepts a new financial stage.');
        }
        $proven = [];
        foreach (ModelRegistry::query('operation')->where([...$this->context->attributes(), 'customer_id' => $root->customer_id, 'remote_id' => $root->remote_id, 'kind' => 'credit_note.add_items', 'status' => 'succeeded'])->get() as $operation) {
            if (($operation->intent['create_action_key'] ?? null) === $root->action_key) {
                $proven += $operation->observation['lines'] ?? [];
            }
        }
        ksort($proven, SORT_STRING);
        if (! self::same($this->lineMap($lines), $proven)) {
            throw new EvidenceException('The draft contains changed or unconfirmed Credit Note lines.');
        }
    }

    private function remoteContext(#[SensitiveParameter] array $remote): void
    {
        foreach (['org_id' => $this->context->organizationId, 'organization_id' => $this->context->organizationId, 'environment' => $this->context->environment] as $key => $expected) {
            if (array_key_exists($key, $remote) && $remote[$key] !== $expected) {
                throw new OwnershipException('Credit Note resource belongs to a foreign context.');
            }
        }
    }

    private function prepare(Invoice $invoice, string $key, string $kind, #[SensitiveParameter] array $specification, #[SensitiveParameter] array $intent): Operation
    {
        [$customer, $subscription] = $this->mapping($invoice);

        return $this->operations->prepare($customer, $key, $kind, $specification, ['invoice_id' => $invoice->id, ...$intent], $subscription, lockKey: 'invoice/'.hash('sha256', CanonicalJson::encode([$customer->id, $invoice->id])));
    }

    private function existing(Customer $customer, ?Subscription $subscription, string $key, string $kind, #[SensitiveParameter] array $specification): ?Operation
    {
        $operation = ModelRegistry::query('operation')->where([...$this->context->attributes(), 'action_key' => $key])->first();
        if ($operation !== null && ! hash_equals($operation->intent_hash, hash('sha256', CanonicalJson::encode([$kind, $customer->id, $subscription?->id, null, $specification])))) {
            throw new ConflictException('Action key was already used for a different credit intent.', $operation->id);
        }

        return $operation;
    }

    private function mapping(Invoice $invoice): array
    {
        [$context, $customerId, $subscriptionId] = $invoice->scope();
        if ($context->attributes() !== $this->context->attributes()) {
            throw new OwnershipException('Invoice belongs to another Billing context.');
        }
        $customer = ModelRegistry::query('customer')->findOrFail($customerId);
        $this->context->assertModel($customer);
        $subscription = $subscriptionId === null ? null : ModelRegistry::query('subscription')->findOrFail($subscriptionId);
        if ($subscription !== null) {
            $this->context->assertModel($subscription);
        }
        if ($customer->remote_id !== $invoice->billingCustomerId || ($subscription !== null && ($subscription->customer_id !== $customer->id || $subscription->remote_id !== $invoice->subscriptionId))) {
            throw new OwnershipException('Original Invoice mapping no longer agrees.');
        }

        return [$customer, $subscription];
    }

    private function freshInvoice(Invoice $invoice): array
    {
        [$customer, $subscription] = $this->mapping($invoice);
        $fresh = app(InvoiceManager::class)->retrieve($subscription ?? $customer, $invoice->id);
        if ($fresh->subscriptionId !== $invoice->subscriptionId) {
            throw new OwnershipException('Original Invoice subscription changed.');
        }
        $data = $fresh->toArray();
        Routing::assertSubscription($data);

        return $data;
    }

    private function eligible(#[SensitiveParameter] array $invoice, string $type): void
    {
        if (($invoice['status'] ?? null) !== 'FINALIZED' || ! in_array($type, ['BEFORE_PAYMENT', 'AFTER_PAYMENT'], true)
            || ($invoice['payment_status'] ?? null) !== ($type === 'BEFORE_PAYMENT' ? 'UNPAID' : 'PAID')) {
            throw new EvidenceException('Credit type requires the original finalized Invoice and matching payment state.');
        }
    }

    private function invoiceShape(#[SensitiveParameter] array $invoice): array
    {
        return array_intersect_key($invoice, array_flip(['id', 'billing_customer_id', 'subscription_id', 'currency', 'status', 'payment_status', 'collection_method', 'payment_source_id', 'legal_entity_id', 'linked_payment_account_id', 'total_amount', 'amount_due', 'credit_note_amount_before_payment', 'credit_note_amount_after_payment', 'total_tax_amount', 'total_discount_amounts']));
    }

    private function noteShape(#[SensitiveParameter] array $note): array
    {
        return array_intersect_key($note, array_flip(['id', 'invoice_id', 'billing_customer_id', 'currency', 'status', 'type', 'reason', 'custom_reason', 'metadata', 'total_amount', 'total_tax_amount', 'total_discount_amounts', 'adjustment_amount', 'refund_amount', 'out_of_band_amount']));
    }

    private function lineMap(#[SensitiveParameter] array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $map[$line['id']] = array_intersect_key($line, array_flip(['id', 'credit_note_id', 'invoice_line_item_id', 'currency', 'amount', 'quantity', 'unit_amount', 'tax_amount', 'tax_percent', 'tax_breakdown', 'discount_amounts', 'metadata']));
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    private function metadata(#[SensitiveParameter] mixed $metadata, string $id, ?string $index = null): stdClass
    {
        if (! $metadata instanceof stdClass && (! is_array($metadata) || ($metadata !== [] && array_is_list($metadata)))) {
            throw new ConflictException('Credit correlation requires object metadata.');
        }
        $metadata = (array) $metadata;
        if (array_intersect(array_keys($metadata), ['_laravel_operation_id', '_laravel_credit_index']) !== []) {
            throw new ConflictException('Credit operation correlation metadata is reserved.');
        }

        return (object) [...$metadata, '_laravel_operation_id' => $id, ...($index === null ? [] : ['_laravel_credit_index' => $index])];
    }

    private static function decimal(mixed $amount): string
    {
        if (! is_string($amount) || ! preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $amount, $parts)) {
            throw new EvidenceException('Credit amounts require exact unsigned decimal strings.');
        }
        $fraction = rtrim($parts[2] ?? '', '0');

        return (ltrim($parts[1], '0') ?: '0').($fraction === '' ? '' : '.'.$fraction);
    }

    private static function same(#[SensitiveParameter] mixed $left, #[SensitiveParameter] mixed $right): bool
    {
        return CanonicalJson::encode($left) === CanonicalJson::encode($right);
    }

    private function resource(): CreditNotes
    {
        return $this->client->billing()->creditNotes();
    }
}
