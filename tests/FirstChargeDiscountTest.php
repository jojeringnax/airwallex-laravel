<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Services\CommitGuard;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class FirstChargeDiscountTest extends TestCase
{
    private Subscription $subscription;

    private const BOUNDARY = '2026-12-01T00:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $team = Team::create(['id' => 'first-charge-team']);
        app(OperationManager::class)->reserveCustomer($team)->update(['remote_id' => 'customer-test']);
        $this->http($this->observation($this->remote()));
        $this->subscription = $team->importSubscription('membership', 'sub-test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_released_sdk_has_a_first_charge_coupon_prerequisite_without_a_mutation(): void
    {
        if ($this->available()) {
            self::markTestSkipped('Released SDK profile only.');
        }
        $http = $this->http($this->observation($this->remote()));
        try {
            $this->apply();
            self::fail('Unavailable Coupon support was accepted.');
        } catch (ConfigurationException) {
            self::assertSame(0, Operation::count());
            self::assertSame([], $this->writes($http));
        }
    }

    public function test_fixed_once_discount_preserves_original_identity_and_verifies_the_paid_remainder_invoice(): void
    {
        $this->extension();
        $http = $this->http($this->applyPlan());
        $this->apply();
        $operation = Operation::where('action_key', 'partial')->sole();
        self::assertSame('succeeded', $operation->status);
        self::assertSame(self::BOUNDARY, $operation->intent['discount_boundary']);
        self::assertSame('15', $operation->intent['coupon_terms']['amount_off']);
        self::assertSame('discount-partial', $operation->observation['discount_proof']['id']);
        self::assertSame('NONE', json_decode((string) $this->writes($http)[0]->getBody(), true)['default_proration_mode']);
        $this->http([]);
        $this->apply();
        Carbon::setTestNow('2026-12-01T00:00:01Z');
        $invoice = $this->invoice();
        $http = $this->http([self::response($invoice), self::response($invoice), Billing::page([$this->line()]), self::response($invoice)]);
        $verified = $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
        self::assertSame('15.00', $verified->toArray()['total_amount']);
        self::assertSame([], $this->writes($http));
        self::assertSame(1, Operation::count());
    }

    public function test_explicit_removal_requires_the_original_still_observed_discount(): void
    {
        $this->extension();
        $this->http($this->applyPlan());
        $this->apply();
        $removed = $this->remote([], '2026-11-10T12:02:00Z');
        $http = $this->http([...$this->observation($this->remote([$this->discount()], '2026-11-10T12:01:00Z')), self::response(Billing::source()), self::response($removed), ...$this->observation($removed)]);
        $this->subscription->removeFirstChargeDiscount('remove-partial', 'partial');
        self::assertSame('succeeded', Operation::where('action_key', 'remove-partial')->sole()->status);
        self::assertSame([], json_decode((string) $this->writes($http)[0]->getBody(), true)['discounts']);
    }

    public function test_unknown_original_discount_response_is_held_without_replay_even_after_renewal(): void
    {
        $this->extension();
        $plan = $this->applyPlan();
        $plan[6] = new RuntimeException('Synthetic lost original response.');
        $http = $this->http($plan);
        try {
            $this->apply();
        } catch (AmbiguousOperationException) {
        }
        $operation = Operation::where('action_key', 'partial')->sole();
        self::assertSame('ambiguous', $operation->status);
        self::assertNull($operation->observation);
        Carbon::setTestNow('2026-12-02T00:00:00Z');
        $http = $this->http($this->observation($this->remote([$this->discount()], '2026-12-02T00:00:00Z')));
        try {
            $this->apply();
            self::fail('A later matching Coupon replaced missing original Discount proof.');
        } catch (AmbiguousOperationException) {
            self::assertNotNull($operation->fresh()->lock_key);
            self::assertSame([], $this->writes($http));
        }
    }

    public function test_attributed_invoice_recovers_saved_response_proof_after_consumption_on_a_historical_generation(): void
    {
        $this->extension();
        $operation = $this->ambiguousWithProof();
        $this->subscription->update(['status' => 'CANCELLED']);
        SubscriptionSlot::where('subscription_id', $this->subscription->id)->delete();
        $replacement = Subscription::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'customer_id' => $this->subscription->customer_id, 'remote_id' => 'sub-replacement', 'name' => 'membership', 'status' => 'ACTIVE', 'cancel_at_period_end' => false, 'snapshot' => []]);
        SubscriptionSlot::create(['customer_id' => $replacement->customer_id, 'name' => 'membership', 'subscription_id' => $replacement->id]);
        $invoice = $this->invoice();
        $http = $this->http([self::response($invoice), self::response($invoice), Billing::page([$this->line()]), self::response($invoice)]);
        $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertNull($operation->fresh()->lock_key);
        self::assertSame('CANCELLED', $this->subscription->fresh()->status);
        self::assertSame($replacement->id, SubscriptionSlot::sole()->subscription_id);
        self::assertSame([], $this->writes($http));
    }

    public function test_invoice_cannot_substitute_for_missing_original_response_identity(): void
    {
        $this->extension();
        $plan = $this->applyPlan();
        $plan[6] = new RuntimeException('Synthetic lost response.');
        $this->http($plan);
        try {
            $this->apply();
        } catch (AmbiguousOperationException) {
        }
        $http = $this->http([]);
        try {
            $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
            self::fail('An Invoice fabricated the missing original Discount identity.');
        } catch (EvidenceException) {
            self::assertCount(0, $http->requests);
            self::assertNotNull(Operation::where('action_key', 'partial')->sole()->lock_key);
        }
    }

    public function test_expired_worker_does_not_complete_the_original_discount_from_invoice_reads(): void
    {
        $this->extension();
        $operation = $this->ambiguousWithProof();
        $expired = false;
        $invoice = $this->invoice();
        $http = new InterleavingHttpClient([FakeHttpClient::login(), self::response($invoice), self::response($invoice), Billing::page([$this->line()]), function () use (&$expired, $invoice) {
            $expired = true;

            return self::response($invoice);
        }]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);
        try {
            app(CommitGuard::class)->during(static function () use (&$expired): void {
                if ($expired) {
                    throw new RuntimeException('Synthetic expired lease.');
                }
            }, fn () => $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00'));
            self::fail('An expired worker confirmed Invoice proof.');
        } catch (RuntimeException) {
            self::assertSame('ambiguous', $operation->fresh()->status);
            self::assertNotNull($operation->fresh()->lock_key);
        }
    }

    public function test_offboarding_during_invoice_paging_retains_the_original_hold(): void
    {
        $this->extension();
        $operation = $this->ambiguousWithProof();
        $invoice = $this->invoice();
        $http = new InterleavingHttpClient([FakeHttpClient::login(), self::response($invoice), self::response($invoice), function () use ($operation) {
            DB::table('airwallex_customers')->where('id', $operation->customer_id)->update(['remote_id' => 'another-customer']);

            return Billing::page([$this->line()]);
        }]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);
        try {
            $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
            self::fail('A changed local owner completed the original Discount.');
        } catch (OwnershipException) {
            self::assertSame('ambiguous', $operation->fresh()->status);
            self::assertNotNull($operation->fresh()->lock_key);
        }
    }

    #[DataProvider('invalidCoupons')]
    public function test_unapproved_coupon_terms_never_reach_a_subscription_write(array $changes): void
    {
        $this->extension();
        $http = $this->http([...$this->observation($this->remote()), self::response($this->coupon($changes))]);
        try {
            $this->apply();
            self::fail('Unapproved first-charge terms were sent.');
        } catch (EvidenceException|OwnershipException) {
            self::assertSame(0, Operation::count());
            self::assertSame([], $this->writes($http));
        }
    }

    public static function invalidCoupons(): iterable
    {
        yield 'foreign' => [['organization_id' => 'foreign']];
        yield 'currency' => [['currency' => 'EUR']];
        yield 'amount' => [['amount_off' => '14.99']];
        yield 'percentage' => [['discount_model' => 'PERCENTAGE', 'percentage_off' => 50]];
        yield 'repeating' => [['duration_type' => 'INDEFINITELY']];
        yield 'expired' => [['expires_at' => '2026-11-10T11:59:59Z']];
    }

    #[DataProvider('invalidInvoices')]
    public function test_missing_wrong_or_competing_invoice_attribution_is_never_a_remainder_proof(array $changes): void
    {
        $this->extension();
        $this->http($this->applyPlan());
        $this->apply();
        $http = $this->http([self::response($this->invoice($changes))]);
        try {
            $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
            self::fail('A matching-looking Invoice substituted for original Discount attribution.');
        } catch (EvidenceException|OwnershipException) {
            self::assertSame([], $this->writes($http));
        }
    }

    public static function invalidInvoices(): iterable
    {
        yield 'same total foreign Discount' => [['applied_discounts' => []]];
        yield 'wrong amount attribution' => [['total_discount_amounts' => [['applied_discount_id' => 'discount-partial', 'amount' => '14.99']]]];
        yield 'foreign customer' => [['billing_customer_id' => 'foreign']];
        yield 'wrong total' => [['total_amount' => '14.99']];
        yield 'voided' => [['status' => 'VOIDED']];
        yield 'draft' => [['status' => 'DRAFT']];
        yield 'foreign collector' => [['linked_payment_account_id' => 'foreign']];
    }

    public function test_an_intervening_invoice_service_period_does_not_prove_the_intended_boundary(): void
    {
        $this->extension();
        $this->http($this->applyPlan());
        $this->apply();
        $invoice = $this->invoice();
        $http = $this->http([self::response($invoice), self::response($invoice), Billing::page([$this->line(['period_starts_at' => '2026-11-20T00:00:00Z'])])]);
        $this->expectException(EvidenceException::class);
        $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
    }

    public function test_a_changed_final_invoice_read_cannot_release_a_stale_amount_claim(): void
    {
        $this->extension();
        $this->http($this->applyPlan());
        $this->apply();
        $invoice = $this->invoice();
        $this->http([self::response($invoice), self::response($invoice), Billing::page([$this->line()]), self::response($this->invoice(['total_amount' => '16.00']))]);
        $this->expectException(EvidenceException::class);
        $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
    }

    public function test_a_voided_final_invoice_read_cannot_confirm_a_chargeable_remainder(): void
    {
        $this->extension();
        $this->http($this->applyPlan());
        $this->apply();
        $invoice = $this->invoice();
        $this->http([self::response($invoice), self::response($invoice), Billing::page([$this->line()]), self::response($this->invoice(['status' => 'VOIDED']))]);
        $this->expectException(EvidenceException::class);
        $this->subscription->firstChargeDiscountInvoice('partial', 'invoice-partial', '15.00');
    }

    private function apply(): Subscription
    {
        return $this->subscription->applyFirstChargeDiscount('partial', 'coupon-partial', '15.00', 'USD', self::BOUNDARY);
    }

    private function ambiguousWithProof(): Operation
    {
        $plan = $this->applyPlan();
        $plan[7] = new RuntimeException('Synthetic canonical read failure after original Discount capture.');
        $this->http($plan);
        try {
            $this->apply();
        } catch (AmbiguousOperationException) {
        }
        $operation = Operation::where('action_key', 'partial')->sole();
        self::assertSame('ambiguous', $operation->status);
        self::assertSame('discount-partial', $operation->observation['discount_proof']['id']);

        return $operation;
    }

    private function coupon(array $changes = []): array
    {
        return ['id' => 'coupon-partial', 'active' => true, 'discount_model' => 'FLAT', 'amount_off' => '15.00', 'currency' => 'USD', 'duration_type' => 'ONCE', ...$changes];
    }

    private function discount(): array
    {
        return ['id' => 'discount-partial', 'applied_to' => 'SUBSCRIPTION', 'applied_to_id' => 'sub-test', 'billing_customer_id' => 'customer-test', 'source' => 'COUPON', 'source_id' => 'coupon-partial', 'discount_model' => 'FLAT', 'amount_off' => '15.00', 'currency' => 'USD', 'duration_type' => 'ONCE', 'created_at' => '2026-11-10T12:01:00Z', 'starts_at' => '2026-11-10T12:01:00Z'];
    }

    private function remote(array $discounts = [], string $updated = '2026-11-10T12:00:00Z'): array
    {
        return Billing::subscription(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'applied_discounts' => $discounts, 'current_period_starts_at' => '2026-11-01T00:00:00Z', 'current_period_ends_at' => self::BOUNDARY, 'updated_at' => $updated]);
    }

    private function invoice(array $changes = []): array
    {
        return ['id' => 'invoice-partial', 'billing_customer_id' => 'customer-test', 'subscription_id' => 'sub-test', 'status' => 'FINALIZED', 'payment_status' => 'UNPAID', 'currency' => 'USD', 'created_at' => self::BOUNDARY, 'updated_at' => self::BOUNDARY, 'total_amount' => '15.00', 'amount_due' => '15.00', 'applied_discounts' => [$this->discount()], 'total_discount_amounts' => [['applied_discount_id' => 'discount-partial', 'amount' => '15.00']], 'total_tax_amount' => '0', 'automatic_tax_status' => 'DISABLED', 'enable_automatic_tax' => false, 'paid_out_of_band' => false, ...$changes];
    }

    private function line(array $changes = []): array
    {
        return ['id' => 'invoice-line', 'invoice_id' => 'invoice-partial', 'currency' => 'USD', 'amount' => '30.00', 'applied_discounts' => [], 'discount_amounts' => [], 'created_at' => self::BOUNDARY, 'updated_at' => self::BOUNDARY, 'period_starts_at' => self::BOUNDARY, 'period_ends_at' => '2027-01-01T00:00:00Z', ...$changes];
    }

    private function applyPlan(): array
    {
        $discounted = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');

        return [...$this->observation($this->remote()), self::response($this->coupon()), self::response($this->coupon()), self::response(Billing::source()), self::response($discounted), ...$this->observation($discounted)];
    }

    private function observation(array $remote): array
    {
        return [self::response($remote), Billing::page([Billing::item(['quantity' => '1'])]), self::response($remote)];
    }

    private function http(array $responses): FakeHttpClient
    {
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    private function writes(FakeHttpClient $http): array
    {
        return array_values(array_filter($http->requests, static fn ($request) => str_ends_with($request->getUri()->getPath(), '/update')));
    }

    private static function response(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $json = preg_replace('/("(?:amount_off|amount|amount_due|total_amount|total_tax_amount)":)"([0-9]+(?:\\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }

    private function available(): bool
    {
        return method_exists(app(Client::class)->billing(), 'coupons');
    }

    private function extension(): void
    {
        if (! $this->available()) {
            self::markTestSkipped('Pinned SDK Coupon extension profile required.');
        }
    }
}
