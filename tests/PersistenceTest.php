<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Jojeringnax\AirwallexLaravel\Billable;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionItem;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Models\WebhookReceipt;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;

class PersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        foreach (['local_users', 'local_teams'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                if ($blueprint->getTable() === 'local_users') {
                    $blueprint->increments('id');
                } else {
                    $blueprint->string('id')->primary();
                }
            });
        }
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::tearDown();
    }

    public function test_migrations_are_reversible(): void
    {
        self::assertTrue(Schema::hasTable('airwallex_webhook_receipts'));
        self::assertTrue(Schema::hasTable('airwallex_domain_events'));
        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
        self::assertFalse(Schema::hasTable('airwallex_customers'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        self::assertTrue(Schema::hasTable('airwallex_subscriptions'));
    }

    public function test_mixed_keys_morph_maps_and_configured_models_are_preserved(): void
    {
        Relation::enforceMorphMap(['user' => LocalUser::class, 'team' => Team::class]);
        config(['airwallex.models.customer' => CustomCustomer::class, 'airwallex.models.subscription' => CustomSubscription::class]);
        foreach ([[LocalUser::class, '42'], [Team::class, 'c3401000-1234-4000-a000-123456789012'], [Team::class, '01JQWTT1PNBDGYX7BX5RB8ATXK']] as [$class, $key]) {
            $billable = $class::create(['id' => $key]);
            $customer = app(OperationManager::class)->reserveCustomer($billable);
            self::assertInstanceOf(CustomCustomer::class, $customer);
            self::assertSame($key, $customer->billable_id);
            self::assertSame($key, (string) $customer->billable->getKey());
            self::assertSame($customer->id, $billable->customerMapping()->id);
            $subscription = $this->subscription($customer, 'sub-'.$key);
            self::assertInstanceOf(CustomSubscription::class, $customer->subscriptions()->first());
            self::assertSame($subscription->id, $billable->subscriptions()->first()->id);
        }
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
    }

    public function test_context_and_case_sensitive_identity_constraints(): void
    {
        $a = Customer::create($this->identity('a', 'Cus-A'));
        $b = Customer::create($this->identity('b', 'cus-a'));
        self::assertNotSame($a->id, $b->id);
        Customer::create([...$this->identity('a', 'Cus-A'), 'environment' => 'production']);
        self::assertSame(3, Customer::count());
        $this->expectException(QueryException::class);
        Customer::create($this->identity('c', 'Cus-A'));
    }

    public function test_opaque_identity_preserves_trailing_spaces_and_unicode_codepoints(): void
    {
        $values = ['customer', 'customer ', 'Customer', 'é', "e\u{0301}"];
        foreach ($values as $index => $remote) {
            $customer = Customer::create($this->identity((string) $index, $remote));
            self::assertSame($customer->id, Customer::where('remote_id', $remote)->sole()->id);
        }
        self::assertSame(count($values), Customer::count());
    }

    public function test_full_length_composite_identity_and_large_payloads_roundtrip(): void
    {
        $customer = Customer::create(['environment' => 'production', 'organization_id' => str_repeat('😀', 255), 'billable_type' => str_repeat('😀', 255), 'billable_id' => str_repeat('😀', 255)]);
        self::assertSame(str_repeat('😀', 255), $customer->fresh()->billable_id);
        $large = str_repeat('synthetic', 10000);
        $receipt = WebhookReceipt::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'event_id' => 'large', 'digest' => hash('sha256', $large), 'body' => $large, 'status' => 'pending']);
        self::assertSame($large, $receipt->fresh()->body);
        $ordinary = Customer::create($this->identity('ordinary', 'ordinary'));
        $subscription = $this->subscription($ordinary, 'sub-large');
        $subscription->snapshot = ['large' => $large];
        $subscription->save();
        self::assertSame($large, $subscription->fresh()->snapshot['large']);
        $operation = app(OperationManager::class)->prepare($ordinary, 'large', 'test', [], ['large' => $large]);
        self::assertSame($large, $operation->fresh()->intent['large']);
    }

    public function test_parent_context_is_enforced_by_the_database(): void
    {
        $customer = Customer::create($this->identity('a', 'customer'));
        $this->expectException(QueryException::class);
        Subscription::create(['customer_id' => $customer->id, 'environment' => 'production', 'organization_id' => 'org-test', 'remote_id' => 'sub', 'name' => 'default', 'status' => 'ACTIVE', 'snapshot' => []]);
    }

    public function test_current_slot_cannot_point_at_a_different_subscription_name(): void
    {
        $customer = Customer::create($this->identity('a', 'customer'));
        $subscription = $this->subscription($customer, 'sub');
        $this->expectException(QueryException::class);
        SubscriptionSlot::create(['customer_id' => $customer->id, 'name' => 'support', 'subscription_id' => $subscription->id]);
    }

    public function test_billable_queries_use_the_configured_customer_table(): void
    {
        Schema::rename('airwallex_customers', 'other_airwallex_customers');
        config(['airwallex.models.customer' => CustomTableCustomer::class]);
        $billable = LocalUser::create(['id' => 42]);
        $customer = app(OperationManager::class)->reserveCustomer($billable);
        $subscription = $this->subscription($customer, 'sub');
        self::assertSame($subscription->id, $billable->subscriptions()->first()->id);
    }

    public function test_local_timestamps_are_utc_even_in_a_non_utc_application(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome');
        try {
            $customer = Customer::create($this->identity('a', 'customer'));
            $customer->synced_at = new \DateTimeImmutable('2026-11-10T13:14:15.123456+01:00');
            $customer->save();
            self::assertSame('2026-11-10 12:14:15.123456', $customer->getRawOriginal('synced_at'));
            self::assertSame('2026-11-10T12:14:15.123456+00:00', $customer->fresh()->synced_at->format('Y-m-d\TH:i:s.uP'));
            self::assertSame('UTC', $customer->fresh()->created_at->getTimezone()->getName());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_snapshot_retains_nested_json_object_identity(): void
    {
        $customer = Customer::create($this->identity('a', 'customer'));
        $subscription = $this->subscription($customer, 'sub');
        $snapshot = ['metadata' => new \stdClass, 'list' => [], 'numeric_map' => (object) ['0' => 'zero'], 'nested' => ['quantity' => '1.0000000000000001', 'null' => null, 'false' => false, 'zero' => 0]];
        $subscription->update(['snapshot' => $snapshot]);
        self::assertSame(json_encode($snapshot), json_encode($subscription->fresh()->snapshot));
    }

    public function test_local_primary_identity_cannot_be_changed(): void
    {
        $customer = Customer::create($this->identity('a', 'customer'));
        $this->expectException(OwnershipException::class);
        $customer->update(['id' => (string) Str::ulid()]);
    }

    public function test_changed_in_memory_context_does_not_disguise_a_foreign_persisted_record(): void
    {
        $customer = Customer::create($this->identity('a', 'customer'));
        $customer->environment = 'production';
        $this->expectException(OwnershipException::class);
        (new BillingContext('production', 'org-test'))->assertModel($customer);
    }

    public function test_schedule_migration_backfills_only_owned_raw_bound_proof(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true, '--step' => 2])->assertSuccessful();
        $customer = Customer::create($this->identity('a', 'customer-test'));
        $proven = $this->subscription($customer, 'sub-proof');
        $proven->update(['cancel_at_period_end' => true, 'snapshot' => ['id' => 'sub-proof', 'billing_customer_id' => 'customer-test', 'status' => 'ACTIVE', 'cancel_at_period_end' => true, 'current_period_ends_at' => '2026-12-01T00:00:00.123456789+0000']]);
        $unproven = $this->subscription($customer, 'sub-unproven');
        $unproven->update(['cancel_at_period_end' => true, 'ends_at' => '2027-01-01T00:00:00Z', 'snapshot' => ['id' => 'sub-unproven', 'billing_customer_id' => 'customer-test', 'status' => 'ACTIVE', 'cancel_at_period_end' => true]]);
        $foreign = $this->subscription($customer, 'sub-foreign');
        $foreign->update(['cancel_at_period_end' => true, 'snapshot' => ['id' => 'sub-foreign', 'billing_customer_id' => 'other-customer', 'status' => 'ACTIVE', 'cancel_at_period_end' => true, 'ends_at' => '2027-01-01T00:00:00Z']]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        self::assertSame('2026-12-01T00:00:00.123456789+0000', $proven->fresh()->scheduled_ends_at);
        self::assertNull($unproven->fresh()->scheduled_ends_at);
        self::assertNull($foreign->fresh()->scheduled_ends_at);
    }

    public function test_routing_migration_backfills_only_owned_positive_provider_values(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true, '--step' => 1])->assertSuccessful();
        $customer = Customer::create($this->identity('a', 'customer-test'));
        $proven = $this->subscription($customer, 'sub-proof');
        $proven->update(['snapshot' => ['id' => 'sub-proof', 'billing_customer_id' => 'customer-test', 'linked_payment_account_id' => 'foreign']]);
        $unproven = $this->subscription($customer, 'sub-unproven');
        $unproven->update(['snapshot' => ['id' => 'sub-unproven', 'billing_customer_id' => 'other-customer', 'legal_entity_id' => 'entity-test']]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        self::assertSame(['linked_payment_account_id' => 'foreign'], $proven->fresh()->observed_routing);
        self::assertNull($unproven->fresh()->observed_routing);
        self::assertArrayNotHasKey('legal_entity_id', $proven->fresh()->snapshot);
    }

    public function test_remote_identity_cannot_be_reassigned(): void
    {
        $customer = Customer::create($this->identity('a', 'customer'));
        $this->expectException(OwnershipException::class);
        $customer->update(['remote_id' => 'other']);
    }

    public function test_optional_exact_values_round_trip_without_defaults(): void
    {
        $customer = Customer::create($this->identity('a', null));
        self::assertNull($customer->remote_id);
        $subscription = $this->subscription($customer, 'subscription');
        $timestamp = '2026-11-10T13:14:15.123456789+01:00';
        $subscription->update(['starts_at' => $timestamp, 'snapshot' => ['extra' => '1.000000000000000000001', 'null_field' => null]]);
        $item = SubscriptionItem::create(['subscription_id' => $subscription->id, 'remote_id' => 'item', 'quantity' => '12345678901234567890.123456789', 'price_id' => null, 'snapshot' => ['quantity' => '12345678901234567890.123456789']]);
        self::assertSame($timestamp, $subscription->fresh()->starts_at);
        self::assertNull($subscription->fresh()->ends_at);
        self::assertSame('12345678901234567890.123456789', $item->fresh()->quantity);
        self::assertNull($item->fresh()->price_id);
        self::assertArrayHasKey('null_field', $subscription->fresh()->snapshot);
        self::assertArrayNotHasKey('absent_field', $subscription->fresh()->snapshot);
        self::assertArrayNotHasKey('snapshot', $subscription->toArray());
        self::assertStringNotContainsString('12345678901234567890', print_r($item, true));
    }

    private function identity(string $key, ?string $remote): array
    {
        return ['environment' => 'sandbox', 'organization_id' => 'org-test', 'billable_type' => LocalUser::class, 'billable_id' => $key, 'remote_id' => $remote];
    }

    private function subscription(Customer $customer, string $remote): Subscription
    {
        return ModelRegistry::query('subscription')->create(['customer_id' => $customer->id, 'environment' => $customer->environment, 'organization_id' => $customer->organization_id, 'remote_id' => $remote, 'name' => 'default', 'status' => 'ACTIVE', 'snapshot' => []]);
    }
}

class LocalUser extends Model
{
    use Billable;

    protected $table = 'local_users';

    protected $guarded = [];

    public $timestamps = false;
}

class CustomCustomer extends Customer {}
class CustomSubscription extends Subscription {}

class CustomTableCustomer extends Customer
{
    protected $table = 'other_airwallex_customers';
}
