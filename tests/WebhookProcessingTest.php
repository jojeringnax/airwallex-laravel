<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Services\BillingWebhookProcessor;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\ReceiptManager;
use Jojeringnax\AirwallexLaravel\Services\ReceiptOutcome;
use Jojeringnax\AirwallexLaravel\Support\BillingWebhookEnvelope;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;

class WebhookProcessingTest extends TestCase
{
    private Customer $customer;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $this->team = Team::create(['id' => 'team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($this->team);
        $this->customer->update(['remote_id' => 'customer-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fake(array $responses): FakeHttpClient
    {
        app('cache')->flush();
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    private function observation(array $remote): array
    {
        return [FakeHttpClient::json($remote), Billing::page([]), FakeHttpClient::json($remote)];
    }

    private function envelope(string $name, array $resource, bool $wrapped = true): array
    {
        return ['id' => 'event-test', 'name' => $name, 'org_id' => 'org-test', 'version' => '2026-08-21', 'created_at' => '2026-11-10T12:00:00Z', 'data' => $wrapped ? ['object' => $resource] : $resource];
    }

    private function receipt(string $name, array $resource, bool $wrapped = true): WebhookReceipt
    {
        $body = json_encode($this->envelope($name, $resource, $wrapped), JSON_THROW_ON_ERROR);
        $identity = BillingWebhookEnvelope::inspect(WebhookEvent::fromJson($body)->toArray(), app(BillingContext::class));

        return WebhookReceipt::create([...app(BillingContext::class)->attributes(), 'event_id' => $identity['eventId'], 'event_name' => $identity['eventName'], 'version' => $identity['version'], 'resource_id' => $identity['resourceId'], 'body' => $body, 'digest' => hash('sha256', $body), 'status' => 'processing']);
    }

    #[DataProvider('catalogue')]
    public function test_selected_catalogue_accepts_direct_and_wrapped_identity_without_applying_embedded_state(string $name, bool $wrapped): void
    {
        $decoded = WebhookEvent::fromJson(json_encode($this->envelope($name, ['id' => 'resource-test', 'status' => 'FUTURE_STATUS'], $wrapped), JSON_THROW_ON_ERROR))->toArray();
        $identity = BillingWebhookEnvelope::inspect($decoded, app(BillingContext::class));
        self::assertSame('pending', $identity['status']);
        self::assertSame('resource-test', $identity['resourceId']);
        self::assertSame('FUTURE_STATUS', $identity['resource']['status']);
        self::assertSame(0, Subscription::count());
    }

    public static function catalogue(): iterable
    {
        $families = ['subscription' => ['created', 'in_trial', 'active', 'unpaid', 'modified', 'cancelled'], 'billing_checkout' => ['created', 'completed', 'cancelled'], 'invoice' => ['created', 'finalized', 'updated', 'payment.paid', 'voided']];
        foreach ($families as $family => $events) {
            foreach ($events as $event) {
                foreach ([true, false] as $wrapped) {
                    yield $family.'.'.$event.($wrapped ? '-wrapped' : '-direct') => [$family.'.'.$event, $wrapped];
                }
            }
        }
    }

    public function test_conflicting_owners_versions_and_malformed_wrappers_are_terminal_classifications(): void
    {
        $valid = $this->envelope('subscription.active', ['id' => 'sub-test']);
        $cases = [
            [array_replace($valid, ['org_id' => 'foreign']), 'quarantined', 'foreign_context'],
            [array_replace($valid, ['account_id' => 'org-test']), 'quarantined', 'foreign_context'],
            [array_replace($valid, ['version' => null]), 'quarantined', 'unsupported_version'],
            [array_replace($valid, ['name' => 'subscription.updated']), 'ignored', 'unsupported_event'],
            [array_replace($valid, ['data' => ['id' => 'sub-test', 'object' => null]]), 'quarantined', 'malformed_resource'],
        ];
        foreach ($cases as [$envelope, $status, $reason]) {
            $identity = BillingWebhookEnvelope::inspect($envelope, app(BillingContext::class));
            self::assertSame($status, $identity['status']);
            self::assertSame($reason, $identity['reason']);
        }
    }

    public function test_duplicate_and_old_status_notifications_read_current_state_and_do_not_duplicate_projection_events(): void
    {
        $old = Billing::subscription(['status' => 'UNPAID']);
        $this->fake($this->observation($old));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $receipt = $this->receipt('subscription.unpaid', $old, false);
        $current = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($current), ...$this->observation($current)]);
        self::assertSame('processed', app(BillingWebhookProcessor::class)->process($receipt)->status);
        self::assertTrue($subscription->fresh()->valid());
        $events = DomainEvent::where('event_name', 'SubscriptionReconciled')->count();
        self::assertSame('processed', app(BillingWebhookProcessor::class)->process($receipt)->status);
        self::assertSame($events, DomainEvent::where('event_name', 'SubscriptionReconciled')->count());
        self::assertSame(1, Subscription::count());
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    #[DataProvider('replacementKinds')]
    public function test_old_cancellation_cannot_release_a_replacement_or_its_inflight_reservation(bool $reservation): void
    {
        $old = Billing::subscription(['status' => 'CANCELLED']);
        $this->fake($this->observation($old));
        $history = $this->team->importSubscription('default', 'sub-test');
        if ($reservation) {
            app(OperationManager::class)->prepare($this->customer, 'replacement', 'checkout.subscription', [], ['name' => 'default'], name: 'default');
        } else {
            $this->fake($this->observation(Billing::subscription(['id' => 'sub-replacement'])));
            $this->team->importSubscription('default', 'sub-replacement');
        }
        $slot = SubscriptionSlot::first()->getAttributes();
        $receipt = $this->receipt('subscription.cancelled', $old);
        $this->fake([...$this->observation($old), ...$this->observation($old)]);
        app(BillingWebhookProcessor::class)->process($receipt);
        app(BillingWebhookProcessor::class)->process($receipt);
        self::assertSame('CANCELLED', $history->fresh()->status);
        self::assertSame($slot, SubscriptionSlot::first()->getAttributes());
    }

    public static function replacementKinds(): array
    {
        return [[false], [true]];
    }

    public function test_unknown_subscription_is_ignored_without_customer_search_or_remote_request(): void
    {
        $receipt = $this->receipt('subscription.created', Billing::subscription());
        $http = $this->fake([]);
        $outcome = app(BillingWebhookProcessor::class)->process($receipt);
        self::assertSame('ignored', $outcome->status);
        self::assertSame('unknown_resource', $outcome->reason);
        self::assertSame([], $http->requests);
        self::assertSame(0, Subscription::count());
    }

    public function test_unknown_direct_creation_recovers_only_through_canonical_reserved_metadata(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'creation', 'subscription.create', [], ['name' => 'default', 'payload' => ['collection_method' => 'OUT_OF_BAND']], name: 'default');
        $operation->update(['status' => 'ambiguous']);
        $remote = Billing::subscription(['metadata' => ['_laravel_operation_id' => $operation->id]]);
        $receipt = $this->receipt('subscription.created', $remote);
        $http = $this->fake([FakeHttpClient::json($remote), ...$this->observation($remote)]);
        self::assertSame('processed', app(BillingWebhookProcessor::class)->process($receipt)->status);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame('sub-test', $operation->fresh()->remote_id);
        self::assertSame('default', Subscription::first()->name);
        foreach (array_slice($http->requests, 1) as $request) {
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function test_copied_event_marker_cannot_bind_an_uncorrelated_canonical_resource(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'creation', 'subscription.create', [], ['name' => 'default', 'payload' => []], name: 'default');
        $operation->update(['status' => 'ambiguous']);
        $receipt = $this->receipt('subscription.created', Billing::subscription(['metadata' => ['_laravel_operation_id' => $operation->id]]));
        $this->fake([FakeHttpClient::json(Billing::subscription())]);
        self::assertSame('quarantined', app(BillingWebhookProcessor::class)->process($receipt)->status);
        self::assertNull($operation->fresh()->remote_id);
        self::assertSame(0, Subscription::count());
    }

    public function test_early_setup_notification_confirms_owned_source_without_subscription_access(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'setup', 'checkout.setup', [], ['name' => null, 'payload' => []]);
        $operation->update(['status' => 'in_flight']);
        $remote = Billing::checkout(['mode' => 'SETUP', 'status' => 'COMPLETED', 'payment_source_id' => 'source-test', 'metadata' => ['_laravel_operation_id' => $operation->id]]);
        $receipt = $this->receipt('billing_checkout.completed', $remote, false);
        $this->fake([FakeHttpClient::json($remote), FakeHttpClient::json(Billing::source())]);
        self::assertSame('processed', app(BillingWebhookProcessor::class)->process($receipt)->status);
        self::assertSame('source-test', Checkout::first()->payment_source_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame(0, Subscription::count());
        self::assertFalse($this->team->subscribed('default'));
    }

    public function test_canonical_checkout_ownership_failure_is_quarantined_without_releasing_the_financial_intent(): void
    {
        $operation = app(OperationManager::class)->prepare($this->customer, 'setup', 'checkout.setup', [], ['name' => null, 'payload' => []]);
        $operation->update(['status' => 'in_flight']);
        $remote = Billing::checkout(['mode' => 'SETUP', 'metadata' => ['_laravel_operation_id' => $operation->id]]);
        $receipt = $this->receipt('billing_checkout.created', $remote);
        $http = $this->fake([FakeHttpClient::json([...$remote, 'billing_customer_id' => 'foreign'])]);
        $outcome = app(BillingWebhookProcessor::class)->process($receipt);
        self::assertSame('quarantined', $outcome->status);
        self::assertSame('resource_ownership_conflict', $outcome->reason);
        self::assertSame('in_flight', $operation->fresh()->status);
        self::assertNotNull($operation->fresh()->lock_key);
        self::assertNull($operation->fresh()->remote_id);
        self::assertSame(0, Checkout::count());
        self::assertSame('GET', $http->requests[1]->getMethod());
    }

    public function test_refresh_does_not_turn_an_unknown_existing_target_write_into_known_success(): void
    {
        $remote = Billing::subscription(['status' => 'UNPAID']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $operation = app(OperationManager::class)->prepare($this->customer, 'replacement-source', 'subscription.payment_source', [], ['remote_id' => 'sub-test', 'payload' => ['payment_source_id' => 'source-test']], $subscription);
        $operation->update(['status' => 'ambiguous']);
        $receipt = $this->receipt('subscription.active', Billing::subscription());
        $this->fake($this->observation(Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z'])));
        app(BillingWebhookProcessor::class)->process($receipt);
        self::assertSame('ambiguous', $operation->fresh()->status);
        self::assertNull($operation->fresh()->remote_id);
        self::assertSame('subscription/'.$subscription->id, $operation->fresh()->lock_key);
    }

    public function test_invoice_paid_notification_refreshes_subscription_without_assuming_access(): void
    {
        $unpaid = Billing::subscription(['status' => 'UNPAID']);
        $this->fake($this->observation($unpaid));
        $this->team->importSubscription('default', 'sub-test');
        $invoice = ['id' => 'invoice-test', 'billing_customer_id' => 'customer-test', 'subscription_id' => 'sub-test', 'status' => 'FINALIZED', 'payment_status' => 'PAID', 'currency' => 'USD', 'client_secret' => 'SYNTHETIC-SECRET'];
        $receipt = $this->receipt('invoice.payment.paid', $invoice);
        $this->fake([FakeHttpClient::json($invoice), ...$this->observation($unpaid)]);
        $processor = app(BillingWebhookProcessor::class);
        $outcome = $processor->process($receipt);
        self::assertFalse($this->team->subscribed('default'));
        self::assertSame(0, DomainEvent::where('event_name', 'InvoiceObserved')->count());
        DB::transaction(function () use ($processor, $receipt, $outcome): void {
            $receipt->update(['status' => $outcome->status]);
            $processor->recordOutcome($receipt, $outcome);
        });
        self::assertSame(1, DomainEvent::where('event_name', 'InvoiceObserved')->count());
        self::assertSame(1, DomainEvent::where('event_name', 'WebhookProcessed')->count());
        self::assertStringNotContainsString('SYNTHETIC-SECRET', json_encode(DomainEvent::all()->pluck('payload'), JSON_THROW_ON_ERROR));
    }

    public function test_provider_failure_keeps_projection_and_does_not_record_terminal_outcome(): void
    {
        $old = Billing::subscription(['status' => 'UNPAID']);
        $this->fake($this->observation($old));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $receipt = $this->receipt('subscription.active', Billing::subscription());
        $this->fake([FakeHttpClient::json(['code' => 'unavailable'], 503)]);
        try {
            app(BillingWebhookProcessor::class)->process($receipt);
            self::fail('Expected retryable provider failure');
        } catch (ApiException) {
        }
        self::assertSame('UNPAID', $subscription->fresh()->status);
        self::assertSame('processing', $receipt->fresh()->status);
        self::assertSame(0, DomainEvent::where('event_name', 'WebhookProcessed')->count());
    }

    public function test_receipt_commit_guard_rolls_back_a_projection_after_lease_loss(): void
    {
        $old = Billing::subscription(['status' => 'UNPAID']);
        $this->fake($this->observation($old));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $receipt = $this->receipt('subscription.active', Billing::subscription());
        $this->fake($this->observation(Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z'])));
        try {
            app(CommitGuard::class)->during(static fn () => throw new \RuntimeException('Lease lost'), fn () => app(BillingWebhookProcessor::class)->process($receipt));
            self::fail('Expected commit fence');
        } catch (\RuntimeException $exception) {
            self::assertSame('Lease lost', $exception->getMessage());
        }
        self::assertSame('UNPAID', $subscription->fresh()->status);
        self::assertSame(1, DomainEvent::where('event_name', 'SubscriptionReconciled')->count());
        app(CommitGuard::class)->assertMayCommit();
    }

    public function test_real_receipt_worker_recovers_crash_after_projection_without_duplicate_invoice_or_projection_events(): void
    {
        $unpaid = Billing::subscription(['status' => 'UNPAID']);
        $this->fake($this->observation($unpaid));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $invoice = ['id' => 'invoice-test', 'billing_customer_id' => 'customer-test', 'subscription_id' => 'sub-test', 'status' => 'FINALIZED', 'payment_status' => 'PAID', 'currency' => 'USD'];
        $body = json_encode($this->envelope('invoice.payment.paid', $invoice, false), JSON_THROW_ON_ERROR);
        config(['airwallex.webhook.secret' => 'synthetic-secret']);
        $timestamp = (string) now()->getTimestampMs();
        $signature = hash_hmac('sha256', $timestamp.$body, 'synthetic-secret');
        $manager = app(ReceiptManager::class);
        $receipt = $manager->accept($body, $timestamp, $signature);
        $processor = new class(app(BillingContext::class)) extends BillingWebhookProcessor
        {
            public bool $fail = true;

            public function recordOutcome(WebhookReceipt $receipt, ReceiptOutcome $outcome): void
            {
                parent::recordOutcome($receipt, $outcome);
                if ($this->fail) {
                    $this->fail = false;
                    throw new \RuntimeException('SYNTHETIC-FINALIZATION-SECRET');
                }
            }
        };
        $this->app->instance(BillingWebhookProcessor::class, $processor);
        $active = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $this->fake([FakeHttpClient::json($invoice), ...$this->observation($active)]);
        $failed = $manager->process($receipt->id);
        self::assertSame('failed', $failed->status);
        self::assertSame('processing_failed', $failed->last_error);
        self::assertSame('ACTIVE', $subscription->fresh()->status);
        self::assertSame(2, DomainEvent::where('event_name', 'SubscriptionReconciled')->count());
        self::assertSame(0, DomainEvent::where('event_name', 'InvoiceObserved')->count());
        self::assertSame(0, DomainEvent::where('event_name', 'WebhookProcessed')->count());
        $this->fake([FakeHttpClient::json($invoice), ...$this->observation($active)]);
        $completed = $manager->process($receipt->id);
        self::assertSame('processed', $completed->status);
        self::assertSame(2, $completed->attempts);
        self::assertSame(2, DomainEvent::where('event_name', 'SubscriptionReconciled')->count());
        self::assertSame(1, DomainEvent::where('event_name', 'InvoiceObserved')->count());
        self::assertSame(1, DomainEvent::where('event_name', 'WebhookProcessed')->count());
        self::assertSame($receipt->id, $manager->accept($body, $timestamp, $signature)->id);
        $http = $this->fake([]);
        self::assertSame('processed', $manager->process($receipt->id)->status);
        self::assertSame([], $http->requests);
    }

    public function test_unknown_invoice_customer_never_triggers_a_lookup_or_adoption(): void
    {
        $receipt = $this->receipt('invoice.created', ['id' => 'invoice-unknown', 'billing_customer_id' => 'unmapped']);
        $http = $this->fake([]);
        $outcome = app(BillingWebhookProcessor::class)->process($receipt);
        self::assertSame('ignored', $outcome->status);
        self::assertSame('unknown_customer', $outcome->reason);
        self::assertSame([], $http->requests);
        self::assertSame(1, Customer::count());
        self::assertSame(0, Subscription::count());
    }

    public function test_a_mapping_removed_during_canonical_read_is_not_recreated_by_notification(): void
    {
        $remote = Billing::subscription(['status' => 'CANCELLED']);
        $this->fake($this->observation($remote));
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $receipt = $this->receipt('subscription.cancelled', $remote);
        app('cache')->flush();
        $http = new InterleavingHttpClient([
            FakeHttpClient::login(),
            function () use ($subscription, $remote) {
                $subscription->delete();

                return FakeHttpClient::json($remote);
            },
            Billing::page([]), FakeHttpClient::json($remote),
        ]);
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);
        try {
            app(BillingWebhookProcessor::class)->process($receipt);
            self::fail('Expected removed mapping conflict');
        } catch (ConflictException) {
        }
        self::assertSame(0, Subscription::count());
    }
}
