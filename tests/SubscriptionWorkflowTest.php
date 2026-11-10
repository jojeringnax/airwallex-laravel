<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionManager;
use Jojeringnax\AirwallexLaravel\SubscriptionBuilder;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class SubscriptionWorkflowTest extends TestCase
{
    private Team $team;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        $this->team = Team::create(['id' => 'team']);
        $this->customer = app(OperationManager::class)->reserveCustomer($this->team);
        $this->customer->update(['remote_id' => 'customer-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_builder_definition_is_stable_and_trial_anchor_preserves_fraction_and_offset(): void
    {
        $one = (new SubscriptionBuilder($this->team, 'default', 'price-one'))->trialDays(7)->startsAt('2026-12-01T12:34:56.123456789+0230')->quantity(2)->addPrice('price-two', 3);
        $two = (new SubscriptionBuilder($this->team, 'default', 'price-one'))->startsAt('2026-12-01T12:34:56.123456789+0230')->quantity(2)->trialDays(7)->addPrice('price-two', 3);
        self::assertSame($one->definition(), $two->definition());
        self::assertSame('2026-12-08T12:34:56.123456789+0230', $one->resolvePayload()['trial_ends_at']);
        self::assertSame('2026-12-01T12:34:56.123456789+0230', $one->resolvePayload()['starts_at']);
        $default = (new SubscriptionBuilder($this->team, 'support', 'price'))->trialDays(7)->resolvePayload();
        self::assertArrayNotHasKey('starts_at', $default);
        self::assertSame('2026-11-17T12:00:00.000000+00:00', $default['trial_ends_at']);
    }

    public function test_direct_multi_item_create_uses_durable_request_and_canonical_pending_state(): void
    {
        $remote = Billing::subscription(['status' => 'PENDING', 'starts_at' => '2026-12-01T12:34:56.123456789+0230']);
        $http = $this->fake([FakeHttpClient::json($remote), ...$this->observation($remote, [Billing::item(), Billing::item(['id' => 'item-two', 'price' => ['id' => 'price-two', 'pricing_model' => 'FLAT', 'metered' => false]])])]);
        $subscription = $this->team->newSubscription('default', 'price-test')->quantity(2)->addPrice('price-two', 3)->startsAt('2026-12-01T12:34:56.123456789+0230')->trialDays(7)->create('create', ['collection_method' => 'OUT_OF_BAND', 'metadata' => (object) ['0' => 'zero'], 'ends_at' => null, 'days_until_due' => 0]);
        self::assertSame('PENDING', $subscription->status);
        self::assertFalse($subscription->valid());
        self::assertCount(2, $subscription->items);
        self::assertSame('1.000000000000000000001', $subscription->items()->first()->quantity);
        $body = self::body($http, 1);
        self::assertSame([['price_id' => 'price-test', 'quantity' => 2], ['price_id' => 'price-two', 'quantity' => 3]], $body['items']);
        self::assertSame(Operation::first()->request_id, $body['request_id']);
        self::assertSame('default', Operation::first()->intent['name']);
        self::assertArrayNotHasKey('name', $body);
        self::assertSame(Operation::first()->id, $body['metadata']['_laravel_operation_id']);
        self::assertSame('zero', $body['metadata'][0]);
        self::assertNull($body['ends_at']);
        self::assertSame(0, $body['days_until_due']);
        self::assertSame('2026-12-08T12:34:56.123456789+0230', $body['trial_ends_at']);
        self::assertSame('entity-test', $body['legal_entity_id']);
        self::assertSame('collector-test', $body['linked_payment_account_id']);
        self::assertSame($subscription->id, SubscriptionSlot::first()->subscription_id);
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        self::assertFalse($subscription->valid());
    }

    public function test_executable_create_recipe_verifies_owned_source_without_inventing_source_status(): void
    {
        $remote = Billing::subscription(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test']);
        $http = $this->fake([$this->source(), FakeHttpClient::json($remote), ...$this->observation($remote)]);
        $recipe = require __DIR__.'/../examples/subscription.php';
        $subscription = $recipe($this->team, 'create', 'price-test', 'source-test');
        self::assertTrue($subscription->active());
        self::assertSame('/api/v1/billing/payment_sources/source-test', $http->requests[1]->getUri()->getPath());
        self::assertSame('AUTO_CHARGE', self::body($http, 2)['collection_method']);
    }

    public function test_foreign_source_and_routing_fail_before_subscription_write(): void
    {
        $http = $this->fake([$this->source(['billing_customer_id' => 'foreign'])]);
        try {
            $this->team->newSubscription('default', 'price-test')->create('foreign-source', ['payment_source_id' => 'source-test']);
            self::fail('Foreign source was used.');
        } catch (OwnershipException) {
            self::assertSame('failed', Operation::first()->status);
            self::assertSame(0, SubscriptionSlot::count());
            self::assertSame(0, Subscription::count());
        }
        self::assertCount(2, $http->requests);
        $this->expectException(OwnershipException::class);
        $this->team->newSubscription('default', 'price-test')->create('foreign-route', ['collection_method' => 'OUT_OF_BAND', 'legal_entity_id' => 'foreign']);
    }

    public function test_create_timeout_retains_original_trial_intent_and_never_replays(): void
    {
        $http = $this->fake([new RuntimeException('timeout')]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->team->newSubscription('default', 'price-test')->trialDays(7)->create('create', ['collection_method' => 'OUT_OF_BAND']);
                self::fail('Expected ambiguous creation.');
            } catch (AmbiguousOperationException) {
                self::assertSame('2026-11-17T12:00:00.000000+00:00', Operation::first()->intent['payload']['trial_ends_at']);
                self::assertArrayNotHasKey('starts_at', Operation::first()->intent['payload']);
                Carbon::setTestNow('2026-11-11T12:00:00Z');
            }
        }
        self::assertCount(2, $http->requests);
        self::assertSame(1, Operation::count());
        self::assertNotNull(SubscriptionSlot::first()->operation_id);
    }

    public function test_recorded_create_response_can_recover_after_canonical_read_failure(): void
    {
        $remote = Billing::subscription();
        $http = $this->fake([FakeHttpClient::json($remote), FakeHttpClient::json(['code' => 'unavailable'], 503), ...$this->observation($remote)]);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
            self::fail('Expected failed readback.');
        } catch (AmbiguousOperationException) {
            self::assertSame('sub-test', Operation::first()->remote_id);
            self::assertSame(0, Subscription::count());
        }
        $subscription = app(SubscriptionManager::class)->reconcileOperation(Operation::first());
        self::assertSame('sub-test', $subscription->remote_id);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertCount(6, $http->requests);
        self::assertSame('GET', $http->requests[3]->getMethod());
    }

    public function test_single_item_quantity_and_multi_item_explicit_target_preserve_all_items(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(), Billing::item(['id' => 'item-two', 'quantity' => '3', 'price' => ['id' => 'price-two', 'pricing_model' => 'PER_UNIT', 'metered' => false]])];
        $subscription = $this->seedSubscription($remote, $items);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $newItems = [$items[0], Billing::item(['id' => 'item-two', 'quantity' => '5.00000000000000001', 'updated_at' => '2026-11-10T12:01:00Z', 'price' => ['id' => 'price-two', 'pricing_model' => 'PER_UNIT', 'metered' => false]])];
        $http = $this->fake([...$this->observation($remote, $items), FakeHttpClient::json($changed), ...$this->observation($changed, $newItems)]);
        $result = $subscription->updateQuantity('quantity', 5, ['default_proration_mode' => 'NONE'], 'item-two');
        self::assertSame([['id' => 'item-two', 'quantity' => 5]], self::body($http, 4)['items']);
        self::assertSame(2, $result->items()->count());
        self::assertSame('5.00000000000000001', $result->items()->where('remote_id', 'item-two')->first()->quantity);
        self::assertSame('acknowledged', Operation::first()->status);
        self::assertNull(Operation::first()->lock_key);
    }

    public function test_acknowledged_quantity_effect_can_be_read_again_and_does_not_block_a_separate_source_action(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $changed = Billing::subscription(['payment_source_id' => 'source-original', 'updated_at' => '2026-11-10T12:01:00Z']);
        $unequal = Billing::item(['quantity' => '5.00000000000000001', 'updated_at' => '2026-11-10T12:01:00Z']);
        $sourceChanged = array_replace($changed, ['payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:02:00Z']);
        $confirmed = array_replace($sourceChanged, ['updated_at' => '2026-11-10T12:03:00Z']);
        $equal = Billing::item(['quantity' => '5.000', 'updated_at' => '2026-11-10T12:03:00Z']);
        $http = $this->fake([
            ...$this->observation($remote, [$item]), $this->source(['id' => 'source-original']), FakeHttpClient::json($changed), ...$this->observation($changed, [$unequal]),
            ...$this->observation($changed, [$unequal]),
            ...$this->observation($changed, [$unequal]), $this->source(), FakeHttpClient::json($sourceChanged), ...$this->observation($sourceChanged, [$unequal]),
            ...$this->observation($confirmed, [$equal]),
        ]);
        $options = ['default_proration_mode' => 'NONE', 'payment_source_id' => 'source-original'];
        $actual = $subscription->updateQuantity('quantity', 5, $options);
        $operation = Operation::where('action_key', 'quantity')->first();
        $requestId = $operation->request_id;
        self::assertSame('5.00000000000000001', $actual->items()->first()->quantity);
        self::assertSame('acknowledged', $operation->status);
        self::assertSame('sub-test', $operation->remote_id);
        self::assertNull($operation->lock_key);
        $actual->updateQuantity('quantity', 5, $options);
        self::assertSame('acknowledged', $operation->fresh()->status);
        self::assertSame($requestId, $operation->fresh()->request_id);
        self::assertSame(1, Operation::count());
        $sourceResult = $actual->updatePaymentSource('separate-source-action', 'source-test');
        self::assertSame('source-test', $sourceResult->snapshot['payment_source_id']);
        self::assertSame('succeeded', Operation::where('action_key', 'separate-source-action')->first()->status);
        self::assertSame('acknowledged', $operation->fresh()->status);
        $result = app(SubscriptionManager::class)->reconcileOperation($operation);
        self::assertSame('5.000', $result->items()->first()->quantity);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame($requestId, $operation->fresh()->request_id);
        $writes = array_values(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST'));
        self::assertCount(2, $writes);
        self::assertSame($requestId, json_decode((string) $writes[0]->getBody(), true, flags: JSON_THROW_ON_ERROR)['request_id']);
    }

    #[DataProvider('unprovenItemDeltas')]
    public function test_item_deltas_stay_acknowledged_until_their_complete_observable_effect_is_present(string $kind): void
    {
        $remote = Billing::subscription();
        $old = Billing::item(['quantity' => '3']);
        $subscription = $this->seedSubscription($remote, [$old]);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $confirmed = Billing::subscription(['updated_at' => '2026-11-10T12:02:00Z']);
        $price = $kind === 'add' ? 'price-test' : 'new-price';
        $new = Billing::item(['id' => 'new-item', 'quantity' => '3.000', 'updated_at' => '2026-11-10T12:01:00Z', 'price' => ['id' => $price, 'pricing_model' => 'PER_UNIT', 'metered' => false]]);
        $second = array_replace($new, ['id' => 'second-new-item']);
        $unproven = match ($kind) {
            'swap', 'two_additions' => [$old, $new],
            default => [$old],
        };
        $complete = match ($kind) {
            'remove' => [],
            'swap' => [$new],
            'two_additions' => [$old, $new, $second],
            default => [$old, $new],
        };
        $http = $this->fake([...$this->observation($remote, [$old]), FakeHttpClient::json($changed), ...$this->observation($changed, $unproven), ...$this->observation($confirmed, $complete)]);
        $options = ['default_proration_mode' => 'NONE'];
        $actual = match ($kind) {
            'remove' => $subscription->removeItem('delta', 'item-test', $options),
            'swap' => $subscription->swapPrice('delta', 'new-price', $options),
            'two_additions' => $subscription->updateItems('delta', [['price_id' => 'new-price', 'quantity' => 3], ['price_id' => 'new-price', 'quantity' => 3]], $options),
            default => $subscription->addItem('delta', 'price-test', 3, $options),
        };
        $operation = Operation::first();
        self::assertSame(['item-test'], $operation->intent['prior_item_ids']);
        self::assertCount(count($unproven), $actual->items()->get());
        self::assertSame('acknowledged', $operation->status);
        self::assertNull($operation->lock_key);
        $actual = app(SubscriptionManager::class)->reconcileOperation($operation);
        self::assertCount(count($complete), $actual->items()->get());
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    public static function unprovenItemDeltas(): array
    {
        return [['remove'], ['swap'], ['add'], ['two_additions']];
    }

    public function test_later_addition_cannot_also_prove_an_earlier_unconfirmed_addition(): void
    {
        $remote = Billing::subscription();
        $old = Billing::item(['quantity' => '3']);
        $subscription = $this->seedSubscription($remote, [$old]);
        $first = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $second = Billing::subscription(['updated_at' => '2026-11-10T12:02:00Z']);
        $new = Billing::item(['id' => 'later-item', 'quantity' => '3.000', 'updated_at' => '2026-11-10T12:02:00Z']);
        $http = $this->fake([
            ...$this->observation($remote, [$old]), FakeHttpClient::json($first), ...$this->observation($first, [$old]),
            ...$this->observation($first, [$old]), FakeHttpClient::json($second), ...$this->observation($second, [$old, $new]),
            ...$this->observation($second, [$old, $new]),
        ]);
        $subscription->addItem('first-add', 'price-test', 3, ['default_proration_mode' => 'NONE']);
        $original = Operation::where('action_key', 'first-add')->first();
        self::assertSame('acknowledged', $original->status);
        $subscription->addItem('second-add', 'price-test', 3, ['default_proration_mode' => 'NONE']);
        self::assertSame('acknowledged', Operation::where('action_key', 'second-add')->first()->status);
        app(SubscriptionManager::class)->reconcileOperation($original);
        self::assertSame('acknowledged', $original->fresh()->status);
        self::assertNull($original->fresh()->lock_key);
        self::assertSame(2, $subscription->items()->count());
        self::assertSame(2, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    public function test_stale_acknowledgement_preserves_concurrently_confirmed_success(): void
    {
        $remote = Billing::subscription();
        $subscription = $this->seedSubscription($remote, [Billing::item(['quantity' => '3'])]);
        $operation = app(OperationManager::class)->prepare($this->customer, 'add', 'subscription.add_item', [], [], $subscription);
        $operation->update(['status' => 'ambiguous', 'remote_id' => 'sub-test']);
        $event = 'eloquent.retrieved: '.Operation::class;
        app('events')->listen($event, function (Operation $retrieved) use ($operation): void {
            if ($retrieved->id === $operation->id) {
                DB::table('airwallex_operations')->where('id', $operation->id)
                    ->update(['status' => 'succeeded', 'lock_key' => null, 'error_code' => null]);
            }
        });
        try {
            app(OperationManager::class)->acknowledgeItemEffect($operation);
            self::assertSame('succeeded', $operation->fresh()->status);
            self::assertNull($operation->fresh()->error_code);
        } finally {
            app('events')->forget($event);
        }
    }

    public function test_unknown_quantity_write_keeps_ambiguity_and_mutex_even_when_a_later_read_matches(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $equal = Billing::item(['quantity' => '5.000', 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, [$item]), new RuntimeException('timeout'), ...$this->observation($changed, [$equal]), ...$this->observation($changed, [$equal])]);
        try {
            $subscription->updateQuantity('quantity', 5, ['default_proration_mode' => 'NONE']);
            self::fail('Expected unknown write outcome.');
        } catch (AmbiguousOperationException) {
            self::assertNull(Operation::first()->remote_id);
        }
        try {
            app(SubscriptionManager::class)->reconcileOperation(Operation::first());
            self::fail('Matching quantity must not prove the original financial response.');
        } catch (AmbiguousOperationException) {
            self::assertSame('5.000', $subscription->items()->first()->quantity);
            self::assertSame('ambiguous', Operation::first()->status);
            self::assertNotNull(Operation::first()->lock_key);
        }
        try {
            $subscription->updatePaymentSource('separate-source-action', 'source-test');
            self::fail('Unknown financial outcome released its mutex.');
        } catch (ConflictException) {
            self::assertSame(1, Operation::count());
        }
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    #[DataProvider('quantityChanges')]
    public function test_single_item_increase_and_decrease_preserve_native_quantity(int $before, int $after): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => (string) $before]);
        $subscription = $this->seedSubscription($remote, [$item]);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $new = Billing::item(['quantity' => (string) $after, 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, [$item]), FakeHttpClient::json($changed), ...$this->observation($changed, [$new])]);
        $result = $subscription->updateQuantity('quantity', $after, ['default_proration_mode' => 'PRORATED']);
        self::assertSame([['id' => 'item-test', 'quantity' => $after]], self::body($http, 4)['items']);
        self::assertSame((string) $after, $result->items()->first()->quantity);
        self::assertSame('PRORATED', self::body($http, 4)['default_proration_mode']);
    }

    public static function quantityChanges(): array
    {
        return [[2, 5], [5, 2]];
    }

    #[DataProvider('opaqueItemIdentities')]
    public function test_numeric_looking_item_identity_is_selected_by_exact_string(string $otherId, string $targetId): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(['id' => $otherId, 'quantity' => '2']), Billing::item(['id' => $targetId, 'quantity' => '3'])];
        $subscription = $this->seedSubscription($remote, $items);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $updated = [$items[0], Billing::item(['id' => $targetId, 'quantity' => '5', 'updated_at' => '2026-11-10T12:01:00Z'])];
        $http = $this->fake([...$this->observation($remote, $items), FakeHttpClient::json($changed), ...$this->observation($changed, $updated)]);
        $result = $subscription->updateQuantity('quantity', 5, ['default_proration_mode' => 'NONE'], $targetId);
        self::assertSame($targetId, self::body($http, 4)['items'][0]['id']);
        self::assertSame('2', $result->items()->where('remote_id', $otherId)->first()->quantity);
        self::assertSame('5', $result->items()->where('remote_id', $targetId)->first()->quantity);
    }

    public static function opaqueItemIdentities(): array
    {
        return [['01', '1'], ['0e1', '0e2']];
    }

    public function test_multi_item_convenience_refuses_implicit_selection(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(), Billing::item(['id' => 'item-two'])];
        $subscription = $this->seedSubscription($remote, $items);
        $http = $this->fake($this->observation($remote, $items));
        $this->expectException(ConflictException::class);
        try {
            $subscription->updateQuantity('quantity', 3, ['default_proration_mode' => 'PRORATED']);
        } finally {
            self::assertCount(4, $http->requests);
            self::assertSame(0, Operation::count());
        }
    }

    public function test_swap_price_deletes_and_adds_with_exact_integer_quantity(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(['quantity' => '3.0000000000000000000'])];
        $subscription = $this->seedSubscription($remote, $items);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $newItems = [Billing::item(['id' => 'new-item', 'quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z', 'price' => ['id' => 'new-price', 'pricing_model' => 'VOLUME', 'metered' => false]])];
        $http = $this->fake([...$this->observation($remote, $items), FakeHttpClient::json($changed), ...$this->observation($changed, $newItems)]);
        $result = $subscription->swapPrice('swap', 'new-price', ['default_proration_mode' => 'ALL']);
        self::assertSame([['id' => 'item-test', 'deleted' => true], ['price_id' => 'new-price', 'quantity' => 3]], self::body($http, 4)['items']);
        self::assertSame('new-item', $result->items()->first()->remote_id);
    }

    public function test_fractional_observation_requires_explicit_replacement_quantity(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item()];
        $subscription = $this->seedSubscription($remote, $items);
        $http = $this->fake($this->observation($remote, $items));
        try {
            $subscription->swapPrice('swap', 'new-price', ['default_proration_mode' => 'NONE']);
            self::fail('Fractional quantity was truncated.');
        } catch (EvidenceException) {
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, $items), FakeHttpClient::json($changed), ...$this->observation($changed, [Billing::item(['id' => 'new', 'quantity' => '2'])])]);
        $subscription->swapPrice('swap-explicit', 'new-price', ['default_proration_mode' => 'NONE'], quantity: 2);
        self::assertSame(2, self::body($http, 4)['items'][1]['quantity']);
    }

    public function test_native_custom_proration_and_false_null_zero_controls_remain_exact(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(['quantity' => '2'])];
        $subscription = $this->seedSubscription($remote, $items);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, $items), FakeHttpClient::json($changed), ...$this->observation($changed, [Billing::item(['quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z'])])]);
        $recipe = require __DIR__.'/../examples/change-items.php';
        $recipe($subscription, 'custom', [['id' => 'item-test', 'quantity' => 3, 'proration_mode' => 'CUSTOM', 'proration_value' => ['charge_amount' => '100.00000000000000001', 'credit_amount' => '0.00']]], ['billing_action' => 'IMMEDIATE_CHARGE_AND_KEEP_CYCLE', 'proration_date' => '2026-11-10T12:00:00.123456789+0230', 'cancel_at_period_end' => false, 'ends_at' => null, 'days_until_due' => 0]);
        $body = self::body($http, 4);
        self::assertSame('100.00000000000000001', $body['items'][0]['proration_value']['charge_amount']);
        self::assertSame('0.00', $body['items'][0]['proration_value']['credit_amount']);
        self::assertFalse($body['cancel_at_period_end']);
        self::assertNull($body['ends_at']);
        self::assertSame(0, $body['days_until_due']);
        self::assertSame('2026-11-10T12:00:00.123456789+0230', $body['proration_date']);
        self::assertArrayNotHasKey('metadata', $body);
    }

    public function test_financial_timeout_can_refresh_state_but_never_confirms_original_proration(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(['quantity' => '2'])];
        $subscription = $this->seedSubscription($remote, $items);
        $changed = Billing::subscription(['status' => 'CANCELLED', 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, $items), new RuntimeException('timeout'), ...$this->observation($changed, $items)]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                if ($attempt === 0) {
                    $subscription->cancelNow('cancel', 'PRORATED');
                } else {
                    app(SubscriptionManager::class)->reconcileOperation(Operation::first());
                }
                self::fail('Unknown refund/proration action was declared complete.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', Operation::first()->status);
                self::assertNull(Operation::first()->remote_id);
                if ($attempt === 0) {
                    self::assertSame('ACTIVE', $subscription->fresh()->status);
                    self::assertTrue($subscription->fresh()->valid());
                }
            }
        }
        self::assertFalse($subscription->fresh()->valid());
        self::assertSame('CANCELLED', $subscription->fresh()->status);
        self::assertCount(8, $http->requests);
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    #[DataProvider('recoveredStates')]
    public function test_explicit_source_replacement_follows_canonical_recovery_only(string $status, bool $valid): void
    {
        $remote = Billing::subscription(['status' => 'UNPAID']);
        $subscription = $this->seedSubscription($remote);
        $changed = Billing::subscription(['status' => $status, 'updated_at' => '2026-11-10T12:01:00Z', 'payment_source_id' => 'source-test']);
        $http = $this->fake([...$this->observation($remote), $this->source(), FakeHttpClient::json($changed), ...$this->observation($changed)]);
        $recipe = require __DIR__.'/../examples/replace-source.php';
        $result = $recipe($subscription, 'source', 'source-test');
        self::assertSame($valid, $result->valid());
        self::assertSame('source-test', self::body($http, 5)['payment_source_id']);
        self::assertArrayNotHasKey('metadata', self::body($http, 5));
    }

    public static function recoveredStates(): array
    {
        return [['ACTIVE', true], ['UNPAID', false]];
    }

    #[DataProvider('endingStates')]
    public function test_scheduled_ending_caps_access_at_exact_confirmed_end_and_unpaid_stays_denied(string $status, bool $eligible): void
    {
        $remote = Billing::subscription(['status' => $status]);
        $subscription = $this->seedSubscription($remote);
        $changed = Billing::subscription(['status' => $status, 'cancel_at_period_end' => true, 'ends_at' => '2026-11-11T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote), FakeHttpClient::json($changed), ...$this->observation($changed)]);
        $recipe = require __DIR__.'/../examples/schedule-ending.php';
        $result = $recipe($subscription, 'ending');
        self::assertSame($eligible, $result->onGracePeriod());
        self::assertSame($eligible, $result->valid());
        self::assertTrue(self::body($http, 4)['cancel_at_period_end']);
        Carbon::setTestNow('2026-11-11T00:00:00Z');
        self::assertFalse($result->valid());
    }

    public static function endingStates(): array
    {
        return [['ACTIVE', true], ['UNPAID', false]];
    }

    #[DataProvider('cancellationStates')]
    public function test_immediate_cancellation_observes_provider_terminal_state_and_preserves_history(string $status, bool $eligible): void
    {
        $remote = Billing::subscription(['status' => $status, 'trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => '2026-12-01T00:00:00Z']);
        $subscription = $this->seedSubscription($remote);
        $subscription->forceFill(['trial_starts_at' => $remote['trial_starts_at'], 'trial_ends_at' => $remote['trial_ends_at']])->save();
        self::assertSame($eligible, $subscription->valid());
        $cancelled = Billing::subscription(['status' => 'CANCELLED', 'updated_at' => '2026-11-10T12:01:00Z', 'ends_at' => '2026-12-01T00:00:00Z']);
        $http = $this->fake([...$this->observation($remote), FakeHttpClient::json($cancelled), ...$this->observation($cancelled)]);
        $result = $subscription->cancelNow('cancel', 'ALL');
        self::assertFalse($result->valid());
        self::assertSame('CANCELLED', $result->status);
        self::assertSame('ALL', self::body($http, 4)['proration_behavior']);
        self::assertSame(0, SubscriptionSlot::count());
        self::assertSame(1, Subscription::count());
        self::assertSame($result->id, $this->team->subscription('default')->id);
    }

    public static function cancellationStates(): array
    {
        return [['PENDING', false], ['IN_TRIAL', true], ['ACTIVE', true], ['UNPAID', false]];
    }

    public function test_optional_subscription_routing_allows_confirmed_creation_and_later_cancellation(): void
    {
        $remote = Billing::subscription();
        unset($remote['legal_entity_id'], $remote['linked_payment_account_id']);
        $http = $this->fake([FakeHttpClient::json($remote), ...$this->observation($remote)]);
        $subscription = $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertArrayNotHasKey('legal_entity_id', $subscription->snapshot);
        self::assertArrayNotHasKey('linked_payment_account_id', $subscription->snapshot);
        self::assertSame('collector-test', self::body($http, 1)['linked_payment_account_id']);
        $cancelled = [...$remote, 'status' => 'CANCELLED', 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->fake([...$this->observation($remote), FakeHttpClient::json($cancelled), ...$this->observation($cancelled)]);
        self::assertSame('CANCELLED', $subscription->cancelNow('cancel', 'NONE')->status);
        self::assertSame(2, Operation::where('status', 'succeeded')->count());
    }

    #[DataProvider('malformedRouting')]
    public function test_present_malformed_subscription_routing_never_confirms_creation(string $key, mixed $value): void
    {
        $remote = Billing::subscription([$key => $value]);
        $http = $this->fake([FakeHttpClient::json($remote), ...$this->observation($remote)]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
        } finally {
            self::assertSame(0, Subscription::count());
            self::assertSame(1, SubscriptionSlot::count());
            self::assertSame(1, count(array_filter($http->requests, fn ($request) => $request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/subscriptions/create'))));
        }
    }

    public static function malformedRouting(): array
    {
        return [['legal_entity_id', null], ['legal_entity_id', []], ['legal_entity_id', ''], ['linked_payment_account_id', null], ['linked_payment_account_id', []], ['linked_payment_account_id', '']];
    }

    public function test_foreign_write_response_cannot_be_overridden_by_optional_omission_in_later_reads(): void
    {
        $remote = Billing::subscription();
        unset($remote['legal_entity_id'], $remote['linked_payment_account_id']);
        $http = $this->fake([FakeHttpClient::json([...$remote, 'legal_entity_id' => 'foreign']), ...$this->observation($remote)]);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
            self::fail('Contradictory write response was accepted.');
        } catch (AmbiguousOperationException $exception) {
            self::assertSame('ownership', $exception->causeCategory);
        }
        self::assertSame('ambiguous', Operation::first()->status);
        self::assertSame(0, Subscription::count());
        self::assertCount(2, $http->requests);
        self::assertNotNull(Operation::first()->lock_key);
        $this->fake($this->observation($remote));
        try {
            app(SubscriptionManager::class)->reconcileOperation(Operation::first());
            self::fail('Omission resolved contradictory response evidence.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::first()->status);
        }
        $this->fake($this->observation(Billing::subscription()));
        app(SubscriptionManager::class)->reconcileOperation(Operation::first());
        self::assertSame('succeeded', Operation::first()->status);
    }

    public function test_same_version_optional_routing_omission_during_paging_and_later_change_is_allowed(): void
    {
        $present = Billing::subscription();
        $subscription = $this->seedSubscription($present);
        $absent = $present;
        unset($absent['legal_entity_id'], $absent['linked_payment_account_id']);
        $cancelled = [...$absent, 'status' => 'CANCELLED', 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->fake([FakeHttpClient::json($present), Billing::page([]), FakeHttpClient::json($absent), FakeHttpClient::json($cancelled), ...$this->observation($cancelled)]);
        $result = $subscription->cancelNow('cancel', 'NONE');
        self::assertSame('CANCELLED', $result->status);
        self::assertArrayNotHasKey('legal_entity_id', $result->snapshot);
        self::assertSame('succeeded', Operation::first()->status);
    }

    public function test_first_paged_routing_contradiction_cannot_be_hidden_by_later_omission(): void
    {
        $remote = Billing::subscription();
        $subscription = $this->seedSubscription($remote);
        $absent = $remote;
        unset($absent['legal_entity_id'], $absent['linked_payment_account_id']);
        $http = $this->fake([FakeHttpClient::json([...$remote, 'legal_entity_id' => 'foreign']), Billing::page([]), FakeHttpClient::json($absent)]);
        $this->expectException(OwnershipException::class);
        try {
            $subscription->cancelNow('cancel', 'NONE');
        } finally {
            self::assertSame(0, Operation::count());
            self::assertCount(2, $http->requests);
        }
    }

    #[DataProvider('routingPaging')]
    public function test_read_only_foreign_routing_evidence_survives_later_optional_omission(bool $omitFinal): void
    {
        $foreign = Billing::subscription(['linked_payment_account_id' => 'foreign']);
        $last = $foreign;
        if ($omitFinal) {
            unset($last['legal_entity_id'], $last['linked_payment_account_id']);
        }
        $this->fake([FakeHttpClient::json($foreign), Billing::page([]), FakeHttpClient::json($last)]);
        $subscription = $this->team->importSubscription('default', 'sub-test');
        $absent = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        unset($absent['legal_entity_id'], $absent['linked_payment_account_id']);
        $this->fake($this->observation($absent));
        $subscription = $subscription->reconcile();
        self::assertArrayNotHasKey('linked_payment_account_id', $subscription->snapshot);
        self::assertSame('foreign', $subscription->observed_routing['linked_payment_account_id']);
        $http = $this->fake($this->observation($absent));
        $this->expectException(OwnershipException::class);
        try {
            $subscription->cancelNow('cancel', 'NONE');
        } finally {
            self::assertSame(0, Operation::count());
            self::assertSame(0, count(array_filter($http->requests, fn ($request) => str_ends_with($request->getUri()->getPath(), '/cancel'))));
        }
    }

    public static function routingPaging(): array
    {
        return [[false], [true]];
    }

    public function test_foreign_canonical_routing_blocks_financial_writes(): void
    {
        $remote = Billing::subscription(['linked_payment_account_id' => 'foreign']);
        $subscription = $this->seedSubscription($remote);
        $http = $this->fake($this->observation($remote));
        $this->expectException(OwnershipException::class);
        try {
            $subscription->cancelNow('cancel', 'NONE');
        } finally {
            self::assertCount(2, $http->requests);
            self::assertSame(0, Operation::count());
        }
    }

    public function test_sdk_rejects_native_invalid_quantity_without_issuing_update(): void
    {
        $remote = Billing::subscription();
        $items = [Billing::item(['quantity' => '2'])];
        $subscription = $this->seedSubscription($remote, $items);
        $http = $this->fake($this->observation($remote, $items));
        $this->expectException(ValidationException::class);
        try {
            $subscription->updateQuantity('quantity-zero', 0, ['default_proration_mode' => 'NONE']);
        } finally {
            self::assertSame('failed', Operation::first()->status);
            self::assertNull(Operation::first()->lock_key);
            self::assertCount(4, $http->requests);
        }
    }

    public function test_add_and_remove_send_only_explicit_changes_and_reconcile_complete_sets(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $added = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $new = Billing::item(['id' => 'item-new', 'quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z', 'price' => ['id' => 'price-new', 'pricing_model' => 'GRADUATED', 'metered' => false]]);
        $removed = Billing::subscription(['updated_at' => '2026-11-10T12:02:00Z']);
        $http = $this->fake([...$this->observation($remote, [$item]), FakeHttpClient::json($added), ...$this->observation($added, [$item, $new]), ...$this->observation($added, [$item, $new]), FakeHttpClient::json($removed), ...$this->observation($removed, [$item])]);
        $result = $subscription->addItem('add', 'price-new', 3, ['default_proration_mode' => 'NONE']);
        self::assertSame(2, $result->items()->count());
        $result = $result->removeItem('remove', 'item-new', ['default_proration_mode' => 'PRORATED']);
        self::assertSame([['price_id' => 'price-new', 'quantity' => 3]], self::body($http, 4)['items']);
        self::assertSame([['id' => 'item-new', 'deleted' => true]], self::body($http, 11)['items']);
        self::assertSame(1, $result->items()->count());
        self::assertSame('item-test', $result->items()->first()->remote_id);
    }

    public function test_item_change_requires_explicit_proration_and_unknown_item_shape_keeps_access(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $http = $this->fake($this->observation($remote, [$item]));
        try {
            $subscription->updateQuantity('missing-proration', 3, []);
            self::fail('Proration was inferred.');
        } catch (ConflictException) {
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
        $unknown = Billing::item(['quantity' => '2', 'updated_at' => '2026-11-10T12:01:00Z', 'price' => ['id' => 'price-test', 'pricing_model' => 'FUTURE_PRICE', 'metered' => false]]);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake($this->observation($changed, [$unknown]));
        try {
            $subscription->updateQuantity('unsupported-shape', 3, ['default_proration_mode' => 'NONE']);
            self::fail('Unsupported item shape was mutated.');
        } catch (EvidenceException) {
            self::assertTrue($subscription->fresh()->valid());
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    public function test_recorded_update_response_recovers_without_repeating_financial_write(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $changed = Billing::subscription(['updated_at' => '2026-11-10T12:01:00Z']);
        $new = Billing::item(['quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, [$item]), FakeHttpClient::json($changed), FakeHttpClient::json(['code' => 'unavailable'], 503), ...$this->observation($changed, [$new])]);
        try {
            $subscription->updateQuantity('quantity', 3, ['default_proration_mode' => 'ALL']);
            self::fail('Expected canonical read failure.');
        } catch (AmbiguousOperationException) {
            self::assertSame('sub-test', Operation::first()->remote_id);
            self::assertSame('2', $subscription->items()->first()->quantity);
        }
        $operation = Operation::first();
        $result = app(SubscriptionManager::class)->reconcileOperation($operation);
        self::assertSame('3', $result->items()->first()->quantity);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame($operation->request_id, $operation->fresh()->request_id);
        self::assertCount(9, $http->requests);
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
        $this->expectException(ConflictException::class);
        $subscription->updateQuantity('quantity', 4, ['default_proration_mode' => 'ALL']);
    }

    public function test_cancelled_history_cannot_release_a_replacement_name(): void
    {
        $remote = Billing::subscription();
        $subscription = $this->seedSubscription($remote);
        $cancelled = Billing::subscription(['status' => 'CANCELLED', 'updated_at' => '2026-11-10T12:01:00Z']);
        $replacement = Billing::subscription(['id' => 'sub-new', 'updated_at' => '2026-11-10T12:02:00Z']);
        $http = $this->fake([...$this->observation($remote), FakeHttpClient::json($cancelled), ...$this->observation($cancelled), FakeHttpClient::json($replacement), ...$this->observation($replacement), ...$this->observation($cancelled)]);
        $recipe = require __DIR__.'/../examples/cancel-subscription.php';
        $old = $recipe($subscription, 'cancel', 'NONE');
        self::assertFalse($old->valid());
        self::assertSame(0, SubscriptionSlot::count());
        $new = $this->team->newSubscription('default', 'price-test')->create('replacement', ['collection_method' => 'OUT_OF_BAND']);
        self::assertNotSame($old->id, $new->id);
        $old->cancelNow('cancel', 'NONE');
        self::assertSame($new->id, $this->team->subscription('default')->id);
        self::assertSame($new->id, SubscriptionSlot::first()->subscription_id);
        self::assertTrue($this->team->subscribed('default'));
        self::assertSame(2, Subscription::count());
        self::assertSame('NONE', self::body($http, 4)['proration_behavior']);
    }

    public function test_same_subscription_financial_hold_rejects_a_competing_action(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $held = app(OperationManager::class)->prepare($this->customer, 'held', 'subscription.cancel', [], [], $subscription);
        $http = $this->fake($this->observation($remote, [$item]));
        $this->expectException(ConflictException::class);
        try {
            $subscription->updateQuantity('competing', 3, ['default_proration_mode' => 'NONE']);
        } finally {
            self::assertSame('prepared', $held->fresh()->status);
            self::assertNotNull($held->fresh()->lock_key);
            self::assertSame(1, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    public function test_create_success_then_local_model_failure_recovers_original_resource(): void
    {
        config(['airwallex.models.subscription' => WorkflowFaultySubscription::class]);
        WorkflowFaultySubscription::$failNext = true;
        $remote = Billing::subscription();
        $http = $this->fake([FakeHttpClient::json($remote), ...$this->observation($remote), ...$this->observation($remote)]);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
            self::fail('Expected local model failure.');
        } catch (AmbiguousOperationException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('SYNTHETIC-LOCAL-SECRET', (string) $exception);
            self::assertSame('sub-test', Operation::first()->remote_id);
        }
        $result = $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
        self::assertInstanceOf(WorkflowFaultySubscription::class, $result);
        self::assertSame('sub-test', $result->remote_id);
        self::assertSame(1, Operation::count());
        self::assertCount(8, $http->requests);
    }

    public function test_new_resource_correlation_preserves_numeric_object_keys_and_blocks_override(): void
    {
        $remote = Billing::subscription();
        $http = $this->fake([FakeHttpClient::json($remote), ...$this->observation($remote)]);
        $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND', 'metadata' => (object) ['1' => 'one', '01' => 'zero-one']]);
        $metadata = self::body($http, 1)['metadata'];
        self::assertSame('one', $metadata[1]);
        self::assertSame('zero-one', $metadata['01']);
        self::assertArrayNotHasKey(0, $metadata);
        $this->expectException(ConflictException::class);
        $this->team->newSubscription('support', 'price-test')->create('override', ['collection_method' => 'OUT_OF_BAND', 'metadata' => ['_laravel_operation_id' => 'foreign']]);
    }

    public function test_canonical_foreign_create_routing_never_establishes_access(): void
    {
        $remote = Billing::subscription(['linked_payment_account_id' => 'foreign']);
        $http = $this->fake([FakeHttpClient::json($remote), FakeHttpClient::json($remote)]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', ['collection_method' => 'OUT_OF_BAND']);
        } finally {
            self::assertSame(0, Subscription::count());
            self::assertFalse($this->team->subscribed('default'));
            self::assertSame('ambiguous', Operation::first()->status);
            self::assertNotNull(SubscriptionSlot::first()->operation_id);
            self::assertCount(2, $http->requests);
        }
    }

    #[DataProvider('creationControlMismatches')]
    public function test_creation_does_not_associate_a_resource_with_contradictory_collection_or_source(array $options, array $provider): void
    {
        $remote = Billing::subscription($provider);
        $responses = isset($options['payment_source_id']) ? [$this->source()] : [];
        $http = $this->fake([...$responses, FakeHttpClient::json(['id' => 'sub-test']), ...$this->observation($remote)]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', $options);
        } finally {
            self::assertSame(0, Subscription::count());
            self::assertFalse($this->team->subscribed('default'));
            self::assertSame('ambiguous', Operation::first()->status);
            self::assertNotNull(Operation::first()->lock_key);
            self::assertNotNull(SubscriptionSlot::first()->operation_id);
            self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
        }
    }

    public static function creationControlMismatches(): array
    {
        return [
            [['collection_method' => 'OUT_OF_BAND'], ['collection_method' => 'AUTO_CHARGE']],
            [['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test'], ['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-other']],
        ];
    }

    #[DataProvider('creationControlMismatches')]
    public function test_known_create_response_with_saved_projection_requires_requested_controls_on_recovery(array $options, array $mismatch): void
    {
        config(['airwallex.models.operation' => WorkflowFaultyOperation::class]);
        WorkflowFaultyOperation::$failSuccess = true;
        $remote = Billing::subscription($options);
        $wrong = array_replace($remote, $mismatch, ['updated_at' => '2026-11-10T12:01:00Z']);
        $correct = array_replace($remote, ['updated_at' => '2026-11-10T12:02:00Z']);
        $responses = isset($options['payment_source_id']) ? [$this->source()] : [];
        $http = $this->fake([...$responses, FakeHttpClient::json(['id' => 'sub-test']), ...$this->observation($remote), ...$this->observation($wrong), ...$this->observation($correct)]);
        try {
            $this->team->newSubscription('default', 'price-test')->create('create', $options);
            self::fail('Expected local operation confirmation failure.');
        } catch (AmbiguousOperationException) {
            self::assertSame(1, Subscription::count());
            self::assertSame('sub-test', Operation::first()->remote_id);
            self::assertSame('ambiguous', Operation::first()->status);
        }
        try {
            app(SubscriptionManager::class)->reconcileOperation(Operation::first());
            self::fail('Contradictory canonical controls confirmed the creation.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::first()->status);
            self::assertNotNull(Operation::first()->lock_key);
        }
        $result = app(SubscriptionManager::class)->reconcileOperation(Operation::first());
        self::assertSame('sub-test', $result->remote_id);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    #[DataProvider('weakQuantities')]
    public function test_ordinary_weak_php_callers_cannot_coerce_quantity_inputs(string $mode, mixed $value): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $builder = str_starts_with($mode, 'builder');
        $subscription = $builder ? null : $this->seedSubscription($remote, [$item]);
        $http = $this->fake($builder ? [] : $this->observation($remote, [$item]));
        $path = tempnam(sys_get_temp_dir(), 'airwallex-weak-consumer-');
        file_put_contents($path, <<<'PHP'
<?php
return static function ($team, $subscription, $mode, $quantity) {
    $options = ['default_proration_mode' => 'NONE'];
    return match ($mode) {
        'builder_quantity' => $team->newSubscription('default', 'price-test')->quantity($quantity)->create('weak', ['collection_method' => 'OUT_OF_BAND']),
        'builder_add_price' => $team->newSubscription('default', 'price-test')->addPrice('price-two', $quantity)->create('weak', ['collection_method' => 'OUT_OF_BAND']),
        'update_quantity' => $subscription->updateQuantity('weak', $quantity, $options),
        'add_item' => $subscription->addItem('weak', 'price-two', $quantity, $options),
        'swap_override' => $subscription->swapPrice('weak', 'price-two', $options, quantity: $quantity),
    };
};
PHP);
        try {
            $consumer = require $path;
            $this->expectException(ValidationException::class);
            try {
                $consumer($this->team, $subscription, $mode, $value);
            } finally {
                self::assertSame(0, Operation::count());
                self::assertCount(0, $http->requests);
            }
        } finally {
            unlink($path);
        }
    }

    public static function weakQuantities(): array
    {
        $cases = [];
        foreach (['builder_quantity', 'builder_add_price', 'update_quantity', 'add_item', 'swap_override'] as $mode) {
            foreach ([1.9, '3', null] as $value) {
                if ($mode === 'swap_override' && $value === null) {
                    continue; // The documented null sentinel preserves observed quantity.
                }
                $cases[] = [$mode, $value];
            }
        }

        return $cases;
    }

    public function test_switching_to_automatic_collection_requires_effective_owned_source(): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $http = $this->fake($this->observation($remote, [$item]));
        try {
            $subscription->updateQuantity('switch-missing-source', 3, ['default_proration_mode' => 'NONE', 'collection_method' => 'AUTO_CHARGE']);
            self::fail('Automatic collection was enabled without source proof.');
        } catch (EvidenceException) {
            self::assertSame('failed', Operation::first()->status);
            self::assertCount(4, $http->requests);
        }
    }

    public function test_switching_collection_mode_verifies_retained_source_without_reemitting_it(): void
    {
        $remote = Billing::subscription(['payment_source_id' => 'source-test']);
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $changed = Billing::subscription(['collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:01:00Z']);
        $http = $this->fake([...$this->observation($remote, [$item]), $this->source(), FakeHttpClient::json($changed), ...$this->observation($changed, [Billing::item(['quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z'])])]);
        $subscription->updateQuantity('switch', 3, ['default_proration_mode' => 'NONE', 'collection_method' => 'AUTO_CHARGE']);
        self::assertSame('/api/v1/billing/payment_sources/source-test', $http->requests[4]->getUri()->getPath());
        self::assertSame('AUTO_CHARGE', self::body($http, 5)['collection_method']);
        self::assertArrayNotHasKey('payment_source_id', self::body($http, 5));
    }

    #[DataProvider('unknownCollectionModes')]
    public function test_item_changes_reject_unknown_or_cleared_effective_collection_semantics(mixed $observed, array $options): void
    {
        $remote = Billing::subscription(['collection_method' => $observed]);
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $http = $this->fake($this->observation($remote, [$item]));
        $this->expectException(EvidenceException::class);
        try {
            $subscription->updateQuantity('unknown-collection', 3, ['default_proration_mode' => 'NONE', ...$options]);
        } finally {
            self::assertSame('failed', Operation::first()->status);
            self::assertNull(Operation::first()->lock_key);
            self::assertCount(4, $http->requests);
        }
    }

    public static function unknownCollectionModes(): array
    {
        return [['OUT_OF_BAND', ['collection_method' => null]], ['FUTURE_COLLECTION', []], [null, []]];
    }

    #[DataProvider('unconfirmedOutcomes')]
    public function test_recorded_response_does_not_confirm_a_missing_direct_action_outcome(string $kind): void
    {
        $remote = Billing::subscription(['payment_source_id' => 'source-old']);
        $subscription = $this->seedSubscription($remote);
        $unconfirmed = Billing::subscription(['payment_source_id' => 'source-old', 'updated_at' => '2026-11-10T12:01:00Z']);
        $confirmed = match ($kind) {
            'cancel' => Billing::subscription(['status' => 'CANCELLED', 'payment_source_id' => 'source-old', 'updated_at' => '2026-11-10T12:02:00Z']),
            'ending' => Billing::subscription(['cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z', 'payment_source_id' => 'source-old', 'updated_at' => '2026-11-10T12:02:00Z']),
            'source' => Billing::subscription(['payment_source_id' => 'source-test', 'updated_at' => '2026-11-10T12:02:00Z']),
        };
        $responses = [...$this->observation($remote)];
        if ($kind === 'source') {
            $responses[] = $this->source();
        }
        $http = $this->fake([...$responses, FakeHttpClient::json(['id' => 'sub-test']), ...$this->observation($unconfirmed), ...$this->observation($unconfirmed), ...$this->observation($confirmed)]);
        $call = fn () => match ($kind) {
            'cancel' => $subscription->cancelNow('outcome', 'NONE'),
            'ending' => $subscription->cancelAtPeriodEnd('outcome'),
            'source' => $subscription->updatePaymentSource('outcome', 'source-test'),
        };
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $call();
                self::fail('A missing canonical action outcome was declared complete.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', Operation::first()->status);
                self::assertSame('sub-test', Operation::first()->remote_id);
                self::assertNotNull(Operation::first()->lock_key);
            }
        }
        $result = $call();
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame($confirmed['status'], $result->status);
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    public static function unconfirmedOutcomes(): array
    {
        return [['cancel'], ['ending'], ['source']];
    }

    public function test_schedule_confirmation_does_not_use_an_old_retained_optional_end(): void
    {
        $remote = Billing::subscription(['ends_at' => '2027-01-01T00:00:00Z']);
        $subscription = $this->seedSubscription($remote);
        $noBound = Billing::subscription(['cancel_at_period_end' => true, 'updated_at' => '2026-11-10T12:01:00Z']);
        $confirmed = Billing::subscription(['cancel_at_period_end' => true, 'current_period_ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:02:00Z']);
        $this->fake([...$this->observation($remote), FakeHttpClient::json(['id' => 'sub-test']), ...$this->observation($noBound), ...$this->observation($confirmed)]);
        try {
            $subscription->cancelAtPeriodEnd('ending');
            self::fail('An old retained date proved a new schedule.');
        } catch (AmbiguousOperationException) {
            self::assertSame('2027-01-01T00:00:00Z', $subscription->fresh()->ends_at);
            self::assertSame('ambiguous', Operation::first()->status);
        }
        $result = $subscription->cancelAtPeriodEnd('ending');
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame('2027-01-01T00:00:00Z', $result->ends_at);
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        self::assertFalse($result->valid());
    }

    public function test_postwrite_and_retry_routing_conflicts_keep_recorded_action_ambiguous(): void
    {
        $remote = Billing::subscription();
        $subscription = $this->seedSubscription($remote);
        $foreign = Billing::subscription(['linked_payment_account_id' => 'foreign', 'updated_at' => '2026-11-10T12:01:00Z']);
        $owned = Billing::subscription(['updated_at' => '2026-11-10T12:02:00Z']);
        $http = $this->fake([...$this->observation($remote), $this->source(), FakeHttpClient::json(['id' => 'sub-test']), ...$this->observation($foreign), ...$this->observation($foreign), ...$this->observation($owned)]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $subscription->updatePaymentSource('source', 'source-test');
                self::fail('A foreign route confirmed a financial action.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', Operation::first()->status);
                self::assertNotNull(Operation::first()->lock_key);
            }
        }
        // Routing alone is insufficient: the intended source is also required.
        try {
            $subscription->updatePaymentSource('source', 'source-test');
            self::fail('Absent intended source was accepted.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::first()->status);
        }
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    #[DataProvider('bundledControls')]
    public function test_bundled_item_controls_require_canonical_confirmation_on_read_only_retry(array $options, array $initial, array $missing, array $confirmed): void
    {
        $remote = Billing::subscription(['payment_source_id' => 'source-old', ...$initial]);
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $unconfirmed = array_replace($remote, $missing, ['updated_at' => '2026-11-10T12:01:00Z']);
        $complete = array_replace($remote, $confirmed, ['updated_at' => '2026-11-10T12:02:00Z']);
        $newItem = Billing::item(['quantity' => '3', 'updated_at' => '2026-11-10T12:01:00Z']);
        $responses = $this->observation($remote, [$item]);
        if (isset($options['payment_source_id']) || ($options['collection_method'] ?? null) === 'AUTO_CHARGE') {
            $responses[] = $this->source();
        }
        $http = $this->fake([...$responses, FakeHttpClient::json(['id' => 'sub-test']), ...$this->observation($unconfirmed, [$newItem]), ...$this->observation($unconfirmed, [$newItem]), ...$this->observation($complete, [$newItem])]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                if ($attempt === 0) {
                    $subscription->updateItems('bundle', [['id' => 'item-test', 'quantity' => 3]], ['default_proration_mode' => 'NONE', ...$options]);
                } else {
                    app(SubscriptionManager::class)->reconcileOperation(Operation::first());
                }
                self::fail('A bundled control without canonical proof was declared complete.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', Operation::first()->status);
                self::assertNotNull(Operation::first()->lock_key);
                self::assertSame('sub-test', Operation::first()->remote_id);
            }
        }
        $result = app(SubscriptionManager::class)->reconcileOperation(Operation::first());
        self::assertSame('succeeded', Operation::first()->status);
        foreach ($options as $field => $expected) {
            self::assertSame($expected, $result->snapshot[$field]);
        }
        self::assertSame(1, count(array_filter(array_slice($http->requests, 1), fn ($request) => $request->getMethod() === 'POST')));
    }

    public static function bundledControls(): array
    {
        return [
            [['payment_source_id' => 'source-test'], [], ['payment_source_id' => 'source-old'], ['payment_source_id' => 'source-test']],
            [['cancel_at_period_end' => true], [], ['cancel_at_period_end' => false], ['cancel_at_period_end' => true, 'current_period_ends_at' => '2026-12-01T00:00:00Z']],
            [['cancel_at_period_end' => false], ['cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z'], ['cancel_at_period_end' => true], ['cancel_at_period_end' => false]],
            [['collection_method' => 'AUTO_CHARGE'], ['payment_source_id' => 'source-test'], ['collection_method' => 'OUT_OF_BAND'], ['collection_method' => 'AUTO_CHARGE']],
        ];
    }

    public function test_read_only_operation_recovery_never_sends_a_prepared_request(): void
    {
        $remote = Billing::subscription();
        $subscription = $this->seedSubscription($remote);
        $operation = app(OperationManager::class)->prepare($this->customer, 'prepared', 'subscription.cancel', [], ['remote_id' => 'sub-test', 'payload' => ['proration_behavior' => 'NONE']], $subscription);
        $http = $this->fake([]);
        $this->expectException(ConflictException::class);
        try {
            app(SubscriptionManager::class)->reconcileOperation($operation);
        } finally {
            self::assertSame('prepared', $operation->fresh()->status);
            self::assertNotNull($operation->fresh()->lock_key);
            self::assertSame([], $http->requests);
        }
    }

    #[DataProvider('unverifiedClearing')]
    public function test_item_controls_do_not_assume_source_detachment_or_schedule_clear_meaning(array $options): void
    {
        $remote = Billing::subscription();
        $item = Billing::item(['quantity' => '2']);
        $subscription = $this->seedSubscription($remote, [$item]);
        $http = $this->fake($this->observation($remote, [$item]));
        $this->expectException(EvidenceException::class);
        try {
            $subscription->updateItems('clear', [['id' => 'item-test', 'quantity' => 3]], ['default_proration_mode' => 'NONE', ...$options]);
        } finally {
            self::assertSame('failed', Operation::first()->status);
            self::assertNull(Operation::first()->lock_key);
            self::assertCount(4, $http->requests);
        }
    }

    public static function unverifiedClearing(): array
    {
        return [[['payment_source_id' => null]], [['payment_source_id' => '']], [['cancel_at_period_end' => null]]];
    }

    private function seedSubscription(array $remote, array $items = []): Subscription
    {
        $subscription = Subscription::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'customer_id' => $this->customer->id, 'name' => 'default', 'remote_id' => $remote['id'], 'status' => $remote['status'], 'starts_at' => $remote['starts_at'], 'ends_at' => $remote['ends_at'] ?? null, 'provider_created_at' => $remote['created_at'], 'provider_updated_at' => $remote['updated_at'], 'cancel_at_period_end' => $remote['cancel_at_period_end'], 'snapshot' => $remote]);
        foreach ($items as $item) {
            $subscription->items()->create(['remote_id' => $item['id'], 'price_id' => $item['price']['id'] ?? null, 'quantity' => $item['quantity'] ?? null, 'snapshot' => $item, 'provider_created_at' => $item['created_at'], 'provider_updated_at' => $item['updated_at']]);
        }
        SubscriptionSlot::create(['customer_id' => $this->customer->id, 'name' => 'default', 'subscription_id' => $subscription->id]);

        return $subscription;
    }

    private function fake(array $responses): FakeHttpClient
    {
        app('cache')->flush();
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }

    private function observation(array $remote, array $items = []): array
    {
        return [FakeHttpClient::json($remote), Billing::page($items), FakeHttpClient::json($remote)];
    }

    private function source(array $overrides = []): ResponseInterface
    {
        return FakeHttpClient::json(array_replace(['id' => 'source-test', 'billing_customer_id' => 'customer-test', 'linked_payment_account_id' => 'collector-test', 'external_id' => 'method-test'], $overrides));
    }

    private static function body(FakeHttpClient $http, int $index): array
    {
        return json_decode((string) $http->requests[$index]->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}

class WorkflowFaultySubscription extends Subscription
{
    public static bool $failNext = false;

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (): void {
            if (self::$failNext) {
                self::$failNext = false;
                throw new RuntimeException('SYNTHETIC-LOCAL-SECRET');
            }
        });
    }
}

class WorkflowFaultyOperation extends Operation
{
    public static bool $failSuccess = false;

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $operation): void {
            if (self::$failSuccess && $operation->status === 'succeeded') {
                self::$failSuccess = false;
                throw new RuntimeException('SYNTHETIC-LOCAL-SECRET');
            }
        });
    }
}
