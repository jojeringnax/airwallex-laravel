<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\FreePeriodEvidence;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Support\CanonicalJson;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

class SubscriptionFreePeriodTest extends TestCase
{
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $team = Team::create(['id' => 'team']);
        app(OperationManager::class)->reserveCustomer($team)->update(['remote_id' => 'customer-test']);
        $this->http($this->observation($this->remote()));
        $this->subscription = $team->importSubscription('membership', 'sub-test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_released_baseline_has_an_explicit_coupon_capability_prerequisite(): void
    {
        if (method_exists(app(Client::class)->billing(), 'coupons')) {
            self::markTestSkipped('This profile explicitly selects the unreleased Coupon resource.');
        }
        $this->expectException(ConfigurationException::class);
        app(FreePeriodEvidence::class)->coupon('coupon-test');
    }

    public function test_apply_and_explicit_remove_preserve_original_discount_identity_and_financial_commands(): void
    {
        $this->requireCoupons();
        $discounted = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
        $http = $this->http($this->applyPlan($discounted));
        $result = $this->subscription->applyFreePeriod('free', 'coupon-test');
        $operation = Operation::where('action_key', 'free')->sole();
        self::assertSame('succeeded', $operation->status);
        self::assertSame('discount-test', $operation->observation['discount_proof']['id']);
        self::assertSame('2026-11-11T12:01:00Z', $operation->observation['discount_proof']['ends_at']);
        self::assertSame(['discounts' => [['type' => 'COUPON', 'coupon' => ['id' => 'coupon-test']]], 'default_proration_mode' => 'NONE', 'billing_action' => 'DEFER_CHARGE_AND_KEEP_CYCLE', 'request_id' => $operation->request_id], json_decode((string) $http->requests[7]->getBody(), true));
        self::assertTrue($result->active());
        $this->http([]);
        $result->applyFreePeriod('free', 'coupon-test');
        $removed = $this->remote([], '2026-11-10T12:02:00Z');
        $http = $this->http([...$this->observation($discounted), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($removed), ...$this->observation($removed)]);
        $result->removeFreePeriod('remove', 'free');
        $remove = Operation::where('action_key', 'remove')->sole();
        self::assertSame('succeeded', $remove->status);
        self::assertNotSame($operation->request_id, $remove->request_id);
        self::assertSame([], json_decode((string) $http->requests[5]->getBody(), true)['discounts']);
    }

    #[DataProvider('invalidCoupons')]
    public function test_foreign_inactive_nonfree_or_unbounded_coupon_never_reaches_subscription_write(array $changes): void
    {
        $this->requireCoupons();
        $http = $this->http([...$this->observation($this->remote()), FakeHttpClient::json($this->coupon($changes))]);
        try {
            $this->subscription->applyFreePeriod('invalid', 'coupon-test');
            self::fail('An unsupported Coupon became a financial command.');
        } catch (EvidenceException|OwnershipException) {
            self::assertSame(0, Operation::count());
            self::assertCount(5, $http->requests);
        }
    }

    public static function invalidCoupons(): array
    {
        return [[['organization_id' => 'foreign']], [['active' => false]], [['percentage_off' => 99]], [['duration_type' => 'INDEFINITELY']], [['duration' => null]], [['expires_at' => '2026-11-10T11:59:59Z']]];
    }

    public function test_contradictory_discount_keeps_original_hold_and_recovers_only_its_response_identity(): void
    {
        $this->requireCoupons();
        $discounted = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
        $different = $this->remote([$this->discount(['id' => 'other-discount'])], '2026-11-10T12:02:00Z');
        $this->http([...array_slice($this->applyPlan($discounted), 0, -3), ...$this->observation($different)]);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
            self::fail('A different Discount fulfilled the original response.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::where('action_key', 'free')->sole()->status);
            self::assertNotNull(Operation::where('action_key', 'free')->sole()->lock_key);
        }
        $http = $this->http($this->observation($this->remote([$this->discount()], '2026-11-10T12:03:00Z')));
        $this->subscription->applyFreePeriod('free', 'coupon-test');
        self::assertSame('succeeded', Operation::where('action_key', 'free')->sole()->status);
        self::assertCount(4, $http->requests);
    }

    public function test_timeout_does_not_replay_when_the_coupon_later_expires(): void
    {
        $this->requireCoupons();
        $plan = $this->applyPlan($this->remote([$this->discount()], '2026-11-10T12:01:00Z'));
        $http = $this->http([...array_slice($plan, 0, 6), new RuntimeException('synthetic timeout')]);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::where('action_key', 'free')->sole()->status);
        }
        self::assertCount(8, $http->requests);
        Carbon::setTestNow('2026-11-12T12:00:00Z');
        $http = $this->http($this->observation($this->remote([], '2026-11-12T12:00:00Z')));
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } finally {
            self::assertCount(4, $http->requests);
            self::assertSame('ambiguous', Operation::where('action_key', 'free')->sole()->status);
        }
    }

    public function test_response_proof_is_encrypted_hidden_and_immutable_without_rewriting_intent(): void
    {
        $this->requireCoupons();
        $discounted = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
        $this->http($this->applyPlan($discounted));
        $this->subscription->applyFreePeriod('free', 'coupon-test');
        $operation = Operation::where('action_key', 'free')->sole();
        self::assertArrayNotHasKey('discount_proof', $operation->intent);
        self::assertArrayNotHasKey('observation', $operation->toArray());
        self::assertStringNotContainsString('discount-test', DB::table('airwallex_operations')->where('id', $operation->id)->value('observation'));
        $operation->observation = ['discount_proof' => ['id' => 'replacement']];
        $this->expectException(OwnershipException::class);
        $operation->save();
    }

    public function test_changed_coupon_terms_block_the_prepared_command_before_dispatch(): void
    {
        $this->requireCoupons();
        $http = $this->http([...$this->observation($this->remote()), FakeHttpClient::json($this->coupon()), FakeHttpClient::json($this->coupon(['duration' => ['period' => 2, 'period_unit' => 'DAY']]))]);
        $this->expectException(EvidenceException::class);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } finally {
            self::assertCount(6, $http->requests);
            self::assertSame('failed', Operation::where('action_key', 'free')->sole()->status);
        }
    }

    #[DataProvider('invalidDiscounts')]
    public function test_unowned_or_unbounded_response_proof_keeps_the_original_command_ambiguous(array $changes): void
    {
        $this->requireCoupons();
        $result = $this->remote([$this->discount($changes)], '2026-11-10T12:01:00Z');
        $http = $this->http(array_slice($this->applyPlan($result), 0, -3));
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } finally {
            $operation = Operation::where('action_key', 'free')->sole();
            self::assertSame('ambiguous', $operation->status);
            self::assertNotNull($operation->lock_key);
            self::assertNull($operation->observation);
            self::assertCount(8, $http->requests);
        }
    }

    public static function invalidDiscounts(): array
    {
        return [[['starts_at' => null]], [['starts_at' => '2026-11-12T12:00:00Z']], [['billing_customer_id' => 'foreign']], [['applied_to_id' => 'foreign']], [['source_id' => 'foreign']], [['applied_to' => 'ITEM']], [['ends_at' => null]], [['ends_at' => '2026-11-10T12:00:00Z']], [['duration' => ['period' => 2, 'period_unit' => 'DAY']]]];
    }

    public function test_conflicting_discount_reads_cannot_fulfil_the_original_response(): void
    {
        $this->requireCoupons();
        $result = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
        $this->http([...array_slice($this->applyPlan($result), 0, -3), FakeHttpClient::json($result), Billing::page([]), FakeHttpClient::json([...$result, 'applied_discounts' => []])]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } finally {
            self::assertSame('ambiguous', Operation::where('action_key', 'free')->sole()->status);
            self::assertSame([], $this->subscription->fresh()->snapshot['applied_discounts']);
        }
    }

    #[DataProvider('invalidCollections')]
    public function test_unverified_collection_semantics_never_prepare_a_discount_write(mixed $collection): void
    {
        $this->requireCoupons();
        $remote = [...$this->remote([], '2026-11-10T12:01:00Z'), 'collection_method' => $collection];
        $http = $this->http($this->observation($remote));
        $this->expectException(EvidenceException::class);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } finally {
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    public static function invalidCollections(): array
    {
        return [[null], ['UNKNOWN'], [['AUTO_CHARGE']]];
    }

    public function test_changed_effective_start_cannot_confirm_the_original_response_bounds(): void
    {
        $this->requireCoupons();
        $result = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
        $changed = $this->remote([$this->discount(['starts_at' => '2026-11-10T12:02:00Z'])], '2026-11-10T12:02:00Z');
        $this->http([...array_slice($this->applyPlan($result), 0, -3), ...$this->observation($changed)]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->subscription->applyFreePeriod('free', 'coupon-test');
        } finally {
            self::assertSame('ambiguous', Operation::where('action_key', 'free')->sole()->status);
        }
    }

    public function test_original_confirmed_discount_is_required_for_removal(): void
    {
        $this->requireCoupons();
        $result = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
        $this->http($this->applyPlan($result));
        $this->subscription->applyFreePeriod('free', 'coupon-test');
        $other = $this->remote([$this->discount(['id' => 'other'])], '2026-11-10T12:02:00Z');
        $http = $this->http($this->observation($other));
        $this->expectException(EvidenceException::class);
        try {
            $this->subscription->removeFreePeriod('remove', 'free');
        } finally {
            self::assertSame(1, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    public function test_malformed_observed_duration_is_redacted_from_removal_exception_arguments(): void
    {
        $this->requireCoupons();
        $prior = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $result = $this->remote([$this->discount()], '2026-11-10T12:01:00Z');
            $this->http($this->applyPlan($result));
            $this->subscription->applyFreePeriod('free', 'coupon-test');
            $malformed = $this->remote([$this->discount(['duration' => ['period' => 0, 'profile' => 'SYNTHETIC-PRIVATE-DURATION-CANARY']])], '2026-11-10T12:02:00Z');
            $this->http($this->observation($malformed));
            try {
                $this->subscription->removeFreePeriod('remove', 'free');
                self::fail('Malformed observed duration became a removal command.');
            } catch (EvidenceException $error) {
                self::assertStringNotContainsString('SYNTHETIC-PRIVATE-DURATION-CANARY', $error->getTraceAsString());
                self::assertInstanceOf(\SensitiveParameterValue::class, $error->getTrace()[0]['args'][0]);
                self::assertSame(1, Operation::count());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $prior);
        }
    }

    #[DataProvider('legacyDiscountInputs')]
    public function test_legacy_prepared_item_commands_cannot_bypass_discount_proof_guards(array $items, array $options): void
    {
        $customer = ModelRegistry::query('customer')->findOrFail($this->subscription->customer_id);
        $operation = app(OperationManager::class)->prepare($customer, 'legacy-discount', 'subscription.items', ['items' => $items, 'options' => $options], [
            'payload' => [...$options, 'items' => $items], 'remote_id' => $this->subscription->remote_id,
            'fingerprint' => hash('sha256', CanonicalJson::encode([$this->subscription->snapshot, []])), 'prior_item_ids' => [],
        ], $this->subscription);
        $original = [$operation->request_id, $operation->intent_hash, $operation->intent];
        $http = $this->http([]);
        $this->expectException(EvidenceException::class);
        try {
            $this->subscription->updateItems('legacy-discount', $items, $options);
        } finally {
            $operation->refresh();
            self::assertSame('failed', $operation->status);
            self::assertSame('preflight_rejected', $operation->error_code);
            self::assertNull($operation->lock_key);
            self::assertSame($original, [$operation->request_id, $operation->intent_hash, $operation->intent]);
            self::assertCount(0, $http->requests);
        }
    }

    public static function legacyDiscountInputs(): array
    {
        $discount = ['type' => 'COUPON', 'coupon' => ['id' => 'coupon-test']];
        $item = ['price_id' => 'price-new', 'quantity' => 1];
        $options = ['default_proration_mode' => 'NONE'];

        return [[[$item], [...$options, 'discounts' => [$discount]]], [[[...$item, 'discounts' => [$discount]]], $options]];
    }

    private function requireCoupons(): void
    {
        if (! method_exists(app(Client::class)->billing(), 'coupons')) {
            self::markTestSkipped('Requires the explicitly selected exact SDK Coupon extension archive.');
        }
    }

    private function remote(array $discounts = [], string $updated = '2026-11-10T12:00:00Z'): array
    {
        return Billing::subscription(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'applied_discounts' => $discounts, 'updated_at' => $updated]);
    }

    private function coupon(array $changes = []): array
    {
        return ['id' => 'coupon-test', 'active' => true, 'discount_model' => 'PERCENTAGE', 'percentage_off' => 100, 'duration_type' => 'CUSTOM', 'duration' => ['period' => 1, 'period_unit' => 'DAY'], ...$changes];
    }

    private function discount(array $changes = []): array
    {
        return ['id' => 'discount-test', 'applied_to' => 'SUBSCRIPTION', 'applied_to_id' => 'sub-test', 'billing_customer_id' => 'customer-test', 'source' => 'COUPON', 'source_id' => 'coupon-test', 'discount_model' => 'PERCENTAGE', 'percentage_off' => 100, 'duration_type' => 'CUSTOM', 'duration' => ['period' => 1, 'period_unit' => 'DAY'], 'created_at' => '2026-11-10T12:01:00Z', 'starts_at' => '2026-11-10T12:01:00Z', 'ends_at' => '2026-11-11T12:01:00Z', ...$changes];
    }

    private function observation(array $remote): array
    {
        return [FakeHttpClient::json($remote), Billing::page([]), FakeHttpClient::json($remote)];
    }

    private function applyPlan(array $result): array
    {
        return [...$this->observation($this->remote()), FakeHttpClient::json($this->coupon()), FakeHttpClient::json($this->coupon()), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($result), ...$this->observation($result)];
    }

    private function http(array $responses): FakeHttpClient
    {
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }
}
