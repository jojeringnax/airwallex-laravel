<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\InvoiceManager;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\Outbox;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class InvoiceTest extends TestCase
{
    private Team $team;

    private Customer $customer;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        $this->team = Team::create(['id' => 'invoice-team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($this->team);
        $this->customer->update(['remote_id' => 'customer-test']);
        $this->subscription = Subscription::create([
            ...app(BillingContext::class)->attributes(), 'customer_id' => $this->customer->id,
            'remote_id' => 'sub-test', 'name' => 'default', 'status' => 'ACTIVE',
            'starts_at' => '2026-11-01T00:00:00Z', 'cancel_at_period_end' => false, 'snapshot' => [],
        ]);
    }

    public function test_authorized_billable_recipe_uses_real_sdk_and_exposes_links_only_explicitly(): void
    {
        $remote = self::invoice(['status' => 'FINALIZED', 'pdf_url' => 'https://example.test/private-pdf', 'hosted_url' => 'https://example.test/private-hosted']);
        $http = $this->http([self::response($remote), self::response($remote)]);
        $recipe = require __DIR__.'/../examples/invoices.php';
        self::assertSame(['pdf_url' => $remote['pdf_url'], 'hosted_url' => $remote['hosted_url']], $recipe($this->team, 'invoice-test'));
        $invoice = $this->team->findInvoice('invoice-test');
        self::assertSame('invoice-test', $invoice->id);
        self::assertSame('customer-test', $invoice->billingCustomerId);
        self::assertSame('sub-test', $invoice->subscriptionId);
        self::assertSame('9007199254740993.000000000000000001', $invoice->toArray()['total_amount']);
        self::assertSame('0.000000000000000001', $invoice->toArray()['amount_due']);
        self::assertSame('https://example.test/private-pdf', $invoice->pdfUrl());
        self::assertSame('https://example.test/private-hosted', $invoice->hostedUrl());
        self::assertArrayNotHasKey('client_secret', $invoice->toArray());
        $copy = $invoice->toArray();
        $copy['unknown_empty']->changed = 'mutated copy';
        self::assertSame([], get_object_vars($invoice->toArray()['unknown_empty']));
        $json = json_encode($invoice, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('https://', $json);
        self::assertStringNotContainsString('private@example.test', $json);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', $json);
        ob_start();
        var_dump($invoice);
        $debug = ob_get_clean();
        self::assertStringNotContainsString('https://', $debug);
        self::assertStringNotContainsString('private@example.test', $debug);
        self::assertSame('/api/v1/billing/invoices/invoice-test', $http->requests[1]->getUri()->getPath());
        self::assertSame('ACTIVE', $this->subscription->fresh()->status);
        self::assertSame(2, DomainEvent::where('event_name', 'InvoiceObserved')->count());
        $event = DomainEvent::first();
        self::assertSame($this->customer->id, $event->resource_id);
        self::assertSame('invoice-test', $event->payload['remote_id']);
        self::assertStringNotContainsString('https://', json_encode($event->payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private@example.test', json_encode($event->payload, JSON_THROW_ON_ERROR));
        $this->expectException(LogicException::class);
        serialize($invoice);
    }

    public function test_draft_null_and_absent_document_values_remain_null(): void
    {
        $absent = self::invoice();
        unset($absent['pdf_url'], $absent['hosted_url']);
        $this->http([self::response(self::invoice()), self::response($absent)]);
        foreach ([$this->customer->findInvoice('invoice-test'), $this->subscription->findInvoice('invoice-test')] as $invoice) {
            self::assertNull($invoice->pdfUrl());
            self::assertNull($invoice->hostedUrl());
        }
        self::assertSame('DRAFT', $invoice->toArray()['status']);
        self::assertSame($this->subscription->id, DomainEvent::orderByDesc('id')->first()->resource_id);
    }

    #[DataProvider('foreignInvoices')]
    public function test_direct_invoice_rejects_foreign_or_mismatched_identity(array $override, bool $subscriptionScope): void
    {
        $http = $this->http([self::response(self::invoice($override))]);
        try {
            ($subscriptionScope ? $this->subscription : $this->customer)->findInvoice('invoice-test');
            self::fail('Foreign invoice was returned.');
        } catch (OwnershipException) {
            self::assertCount(2, $http->requests);
            self::assertSame(0, DomainEvent::count());
        }
    }

    public static function foreignInvoices(): array
    {
        return [
            [['id' => 'another-invoice'], false],
            [['billing_customer_id' => 'another-customer'], false],
            [['subscription_id' => 'another-subscription'], true],
            [['subscription_id' => null], true],
            [['org_id' => 'foreign-org'], false],
            [['organization_id' => 'foreign-org'], false],
            [['environment' => 'production'], false],
        ];
    }

    public function test_subscription_invoice_pages_keep_customer_subscription_and_filters(): void
    {
        $query = ['status' => 'FINALIZED', 'payment_status' => 'UNPAID', 'from_created_at' => '2026-11-01T00:00:00Z', 'to_created_at' => '2026-12-01T00:00:00Z', 'page_size' => 1];
        $http = $this->http([
            self::response(['items' => [self::invoice(['id' => 'invoice-one'])], 'page_after' => 'next-page']),
            self::response(['items' => [self::invoice(['id' => 'invoice-two'])], 'page_after' => null]),
        ]);
        $invoices = $this->subscription->invoices($query);
        self::assertSame(['invoice-one', 'invoice-two'], $invoices->pluck('id')->all());
        self::assertSame('9007199254740993.000000000000000001', $invoices[1]->toArray()['total_amount']);
        foreach (array_slice($http->requests, 1) as $index => $request) {
            parse_str($request->getUri()->getQuery(), $actual);
            self::assertSame('customer-test', $actual['billing_customer_id']);
            self::assertSame('sub-test', $actual['subscription_id']);
            foreach ($query as $key => $value) {
                self::assertSame((string) $value, $actual[$key]);
            }
            self::assertSame($index === 0 ? null : 'next-page', $actual['page'] ?? null);
        }
        self::assertSame(2, DomainEvent::count());
    }

    public function test_customer_and_billable_list_wrappers_include_only_customer_filter(): void
    {
        $http = $this->http([self::response(['items' => [self::invoice(['subscription_id' => null])]]), self::response(['items' => []])]);
        self::assertCount(1, $this->customer->invoices());
        self::assertCount(0, $this->team->invoices());
        foreach (array_slice($http->requests, 1) as $request) {
            parse_str($request->getUri()->getQuery(), $actual);
            self::assertSame(['billing_customer_id' => 'customer-test'], $actual);
        }
    }

    public function test_known_subscription_of_another_customer_is_rejected(): void
    {
        $other = Team::create(['id' => 'other-team']);
        $customer = app(OperationManager::class)->reserveCustomer($other);
        $customer->update(['remote_id' => 'other-customer']);
        Subscription::create([
            ...app(BillingContext::class)->attributes(), 'customer_id' => $customer->id,
            'remote_id' => 'foreign-sub', 'name' => 'default', 'status' => 'CANCELLED', 'snapshot' => [],
        ]);
        $this->http([self::response(self::invoice(['subscription_id' => 'foreign-sub']))]);
        try {
            $this->team->findInvoice('invoice-test');
            self::fail('A contradictory known subscription owner was accepted.');
        } catch (OwnershipException) {
            self::assertSame(0, DomainEvent::count());
            self::assertSame(2, Subscription::count());
        }
    }

    public function test_unknown_subscription_reference_is_observed_without_adoption(): void
    {
        $this->http([self::response(self::invoice(['subscription_id' => 'unknown-sub']))]);
        $invoice = $this->customer->findInvoice('invoice-test');
        self::assertSame('unknown-sub', $invoice->subscriptionId);
        self::assertSame(1, Subscription::count());
        self::assertSame(0, Subscription::where('remote_id', 'unknown-sub')->count());
        self::assertSame(1, DomainEvent::count());
    }

    public function test_terminated_subscription_invoice_access_is_independent_of_eligibility(): void
    {
        $this->subscription->update(['status' => 'CANCELLED']);
        self::assertFalse($this->subscription->valid());
        $this->http([self::response(self::invoice())]);
        self::assertSame('invoice-test', $this->subscription->findInvoice('invoice-test')->id);
        self::assertSame('CANCELLED', $this->subscription->fresh()->status);
        self::assertFalse($this->subscription->fresh()->valid());
    }

    #[DataProvider('filterOverrides')]
    public function test_scope_filter_override_is_rejected_before_http(array $query, bool $subscriptionScope): void
    {
        $http = $this->http([]);
        try {
            ($subscriptionScope ? $this->subscription : $this->customer)->invoices($query);
            self::fail('Ownership filters were overridden.');
        } catch (OwnershipException) {
            self::assertCount(0, $http->requests);
        }
    }

    public static function filterOverrides(): array
    {
        return [[['billing_customer_id' => 'foreign'], false], [['billing_customer_id' => null], false], [['subscription_id' => 'foreign'], true]];
    }

    public function test_foreign_later_page_never_returns_a_partial_list_or_publishes_an_event(): void
    {
        $this->http([
            self::response(['items' => [self::invoice(['id' => 'invoice-one'])], 'page_after' => 'next']),
            self::response(['items' => [self::invoice(['id' => 'invoice-two', 'billing_customer_id' => 'foreign'])]]),
        ]);
        try {
            $this->team->invoices();
            self::fail('Partial or foreign invoices were returned.');
        } catch (OwnershipException) {
            self::assertSame(0, DomainEvent::count());
        }
    }

    public function test_repeated_invoice_identity_is_rejected_without_events(): void
    {
        $this->http([
            self::response(['items' => [self::invoice()], 'page_after' => 'next']),
            self::response(['items' => [self::invoice()]]),
        ]);
        try {
            $this->customer->invoices();
            self::fail('Repeated identity was accepted.');
        } catch (EvidenceException) {
            self::assertSame(0, DomainEvent::count());
        }
    }

    public function test_customer_subscription_filter_is_retained_and_verified(): void
    {
        $http = $this->http([self::response(['items' => [self::invoice(['subscription_id' => 'other-sub'])]])]);
        try {
            $this->customer->invoices(['subscription_id' => 'sub-test']);
            self::fail('Invoice escaped its requested subscription filter.');
        } catch (OwnershipException) {
            parse_str($http->requests[1]->getUri()->getQuery(), $actual);
            self::assertSame('sub-test', $actual['subscription_id']);
            self::assertSame('customer-test', $actual['billing_customer_id']);
            self::assertSame(0, DomainEvent::count());
        }
    }

    public function test_repeated_cursor_is_rejected_without_events(): void
    {
        $http = $this->http([
            self::response(['items' => [self::invoice(['id' => 'invoice-one'])], 'page_after' => 'repeat']),
            self::response(['items' => [self::invoice(['id' => 'invoice-two'])], 'page_after' => 'repeat']),
        ]);
        try {
            $this->customer->invoices();
            self::fail('Repeated cursor was accepted.');
        } catch (EvidenceException) {
            self::assertCount(3, $http->requests);
            self::assertSame(0, DomainEvent::count());
        }
    }

    #[DataProvider('malformedInvoices')]
    public function test_malformed_safe_summary_or_document_values_are_rejected(array $override): void
    {
        $this->http([self::response(self::invoice($override))]);
        try {
            $this->customer->findInvoice('invoice-test');
            self::fail('Malformed invoice was returned.');
        } catch (EvidenceException) {
            self::assertSame(0, DomainEvent::count());
        }
    }

    public static function malformedInvoices(): array
    {
        return [[['status' => null]], [['payment_status' => []]], [['currency' => '']], [['pdf_url' => false]], [['hosted_url' => []]], [['subscription_id' => []]]];
    }

    public function test_line_pages_read_owned_parent_first_and_preserve_exact_amounts_and_filters(): void
    {
        $http = $this->http([
            self::response(self::invoice()), self::response(self::invoice()),
            self::response(['items' => [self::line(['id' => 'line-one'])], 'page_after' => 'next']),
            self::response(['items' => [self::line(['id' => 'line-two', 'amount' => '-12345678901234567890.000000000000000001'])]]),
        ]);
        $invoice = $this->subscription->findInvoice('invoice-test');
        $items = $invoice->lineItems(['page_size' => 1]);
        self::assertSame('1.000000000000000000001', $items[0]['amount']);
        self::assertSame('-12345678901234567890.000000000000000001', $items[1]['amount']);
        self::assertSame(2, $items[0]['quantity']);
        self::assertSame('0.000000000000000001', $items[0]['remaining_creditable_quantity']);
        self::assertSame('/api/v1/billing/invoices/invoice-test', $http->requests[2]->getUri()->getPath());
        foreach ([3, 4] as $index) {
            self::assertSame('/api/v1/billing/invoices/invoice-test/line_items', $http->requests[$index]->getUri()->getPath());
            parse_str($http->requests[$index]->getUri()->getQuery(), $actual);
            self::assertSame('1', $actual['page_size']);
            self::assertSame($index === 3 ? null : 'next', $actual['page'] ?? null);
            self::assertArrayNotHasKey('billing_customer_id', $actual);
        }
        self::assertSame(2, DomainEvent::count());
    }

    public function test_direct_line_read_checks_exact_line_identity_and_parent(): void
    {
        $http = $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response(self::line())]);
        $invoice = $this->team->findInvoice('invoice-test');
        self::assertSame('line-test', $invoice->lineItem('line-test')['id']);
        self::assertSame('/api/v1/billing/invoices/invoice-test/line_items/line-test', $http->requests[3]->getUri()->getPath());
    }

    public function test_foreign_later_line_page_has_no_partial_result_or_observation_event(): void
    {
        $this->http([
            self::response(self::invoice()), self::response(self::invoice()),
            self::response(['items' => [self::line()], 'page_after' => 'next']),
            self::response(['items' => [self::line(['id' => 'line-two', 'invoice_id' => 'foreign-invoice'])]]),
        ]);
        $invoice = $this->team->findInvoice('invoice-test');
        try {
            $invoice->lineItems();
            self::fail('Foreign page returned partial lines.');
        } catch (OwnershipException) {
            self::assertSame(1, DomainEvent::count());
        }
    }

    #[DataProvider('foreignLines')]
    public function test_foreign_line_is_rejected_after_fresh_parent_verification(array $override): void
    {
        $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response(self::line($override))]);
        $invoice = $this->team->findInvoice('invoice-test');
        try {
            $invoice->lineItem('line-test');
            self::fail('Foreign line was returned.');
        } catch (OwnershipException) {
            self::assertSame(1, DomainEvent::count());
        }
    }

    public static function foreignLines(): array
    {
        return [[['invoice_id' => 'foreign-invoice']], [['id' => 'foreign-line']], [['org_id' => 'foreign-org']], [['billing_customer_id' => 'foreign-customer']]];
    }

    public function test_changed_parent_ownership_stops_before_line_request(): void
    {
        $http = $this->http([self::response(self::invoice()), self::response(self::invoice(['billing_customer_id' => 'foreign']))]);
        $invoice = $this->team->findInvoice('invoice-test');
        try {
            $invoice->lineItems();
            self::fail('Unowned invoice lines were fetched.');
        } catch (OwnershipException) {
            self::assertCount(3, $http->requests);
            self::assertSame(1, DomainEvent::count());
        }
    }

    public function test_line_page_failure_does_not_emit_success_for_a_partial_read(): void
    {
        $this->http([
            self::response(self::invoice()), self::response(self::invoice()),
            self::response(['items' => [self::line()], 'page_after' => 'next']),
            FakeHttpClient::json(['code' => 'resource_not_found'], 404),
        ]);
        $invoice = $this->customer->findInvoice('invoice-test');
        try {
            $invoice->lineItems();
            self::fail('Partial lines were returned.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->httpStatus());
            self::assertSame(1, DomainEvent::count());
        }
    }

    public function test_provider_not_found_keeps_sdk_classification_and_does_not_mutate_access(): void
    {
        $this->http([FakeHttpClient::json(['code' => 'resource_not_found', 'message' => 'PRIVATE-PROFILE'], 404)]);
        try {
            $this->team->findInvoice('missing');
            self::fail('Missing invoice was returned.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->httpStatus());
            self::assertSame('resource_not_found', $exception->providerCode());
            self::assertStringNotContainsString('PRIVATE-PROFILE', (string) $exception);
            self::assertSame(0, DomainEvent::count());
            self::assertSame('ACTIVE', $this->subscription->fresh()->status);
        }
    }

    public function test_local_dirty_or_foreign_context_is_rejected_without_http(): void
    {
        $http = $this->http([]);
        $this->subscription->remote_id = 'foreign-sub';
        try {
            $this->subscription->invoices();
            self::fail('Dirty immutable identity was accepted.');
        } catch (OwnershipException) {
            self::assertCount(0, $http->requests);
        }
        $this->app->instance(BillingContext::class, new BillingContext('production', 'org-test'));
        try {
            $this->customer->findInvoice('invoice-test');
            self::fail('Stale local context was accepted.');
        } catch (OwnershipException) {
            self::assertCount(0, $http->requests);
        }
    }

    public function test_current_owner_is_rechecked_after_remote_io_before_outbox_commit(): void
    {
        $http = new InterleavingHttpClient([FakeHttpClient::login(), function (): ResponseInterface {
            self::assertSame(0, DB::transactionLevel());
            DB::table('airwallex_customers')->where('id', $this->customer->id)->update(['remote_id' => 'changed-owner']);

            return self::response(self::invoice());
        }]);
        $this->bindHttp($http);
        try {
            $this->customer->findInvoice('invoice-test');
            self::fail('Obsolete owner observation was returned.');
        } catch (OwnershipException) {
            self::assertSame(0, DomainEvent::count());
        }
    }

    public function test_retained_dto_cannot_fetch_lines_in_a_new_context(): void
    {
        $http = $this->http([self::response(self::invoice())]);
        $invoice = $this->team->findInvoice('invoice-test');
        $this->app->instance(BillingContext::class, new BillingContext('production', 'org-test'));
        try {
            $invoice->lineItems();
            self::fail('Old invoice escaped context isolation.');
        } catch (OwnershipException) {
            self::assertCount(2, $http->requests);
        }
    }

    public function test_configured_customer_and_subscription_tables_are_used_for_all_owned_reads(): void
    {
        Schema::rename('airwallex_customers', 'invoice_customers');
        Schema::rename('airwallex_subscriptions', 'invoice_subscriptions');
        config(['airwallex.models.customer' => InvoiceCustomer::class, 'airwallex.models.subscription' => InvoiceSubscription::class]);
        $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response(self::line())]);
        $subscription = InvoiceSubscription::findOrFail($this->subscription->id);
        $invoice = $subscription->findInvoice('invoice-test');
        self::assertSame('line-test', $invoice->lineItem('line-test')['id']);
        self::assertSame('invoice_subscriptions', DomainEvent::first()->resource_type);
    }

    public function test_webhook_observation_verifies_current_owner_without_persisting_events(): void
    {
        $this->http([self::response(self::invoice())]);
        $invoice = app(InvoiceManager::class)->observeForWebhook($this->customer, 'invoice-test');
        self::assertSame('invoice-test', $invoice->id);
        self::assertSame('sub-test', $invoice->subscriptionId);
        self::assertSame(0, DomainEvent::count());
    }

    public function test_commit_fence_can_reject_an_observation_without_persisting_events(): void
    {
        $this->http([self::response(self::invoice())]);
        try {
            app(CommitGuard::class)->during(
                static fn () => throw new EvidenceException('Receipt lease lost.'),
                fn () => $this->customer->findInvoice('invoice-test'),
            );
            self::fail('Stale receipt worker committed an event.');
        } catch (EvidenceException) {
            self::assertSame(0, DomainEvent::count());
        }
    }

    public function test_all_invoice_observation_events_roll_back_when_one_cannot_be_recorded(): void
    {
        $this->app->instance(Outbox::class, new class(app(BillingContext::class)) extends Outbox
        {
            private int $calls = 0;

            public function record(string $name, Model $resource, array $payload): DomainEvent
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('Synthetic storage failure.');
                }

                return parent::record($name, $resource, $payload);
            }
        });
        $this->http([self::response(['items' => [self::invoice(['id' => 'invoice-one']), self::invoice(['id' => 'invoice-two'])]])]);
        try {
            $this->customer->invoices();
            self::fail('Partial observation events committed.');
        } catch (RuntimeException) {
            self::assertSame(0, DomainEvent::count());
        }
    }

    public function test_unmapped_billable_and_unconfirmed_customer_have_no_invoice_reads(): void
    {
        $http = $this->http([]);
        $unmapped = Team::create(['id' => 'unmapped']);
        try {
            $unmapped->findInvoice('invoice-test');
            self::fail('Unmapped billable performed a remote read.');
        } catch (ConflictException) {
            self::assertCount(0, $http->requests);
        }
        $unconfirmed = app(OperationManager::class)->reserveCustomer($unmapped);
        $this->expectException(ConflictException::class);
        $unconfirmed->invoices();
    }

    private function http(array $responses): FakeHttpClient
    {
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        $this->bindHttp($http);

        return $http;
    }

    private function bindHttp(ClientInterface $http): void
    {
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);
    }

    /** Synthetic selected SDK v0.1.1 Invoice schema; no account observations. */
    private static function invoice(array $override = []): array
    {
        return array_replace([
            'id' => 'invoice-test', 'billing_customer_id' => 'customer-test', 'subscription_id' => 'sub-test',
            'status' => 'DRAFT', 'payment_status' => 'UNPAID', 'currency' => 'USD',
            'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z',
            'amount_due' => '0.000000000000000001', 'total_amount' => '9007199254740993.000000000000000001', 'total_tax_amount' => '0.000',
            'applied_discounts' => [], 'total_discount_amounts' => [], 'automatic_tax_status' => 'DISABLED',
            'enable_automatic_tax' => false, 'paid_out_of_band' => false, 'pdf_url' => null, 'hosted_url' => null,
            'billing_customer' => ['name' => 'Synthetic customer', 'email' => 'private@example.test'],
            'client_secret' => 'SYNTHETIC-SECRET', 'unknown_empty' => new \stdClass,
        ], $override);
    }

    private static function line(array $override = []): array
    {
        return array_replace([
            'id' => 'line-test', 'invoice_id' => 'invoice-test', 'currency' => 'USD',
            'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z',
            'amount' => '1.000000000000000000001', 'remaining_creditable_quantity' => '0.000000000000000001',
            'quantity' => 2, 'applied_discounts' => [], 'discount_amounts' => [],
        ], $override);
    }

    private static function response(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $json = preg_replace('/("(?:amount_due|total_amount|total_tax_amount|amount|remaining_creditable_quantity)":)"(-?[0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }
}

class InvoiceCustomer extends Customer
{
    protected $table = 'invoice_customers';
}

class InvoiceSubscription extends Subscription
{
    protected $table = 'invoice_subscriptions';
}
