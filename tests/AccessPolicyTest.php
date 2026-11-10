<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Billable;
use Jojeringnax\AirwallexLaravel\Contracts\SubscriptionAccessPolicy;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;

class AccessPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('states')]
    public function test_local_access_matrix(string $status, array $dates, bool $active, bool $trial, bool $valid, bool $grace): void
    {
        $subscription = new Subscription(['status' => $status, 'starts_at' => '2026-11-01T00:00:00Z', ...$dates]);
        self::assertSame($active, $subscription->active());
        self::assertSame($trial, $subscription->onTrial());
        self::assertSame($valid, $subscription->valid());
        self::assertSame($grace, $subscription->onGracePeriod());
    }

    public static function states(): array
    {
        return [
            'active' => ['ACTIVE', [], true, false, true, false],
            'compact offset' => ['ACTIVE', ['starts_at' => '2026-11-10T13:00:00+0100'], true, false, true, false],
            'unpaid' => ['UNPAID', [], false, false, false, false],
            'pending never promoted' => ['PENDING', [], false, false, false, false],
            'cancelled future end' => ['CANCELLED', ['ends_at' => '2027-01-01T00:00:00Z'], false, false, false, false],
            'future unknown' => ['NEW_STATE', [], false, false, false, false],
            'inclusive start' => ['ACTIVE', ['starts_at' => '2026-11-10T12:00:00Z'], true, false, true, false],
            'future start' => ['ACTIVE', ['starts_at' => '2026-11-10T12:00:00.000000001Z'], false, false, false, false],
            'exclusive end' => ['ACTIVE', ['ends_at' => '2026-11-10T12:00:00Z'], false, false, false, false],
            'nanosecond future end' => ['ACTIVE', ['ends_at' => '2026-11-10T12:00:00.000000001Z'], true, false, true, false],
            'old ordinary rollover' => ['ACTIVE', ['current_period_ends_at' => '2026-11-09T00:00:00Z'], true, false, true, false],
            'scheduled grace' => ['ACTIVE', ['cancel_at_period_end' => true, 'current_period_ends_at' => '2026-11-11T00:00:00Z'], true, false, true, true],
            'scheduled exact end' => ['ACTIVE', ['cancel_at_period_end' => true, 'current_period_ends_at' => '2026-11-10T12:00:00Z'], false, false, false, false],
            'unpaid scheduled' => ['UNPAID', ['cancel_at_period_end' => true, 'ends_at' => '2026-11-11T00:00:00Z'], false, false, false, false],
            'missing scheduled bound' => ['ACTIVE', ['cancel_at_period_end' => true], false, false, false, false],
            'trial' => ['IN_TRIAL', ['trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => '2026-11-11T00:00:00Z'], false, true, true, false],
            'expired trial' => ['IN_TRIAL', ['trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => '2026-11-10T12:00:00Z'], false, false, false, false],
            'missing trial dates' => ['IN_TRIAL', [], false, false, false, false],
            'inconsistent trial start' => ['IN_TRIAL', ['trial_starts_at' => '2026-10-31T00:00:00Z', 'trial_ends_at' => '2026-11-11T00:00:00Z'], false, false, false, false],
            'trial beyond subscription' => ['IN_TRIAL', ['ends_at' => '2026-11-11T00:00:00Z', 'trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => '2026-11-12T00:00:00Z'], false, false, false, false],
            'missing start' => ['ACTIVE', ['starts_at' => null], false, false, false, false],
            'malformed start' => ['ACTIVE', ['starts_at' => 'tomorrow'], false, false, false, false],
            'invalid calendar' => ['ACTIVE', ['starts_at' => '2026-02-30T00:00:00Z'], false, false, false, false],
        ];
    }

    public function test_timestamp_comparison_preserves_offsets_and_arbitrary_fraction(): void
    {
        self::assertSame(0, Timestamp::compare('2026-11-10T13:00:00.123456789+0100', '2026-11-10T12:00:00.123456789Z'));
        self::assertSame(0, Timestamp::compare('2026-11-10T13:00:00.123456789+01:00', '2026-11-10T12:00:00.1234567890Z'));
        self::assertSame(-1, Timestamp::compare('2026-11-10T12:00:00.123456788Z', '2026-11-10T12:00:00.123456789Z'));
    }

    public function test_policy_replacement_does_not_change_lifecycle_helpers(): void
    {
        $this->app->bind(SubscriptionAccessPolicy::class, fn () => new class implements SubscriptionAccessPolicy
        {
            public function allows(Subscription $subscription): bool
            {
                return $subscription->status === 'UNPAID';
            }
        });
        $subscription = new Subscription(['status' => 'UNPAID']);
        self::assertTrue($subscription->valid());
        self::assertFalse($subscription->active());
        self::assertFalse($subscription->onGracePeriod());
    }

    public function test_current_selection_never_restores_historical_access_and_context_isolation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('access_billables', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        $billable = AccessBillable::create(['id' => '01JQWTT1PNBDGYX7BX5RB8ATXK']);
        $customer = Customer::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'billable_type' => AccessBillable::class, 'billable_id' => $billable->id, 'remote_id' => 'customer']);
        $old = $customer->subscriptions()->create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'name' => 'default', 'remote_id' => 'sub-old', 'status' => 'ACTIVE', 'starts_at' => '2026-11-01T00:00:00Z', 'snapshot' => []]);
        self::assertSame($old->id, $billable->subscription('default')->id);
        self::assertFalse($billable->subscribed('default'));
        $new = $customer->subscriptions()->create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'name' => 'default', 'remote_id' => 'sub-new', 'status' => 'UNPAID', 'starts_at' => '2026-11-01T00:00:00Z', 'snapshot' => []]);
        SubscriptionSlot::create(['customer_id' => $customer->id, 'name' => 'default', 'subscription_id' => $new->id]);
        self::assertSame($new->id, $billable->subscription('default')->id);
        self::assertFalse($billable->subscribed('default'));
        $old->update(['provider_updated_at' => '2027-01-01T00:00:00Z']);
        SubscriptionSlot::query()->delete();
        self::assertSame($new->id, $billable->subscription('default')->id);
        config(['airwallex.environment' => 'production']);
        $this->app->forgetScopedInstances();
        self::assertNull($billable->subscription('default'));
        self::assertFalse($billable->subscribed('default'));
    }
}

class AccessBillable extends Model
{
    use Billable;

    protected $table = 'access_billables';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}
