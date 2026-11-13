<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
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

    #[DataProvider('generalCouponShapes')]
    public function test_general_coupon_shapes_preserve_original_proof_through_create_or_update_and_explicit_removal(string $model, string $duration, bool $create, string $status = 'ACTIVE'): void
    {
        $terms = ['discount_model' => $model, 'duration_type' => $duration, 'duration' => $duration === 'CUSTOM' ? ['period' => 2, 'period_unit' => 'MONTH'] : null,
            'percentage_off' => $model === 'PERCENTAGE' ? 25 : null, 'amount_off' => $model === 'FLAT' ? '5.50' : null, 'currency' => $model === 'FLAT' ? 'USD' : null];
        $coupon = $this->coupon($terms);
        $id = $create ? 'sub-created-coupon' : 'sub-test';
        $discount = $this->discount([...$terms, 'applied_to_id' => $id, 'ends_at' => $duration === 'CUSTOM' ? '2027-01-10T12:01:00Z' : null]);
        $remote = $create ? Billing::subscription(['id' => $id, 'applied_discounts' => [$discount], 'status' => $status]) : $this->remote([$discount], '2026-11-10T12:01:00Z');
        $priceReads = $model === 'FLAT' ? [FakeHttpClient::json(['id' => 'price-test', 'currency' => 'USD'])] : [];
        $plan = $create
            ? [FakeHttpClient::json($coupon), ...$priceReads, FakeHttpClient::json($coupon), ...$priceReads, FakeHttpClient::json($remote), ...$this->observation($remote)]
            : [...$this->observation($this->remote()), FakeHttpClient::json($coupon), FakeHttpClient::json($coupon), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($remote), ...$this->observation($remote)];
        $http = $this->http($plan);
        $team = Team::firstOrFail();
        if (! $create && $duration === 'ONCE') {
            try {
                $this->subscription->applyCoupon('general-coupon', 'coupon-test');
                self::fail('ONCE on an existing ACTIVE Subscription was assumed supported.');
            } catch (EvidenceException) {
                self::assertSame(0, Operation::count());
                self::assertCount(5, $http->requests);
            }

            return;
        }
        $result = $create
            ? $team->newSubscription('coupon-created', 'price-test')->withCoupon('coupon-test')->create('general-coupon', ['collection_method' => 'OUT_OF_BAND'])
            : $this->subscription->applyCoupon('general-coupon', 'coupon-test');
        $operation = Operation::where('action_key', 'general-coupon')->sole();
        self::assertSame('succeeded', $operation->status);
        self::assertSame($model, $operation->intent['coupon_terms']['discount_model']);
        self::assertSame($duration, $operation->intent['coupon_terms']['duration_type']);
        self::assertSame('discount-test', $operation->observation['discount_proof']['id']);
        self::assertStringNotContainsString('discount-test', $operation->getRawOriginal('observation'));
        $this->http([]);
        $create ? $team->newSubscription('coupon-created', 'price-test')->withCoupon('coupon-test')->create('general-coupon', ['collection_method' => 'OUT_OF_BAND']) : $result->applyCoupon('general-coupon', 'coupon-test');
        $removed = [...$remote, 'applied_discounts' => [], 'updated_at' => '2026-11-10T12:02:00Z'];
        $this->http([...$this->observation($remote), ...($create ? [] : [FakeHttpClient::json(Billing::source())]), FakeHttpClient::json($removed), ...$this->observation($removed)]);
        $result->removeCoupon('remove-general', 'general-coupon');
        self::assertSame('succeeded', Operation::where('action_key', 'remove-general')->sole()->status);
    }

    public static function generalCouponShapes(): iterable
    {
        foreach (['PERCENTAGE', 'FLAT'] as $model) {
            foreach (['ONCE', 'CUSTOM', 'INDEFINITELY'] as $duration) {
                foreach ([true, false] as $create) {
                    yield $model.'-'.$duration.'-'.($create ? 'create' : 'ACTIVE') => [$model, $duration, $create];
                }
            }
        }
        yield 'signup-PENDING' => ['PERCENTAGE', 'ONCE', true, 'PENDING'];
        yield 'signup-IN_TRIAL' => ['PERCENTAGE', 'ONCE', true, 'IN_TRIAL'];
    }

    #[DataProvider('changedCreationCoupons')]
    public function test_general_creation_coupon_rechecks_frozen_terms_before_write(array $changes, bool $hosted): void
    {
        $coupon = $this->coupon(['percentage_off' => 25]);
        $http = $this->http([FakeHttpClient::json($coupon), FakeHttpClient::json([...$coupon, ...$changes])]);
        try {
            $method = $hosted ? 'checkout' : 'create';
            $options = $hosted ? ['success_url' => 'https://app.example.test/success', 'back_url' => 'https://app.example.test/back'] : ['collection_method' => 'OUT_OF_BAND'];
            Team::firstOrFail()->newSubscription('coupon-created', 'price-test')->withCoupon('coupon-test')->{$method}('general-coupon', $options);
            self::fail('A changed Coupon reached creation.');
        } catch (EvidenceException) {
            self::assertSame('failed', Operation::sole()->status);
            self::assertCount(3, $http->requests);
        }
    }

    public static function changedCreationCoupons(): array
    {
        return [[['percentage_off' => 20], false], [['active' => false], false], [['expires_at' => '2026-11-10T11:59:59Z'], false], [['percentage_off' => 20], true], [['active' => false], true], [['expires_at' => '2026-11-10T11:59:59Z'], true]];
    }

    public function test_fixed_creation_coupon_verifies_every_price_currency_before_reserving_a_command(): void
    {
        $coupon = $this->coupon(['discount_model' => 'FLAT', 'amount_off' => '5.50', 'currency' => 'USD']);
        $http = $this->http([FakeHttpClient::json($coupon), FakeHttpClient::json(['id' => 'price-test', 'currency' => 'USD']), FakeHttpClient::json(['id' => 'price-second', 'currency' => 'EUR'])]);
        try {
            Team::firstOrFail()->newSubscription('coupon-created', 'price-test')->addPrice('price-second')->withCoupon('coupon-test')->create('general-coupon', ['collection_method' => 'OUT_OF_BAND']);
            self::fail('A fixed Coupon crossed a Price currency.');
        } catch (EvidenceException) {
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    public function test_missing_original_creation_discount_identity_cannot_be_replaced_by_canonical_coupon_match(): void
    {
        $coupon = $this->coupon(['percentage_off' => 25]);
        $remote = Billing::subscription(['id' => 'sub-created-coupon']);
        $this->http([FakeHttpClient::json($coupon), FakeHttpClient::json($coupon), FakeHttpClient::json($remote)]);
        $team = Team::firstOrFail();
        try {
            $team->newSubscription('coupon-created', 'price-test')->withCoupon('coupon-test')->create('general-coupon', ['collection_method' => 'OUT_OF_BAND']);
            self::fail('Missing original Discount proof was accepted.');
        } catch (AmbiguousOperationException) {
            self::assertNull(Operation::sole()->observation);
        }
        $canonical = [...$remote, 'applied_discounts' => [$this->discount(['percentage_off' => 25, 'applied_to_id' => $remote['id']])]];
        $http = $this->http($this->observation($canonical));
        try {
            $team->newSubscription('coupon-created', 'price-test')->withCoupon('coupon-test')->create('general-coupon', ['collection_method' => 'OUT_OF_BAND']);
            self::fail('Canonical Coupon equality substituted for original Discount identity.');
        } catch (AmbiguousOperationException) {
            self::assertNull(Operation::sole()->observation);
            self::assertSame('ambiguous', Operation::sole()->status);
            self::assertCount(4, $http->requests);
        }
    }

    #[DataProvider('unmanagedCouponInputs')]
    public function test_unmanaged_or_stacked_coupon_input_refuses_before_http(string $mode): void
    {
        $http = $this->http([]);
        $builder = Team::firstOrFail()->newSubscription('coupon-created', 'price-test');
        $discounts = [['type' => 'COUPON', 'coupon' => ['id' => 'coupon-test']]];
        try {
            match ($mode) {
                'direct' => $builder->create('raw', ['discounts' => $discounts]),
                'checkout' => $builder->checkout('raw', ['discounts' => [...$discounts, ...$discounts]]),
                'checkout-data' => $builder->checkout('raw', ['subscription_data' => ['discounts' => $discounts]]),
                'stack' => $builder->withCoupon('coupon-test')->withCoupon('another-coupon'),
            };
            self::fail('An unverified Coupon input reached HTTP.');
        } catch (ConflictException) {
            self::assertCount(0, $http->requests);
            self::assertSame(0, Operation::count());
        }
    }

    public static function unmanagedCouponInputs(): array
    {
        return array_map(fn ($mode) => [$mode], ['direct', 'checkout', 'checkout-data', 'stack']);
    }

    public function test_general_coupon_refuses_existing_item_level_discounts_before_reading_a_coupon(): void
    {
        $remote = $this->remote();
        $http = $this->http([FakeHttpClient::json($remote), Billing::page([Billing::item(['applied_discounts' => [['id' => 'item-discount']]])]), FakeHttpClient::json($remote)]);
        try {
            $this->subscription->applyCoupon('stacked-item', 'coupon-test');
            self::fail('Item-level stacking was accepted.');
        } catch (EvidenceException) {
            self::assertCount(4, $http->requests);
            self::assertSame(0, Operation::count());
        }
    }

    public function test_apply_and_explicit_remove_preserve_original_discount_identity_and_financial_commands(): void
    {
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

    #[DataProvider('malformedCouponObservations')]
    public function test_malformed_observed_coupon_values_are_redacted_from_removal_exception_arguments(string $field): void
    {
        $prior = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $general = $field !== 'duration';
            $terms = $general ? ['discount_model' => 'FLAT', 'amount_off' => '5.50', 'currency' => 'USD'] : [];
            $discount = $this->discount($terms);
            $result = $this->remote([$discount], '2026-11-10T12:01:00Z');
            $coupon = $this->coupon($terms);
            $this->http([...$this->observation($this->remote()), FakeHttpClient::json($coupon), FakeHttpClient::json($coupon), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($result), ...$this->observation($result)]);
            $apply = $general ? 'applyCoupon' : 'applyFreePeriod';
            $remove = $general ? 'removeCoupon' : 'removeFreePeriod';
            $this->subscription->{$apply}('free', 'coupon-test');
            $canary = ['profile' => 'SYNTHETIC-PRIVATE-COUPON-CANARY'];
            $malformed = $field === 'currency'
                ? [...$result, 'currency' => $canary, 'updated_at' => '2026-11-10T12:02:00Z']
                : $this->remote([$this->discount([...$terms, $field => $field === 'duration' ? ['period' => 0, ...$canary] : $canary])], '2026-11-10T12:02:00Z');
            $this->http($this->observation($malformed));
            try {
                $this->subscription->{$remove}('remove', 'free');
                self::fail('Malformed observed Coupon became a removal command.');
            } catch (EvidenceException $error) {
                self::assertStringNotContainsString('SYNTHETIC-PRIVATE-COUPON-CANARY', $error->getTraceAsString());
                self::assertInstanceOf(\SensitiveParameterValue::class, $error->getTrace()[0]['args'][$field === 'currency' ? 1 : 0]);
                self::assertSame(1, Operation::count());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $prior);
        }
    }

    public static function malformedCouponObservations(): array
    {
        return [['duration'], ['currency'], ['amount_off']];
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
