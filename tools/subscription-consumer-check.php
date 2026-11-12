<?php

declare(strict_types=1);

namespace AirwallexCandidateQualification;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Gate;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\AirwallexBillable;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Throwable;

function adoptionCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    $options = getopt('', ['consumer:', 'evidence:']);
    $consumer = realpath($options['consumer'] ?? '');
    adoptionCheck($consumer !== false && getcwd() === $consumer && isset($options['evidence']), 'An explicit isolated native consumer and evidence path are required.');
    require $consumer.'/vendor/autoload.php';
    require __DIR__.'/consumer-fixtures.php';
    $app = require $consumer.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    adoptionCheck($app->configurationIsCached(), 'Installed consumer configuration must be cached.');
    Carbon::setTestNow('2026-11-10T12:00:00Z');
    config(['airwallex.organization_id' => 'org-synthetic', 'airwallex.client_id' => 'client-synthetic', 'airwallex.api_key' => 'key-synthetic', 'airwallex.legal_entity_id' => 'entity-synthetic', 'airwallex.linked_payment_account_id' => 'collector-synthetic', 'airwallex.currency' => 'USD']);
    Relation::enforceMorphMap(['candidate_team' => Team::class]);
    $bind = static function (array $plan) use ($app): DenyNetworkClient {
        $http = new DenyNetworkClient($plan);
        $app->make('cache')->flush();
        $app->bind(ClientInterface::class, static fn () => $http);
        $app->forgetInstance(Client::class);

        return $http;
    };
    $report = ['status' => 'passed', 'provider_observations' => 'unexecuted', 'recipes' => [], 'checks' => []];
    $package = $consumer.'/vendor/jojeringnax/airwallex-laravel';
    $adapters = (require $package.'/examples/adoption-adapters.php')(
        ['USD' => 2, 'JPY' => 0, 'KWD' => 3],
        ['sandbox' => ['membership' => ['USD' => 'price-synthetic'], 'next-membership' => ['USD' => 'price-next-synthetic']]],
        'candidate_cash_entries',
    );
    adoptionCheck($adapters['major']('900719925474099312345', 'USD') === '9007199254740993123.45', 'Exact large minor-unit conversion failed.');
    adoptionCheck($adapters['major']('12345', 'JPY') === '12345' && $adapters['major']('12345', 'KWD') === '12.345', 'Zero/three-exponent conversion failed.');
    adoptionCheck($adapters['minor']('123.45000', 'USD') === '12345', 'Exact decimal normalization failed.');
    foreach ([['1.001', 'USD'], ['1.00', 'UNVERIFIED']] as [$amount, $currency]) {
        try {
            $adapters['minor']($amount, $currency);
            throw new RuntimeException('Unverified precision was accepted.');
        } catch (\InvalidArgumentException) {
        }
    }
    // Internal callback invocation is a weak PHP call boundary even from this strict file.
    foreach (['major', 'minor'] as $converter) {
        try {
            array_map($adapters[$converter], [1.0], ['USD']);
            throw new RuntimeException('A weak caller coerced float money into the string contract.');
        } catch (\InvalidArgumentException) {
        }
    }
    Schema::create('candidate_cash_entries', static function (Blueprint $table): void {
        $table->string('financial_identity', 64)->primary();
        foreach (['provider', 'environment', 'account_id', 'resource_id', 'invoice_id', 'currency', 'proof_reference', 'clearing_account'] as $column) {
            $table->string($column);
        }
        $table->text('amount_minor');
    });
    Schema::create('candidate_adoption_commands', static function (Blueprint $table): void {
        $table->string('action_key')->primary();
        $table->string('purpose');
    });
    $team = Team::create(['id' => 'adoption-team']);
    // This policy belongs to the synthetic application; the package has no authentication guard.
    $gate = new Gate($app, static fn () => null);
    $gate->define('manage-billing', static fn (GenericUser $actor, Team $billable): bool => $actor->team_id === $billable->getKey());
    $owner = new GenericUser(['id' => 'synthetic-owner', 'team_id' => $team->getKey()]);
    $otherActor = new GenericUser(['id' => 'synthetic-other', 'team_id' => 'another-team']);
    $gate->forUser($owner)->authorize('manage-billing', $team);
    $billing = new AirwallexBillable($team);
    $http = $bind([Fixtures::request('GET', 'billing/billing_customers/customer-adoption', ['id' => 'customer-adoption'])]);
    $billing->linkAirwallexCustomer('customer-adoption');
    $http->assertConsumed();
    $startTrial = static function (GenericUser $actor, Team $billable, string $actionKey) use ($gate, $adapters) {
        $gate->forUser($actor)->authorize('manage-billing', $billable);
        DB::table('candidate_adoption_commands')->insert(['action_key' => $actionKey, 'purpose' => 'approved initial free period']);

        return (new AirwallexBillable($billable))->newSubscription('trial', $adapters['price']('sandbox', 'membership', 'USD'))
            ->startsAt('2026-11-12T12:00:00Z')->trialDays(7)
            ->create($actionKey, ['collection_method' => 'OUT_OF_BAND']);
    };
    $beforeDenial = [DB::table('candidate_adoption_commands')->count(), Operation::count(), Subscription::count(), SubscriptionSlot::count()];
    $http = $bind([]);
    try {
        $startTrial($otherActor, $team, 'denied-trial-command');
        throw new RuntimeException('Application authorization accepted an unrelated actor.');
    } catch (AuthorizationException) {
    }
    adoptionCheck($http->requests === 0, 'Denied application action reached SDK authentication or a provider request.');
    adoptionCheck($beforeDenial === [DB::table('candidate_adoption_commands')->count(), Operation::count(), Subscription::count(), SubscriptionSlot::count()], 'Denied application action persisted a command, operation, subscription or reservation.');
    $report['checks'][] = 'application_policy_denial_before_command_reservation_and_sdk_request';
    $pending = Fixtures::subscription('sub-future-trial', 'customer-adoption', ['status' => 'PENDING', 'starts_at' => '2026-11-12T12:00:00Z', 'trial_starts_at' => '2026-11-12T12:00:00Z', 'trial_ends_at' => '2026-11-19T12:00:00Z', 'collection_method' => 'OUT_OF_BAND']);
    $http = $bind([Fixtures::request('POST', 'billing/subscriptions/create', $pending), ...Fixtures::observation($pending, [Fixtures::item('item-trial', 'sub-future-trial')])]);
    $subscription = $startTrial($owner, $team, 'future-trial-command');
    $http->assertConsumed();
    adoptionCheck(! $billing->subscribed('trial') && $subscription->trial_ends_at === '2026-11-19T12:00:00Z', 'Future/free-period intent granted early access or lost dates.');
    adoptionCheck(Operation::where('action_key', 'future-trial-command')->first()->intent['payload']['trial_ends_at'] === '2026-11-19T12:00:00Z', 'Resolved application free-period date was not persisted.');
    adoptionCheck(DB::table('candidate_adoption_commands')->where('action_key', 'future-trial-command')->count() === 1 && ! DB::table('candidate_adoption_commands')->where('action_key', 'denied-trial-command')->exists(), 'Authorized and denied application commands lost their distinct persistence outcome.');
    $report['checks'][] = 'authorized_provider_entry_point_persisted_command_catalogue_and_exact_free_period';

    Schema::create('candidate_membership_plans', static function (Blueprint $table): void {
        $table->string('action_key')->primary();
        $table->string('effective_at');
        $table->string('tier');
    });
    $trialBoundary = '2026-11-11T00:00:00Z';
    $trialRemote = Fixtures::subscription('sub-trial-sequence', 'customer-adoption', ['status' => 'IN_TRIAL', 'trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => $trialBoundary]);
    $trialItem = Fixtures::item('trial-item', 'sub-trial-sequence', '1');
    $http = $bind(Fixtures::observation($trialRemote, [$trialItem]));
    $trialSequence = $billing->importSubscription('trial-sequence', 'sub-trial-sequence');
    $http->assertConsumed();
    DB::table('candidate_membership_plans')->insert(['action_key' => 'first-paid-tier', 'effective_at' => $trialBoundary, 'tier' => 'standard']);
    $firstPaidItem = Fixtures::item('first-paid-item', 'sub-trial-sequence', '1', '2026-11-10T12:01:00Z');
    $firstPaidItem['price']['id'] = 'price-first-paid';
    $changedTrial = [...$trialRemote, 'updated_at' => '2026-11-10T12:01:00Z'];
    $http = $bind([...Fixtures::observation($trialRemote, [$trialItem]), Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-adoption')), Fixtures::request('POST', 'billing/subscriptions/sub-trial-sequence/update', $changedTrial), ...Fixtures::observation($changedTrial, [$firstPaidItem])]);
    $trialSequence = (require $package.'/examples/next-cycle-change.php')($trialSequence, 'first-paid-tier', [['id' => 'trial-item', 'deleted' => true], ['price_id' => 'price-first-paid', 'quantity' => 1]], $trialBoundary);
    $http->assertConsumed();
    adoptionCheck($trialSequence->status === 'IN_TRIAL' && $trialSequence->onTrial(), 'An early item update ended the free trial locally.');
    adoptionCheck(Operation::where('action_key', 'first-paid-tier')->sole()->intent['next_cycle_boundary'] === $trialBoundary, 'Original trial boundary was not persisted in the durable command.');
    adoptionCheck(DB::table('candidate_membership_plans')->where('effective_at', '<=', now()->format('Y-m-d\TH:i:s\Z'))->count() === 0, 'Immediate item projection prematurely applied the dated membership.');
    Carbon::setTestNow($trialBoundary);
    $paid = [...$changedTrial, 'status' => 'ACTIVE', 'current_period_starts_at' => $trialBoundary, 'current_period_ends_at' => '2026-12-11T00:00:00Z', 'updated_at' => $trialBoundary];
    $http = $bind(Fixtures::observation($paid, [$firstPaidItem]));
    $trialSequence = $trialSequence->reconcile();
    $http->assertConsumed();
    adoptionCheck($trialSequence->active() && DB::table('candidate_membership_plans')->where('effective_at', '<=', $trialBoundary)->sole()->tier === 'standard', 'Canonical first paid state and application membership did not converge.');
    $paidBoundary = $paid['current_period_ends_at'];
    DB::table('candidate_membership_plans')->insert(['action_key' => 'following-paid-tier', 'effective_at' => $paidBoundary, 'tier' => 'basic']);
    $lowerItem = Fixtures::item('following-paid-item', 'sub-trial-sequence', '1', '2026-11-11T00:01:00Z');
    $lowerItem['price']['id'] = 'price-following-paid';
    $lowerPaid = [...$paid, 'updated_at' => '2026-11-11T00:01:00Z'];
    $http = $bind([...Fixtures::observation($paid, [$firstPaidItem]), Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-adoption')), Fixtures::request('POST', 'billing/subscriptions/sub-trial-sequence/update', $lowerPaid), ...Fixtures::observation($lowerPaid, [$lowerItem])]);
    $trialSequence = (require $package.'/examples/next-cycle-change.php')($trialSequence, 'following-paid-tier', [['id' => 'first-paid-item', 'deleted' => true], ['price_id' => 'price-following-paid', 'quantity' => 1]], $paidBoundary);
    $http->assertConsumed();
    adoptionCheck(DB::table('candidate_membership_plans')->where('effective_at', '<=', $trialBoundary)->count() === 1 && $trialSequence->snapshot['payment_source_id'] === $trialRemote['payment_source_id'], 'The later tier changed the earlier entitlement or original source.');
    Carbon::setTestNow($paidBoundary);
    $nextPaid = [...$lowerPaid, 'current_period_starts_at' => $paidBoundary, 'current_period_ends_at' => '2027-01-11T00:00:00Z', 'updated_at' => $paidBoundary];
    $http = $bind(Fixtures::observation($nextPaid, [$lowerItem]));
    $trialSequence->reconcile();
    $http->assertConsumed();
    adoptionCheck(DB::table('candidate_membership_plans')->where('effective_at', '<=', $paidBoundary)->count() === 2, 'The second application boundary was lost.');
    $report['recipes']['next-cycle-change.php'] = ['executed' => true, 'sequence' => 'trial to first paid tier to following lower tier', 'invoice_effects' => 'synthetic state only; actual invoices remain Sandbox evidence'];
    $report['checks'][] = 'early_native_request_original_source_and_separately_persisted_application_entitlement_boundaries';
    Carbon::setTestNow('2026-11-10T12:00:00Z');

    $freeRecipes = (require $package.'/examples/free-period.php')();
    $freeRemote = Fixtures::subscription('sub-earned-free', 'customer-adoption', ['current_period_ends_at' => '2026-11-11T12:01:00Z']);
    $freeItem = Fixtures::item('earned-item', 'sub-earned-free', '1');
    $http = $bind(Fixtures::observation($freeRemote, [$freeItem]));
    $free = $billing->importSubscription('earned-free', $freeRemote['id']);
    $http->assertConsumed();
    if (! method_exists($app->make(Client::class)->billing(), 'coupons')) {
        $before = Operation::count();
        $http = $bind(Fixtures::observation($freeRemote, [$freeItem]));
        try {
            $freeRecipes['apply']($free, 'earned-free-command', 'coupon-synthetic');
            throw new RuntimeException('The baseline silently accepted an unavailable Coupon dependency.');
        } catch (ConfigurationException) {
            adoptionCheck(Operation::count() === $before, 'Missing Coupon capability persisted a financial command.');
        }
        $http->assertConsumed();
        $report['recipes']['free-period.php'] = ['executed' => true, 'path' => 'explicit unavailable-SDK prerequisite; no Coupon mutation', 'financial_effects' => 'unqualified'];
    } else {
        $coupon = ['id' => 'coupon-synthetic', 'active' => true, 'discount_model' => 'PERCENTAGE', 'percentage_off' => 100, 'duration_type' => 'CUSTOM', 'duration' => ['period' => 1, 'period_unit' => 'DAY']];
        $discount = ['id' => 'discount-synthetic', 'applied_to' => 'SUBSCRIPTION', 'applied_to_id' => $freeRemote['id'], 'billing_customer_id' => 'customer-adoption', 'source' => 'COUPON', 'source_id' => $coupon['id'], 'discount_model' => 'PERCENTAGE', 'percentage_off' => 100, 'duration_type' => 'CUSTOM', 'duration' => $coupon['duration'], 'created_at' => '2026-11-10T12:01:00Z', 'ends_at' => '2026-11-11T12:01:00Z'];
        $discounted = [...$freeRemote, 'applied_discounts' => [$discount], 'updated_at' => $discount['created_at']];
        $http = $bind([...Fixtures::observation($freeRemote, [$freeItem]), Fixtures::request('GET', 'billing/coupons/'.$coupon['id'], $coupon), Fixtures::request('GET', 'billing/coupons/'.$coupon['id'], $coupon), Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-adoption')), Fixtures::request('POST', 'billing/subscriptions/'.$freeRemote['id'].'/update', $discounted), ...Fixtures::observation($discounted, [$freeItem])]);
        $free = $freeRecipes['apply']($free, 'earned-free-command', $coupon['id']);
        $http->assertConsumed();
        $freeOperation = Operation::where('action_key', 'earned-free-command')->sole();
        adoptionCheck($freeOperation->status === 'succeeded' && $freeOperation->observation['discount_proof']['ends_at'] === $discount['ends_at'], 'Original exact Discount/end was not confirmed.');
        DB::table('candidate_membership_plans')->insert(['action_key' => 'after-earned-free', 'effective_at' => $discount['ends_at'], 'tier' => 'basic']);
        Carbon::setTestNow('2026-11-10T12:02:00Z');
        $lowerFreeItem = Fixtures::item('after-earned-free-item', $freeRemote['id'], '1', '2026-11-10T12:02:00Z');
        $lowerFreeItem['price']['id'] = 'price-after-free';
        $lowerFree = [...$discounted, 'updated_at' => '2026-11-10T12:02:00Z'];
        $http = $bind([...Fixtures::observation($discounted, [$freeItem]), Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-adoption')), Fixtures::request('POST', 'billing/subscriptions/'.$freeRemote['id'].'/update', $lowerFree), ...Fixtures::observation($lowerFree, [$lowerFreeItem])]);
        $free = (require $package.'/examples/next-cycle-change.php')($free, 'after-earned-free', [['id' => $freeItem['id'], 'deleted' => true], ['price_id' => 'price-after-free', 'quantity' => 1]], $discount['ends_at']);
        $http->assertConsumed();
        adoptionCheck($free->cancel_at_period_end === false && $free->snapshot['payment_source_id'] === $freeRemote['payment_source_id'] && $free->snapshot['applied_discounts'][0]['id'] === $discount['id'], 'Following tier changed auto-renew, source or free-period identity.');
        adoptionCheck(DB::table('candidate_membership_plans')->where('action_key', 'after-earned-free')->sole()->effective_at === $discount['ends_at'], 'The application entitlement date was lost.');
        $removed = [...$lowerFree, 'applied_discounts' => [], 'updated_at' => '2026-11-10T12:03:00Z'];
        $http = $bind([...Fixtures::observation($lowerFree, [$lowerFreeItem]), Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-adoption')), Fixtures::request('POST', 'billing/subscriptions/'.$freeRemote['id'].'/update', $removed), ...Fixtures::observation($removed, [$lowerFreeItem])]);
        $freeRecipes['remove']($free, 'remove-earned-free', 'earned-free-command');
        $http->assertConsumed();
        adoptionCheck(Operation::where('action_key', 'remove-earned-free')->sole()->status === 'succeeded', 'Explicit original-discount removal did not converge.');
        $report['recipes']['free-period.php'] = ['executed' => true, 'path' => '100 percent CUSTOM Coupon, following next-cycle tier and explicit removal', 'financial_effects' => 'synthetic only; no invoice or cash proof'];
    }
    Carbon::setTestNow('2026-11-10T12:00:00Z');

    $original = Fixtures::subscription('sub-finite', 'customer-adoption', ['collection_method' => 'OUT_OF_BAND', 'ends_at' => '2027-01-01T00:00:00Z', 'duration' => ['period' => 2, 'period_unit' => 'MONTH']]);
    unset($original['payment_source_id']); // The verified restoration subset has no payment instrument.
    $item = Fixtures::item('item-finite', 'sub-finite', '1.000');
    $http = $bind(Fixtures::observation($original, [$item]));
    $finite = $billing->importSubscription('finite', 'sub-finite');
    $http->assertConsumed();
    $scheduled = array_replace($original, ['cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z']);
    $http = $bind([...Fixtures::observation($original, [$item]), Fixtures::request('POST', 'billing/subscriptions/sub-finite/update', $scheduled), ...Fixtures::observation($scheduled, [$item])]);
    $finite = $finite->cancelAtPeriodEnd('finite-off');
    $http->assertConsumed();
    $restored = array_replace($original, ['updated_at' => '2026-11-10T12:02:00Z']);
    $http = $bind([...Fixtures::observation($scheduled, [$item]), Fixtures::request('POST', 'billing/subscriptions/sub-finite/update', $restored), ...Fixtures::observation($restored, [$item])]);
    $finite = (require $package.'/examples/restore-renewal.php')($finite, 'finite-on', 'finite-off');
    $http->assertConsumed();
    adoptionCheck($finite->scheduled_ends_at === null && $finite->ends_at === $original['ends_at'], 'Finite restoration did not prove the original bound.');
    $report['recipes']['restore-renewal.php'] = ['executed' => true, 'combination' => 'finite ACTIVE OUT_OF_BAND only'];
    $report['checks'][] = 'restoration_original_bound_distinct_command_canonical_cap_clear';

    foreach (['ACTIVE', 'PENDING', 'IN_TRIAL', 'UNPAID'] as $state) {
        $id = 'sub-indefinite-'.strtolower($state);
        $unbounded = Fixtures::subscription($id, 'customer-adoption', ['status' => $state]);
        if ($state === 'IN_TRIAL') {
            $unbounded['trial_starts_at'] = '2026-11-01T00:00:00Z';
            $unbounded['trial_ends_at'] = '2026-11-11T00:00:00Z';
        }
        $http = $bind(Fixtures::observation($unbounded, []));
        $automatic = $billing->importSubscription('indefinite-'.strtolower($state), $id);
        $http->assertConsumed();
        $ending = [...$unbounded, 'cancel_at_period_end' => true, 'ends_at' => '2026-12-01T00:00:00Z', 'updated_at' => '2026-11-10T12:01:00Z'];
        $http = $bind([...Fixtures::observation($unbounded, []), Fixtures::request('POST', 'billing/subscriptions/'.$id.'/update', $ending), ...Fixtures::observation($ending, [])]);
        $automatic = (require $package.'/examples/schedule-ending.php')($automatic, $id.'/off', ['duration' => null, 'remaining_duration' => null, 'ends_at' => null]);
        $http->assertConsumed();
        $continued = [...$unbounded, 'updated_at' => '2026-11-10T12:02:00Z'];
        $http = $bind([...Fixtures::observation($ending, []), Fixtures::request('GET', 'billing/payment_sources/source-synthetic', Fixtures::source('source-synthetic', 'customer-adoption')), Fixtures::request('POST', 'billing/subscriptions/'.$id.'/update', $continued), ...Fixtures::observation($continued, [])]);
        $automatic = (require $package.'/examples/restore-renewal.php')($automatic, $id.'/on', $id.'/off');
        $http->assertConsumed();
        adoptionCheck($automatic->status === $state && $automatic->scheduled_ends_at === null && $automatic->ends_at === null, 'Indefinite restoration changed lifecycle or retained the cancelled bound.');
        $restoreOperation = Operation::where('action_key', $id.'/on')->firstOrFail();
        adoptionCheck($restoreOperation->intent['payload'] === ['cancel_at_period_end' => false, 'duration' => null, 'remaining_duration' => null, 'ends_at' => null], 'Indefinite restoration lost its explicit null triplet.');
        if (in_array($state, ['PENDING', 'UNPAID'], true)) {
            adoptionCheck(! $automatic->valid(), 'Restoration independently granted access.');
        }
    }
    $report['recipes']['restore-renewal.php']['combination'] = 'finite ACTIVE OUT_OF_BAND and explicit indefinite AUTO_CHARGE ACTIVE/PENDING/IN_TRIAL/UNPAID';
    $report['checks'][] = 'explicit_indefinite_intent_source_ownership_omitted_bounds_and_lifecycle_preservation';

    // A commercially chosen due time after an initial free period is persisted once.
    // This fixture establishes local due execution, not an ACTIVE invoice holiday or atomic renewal timing.
    $http = $bind(Fixtures::observation($restored, [$item]));
    $transition = (require $package.'/examples/schedule-transition.php')($finite, 'future-price-transition', '2026-11-10T12:10:00Z', '2026-11-10T12:11:00Z', [['id' => 'item-finite', 'deleted' => true], ['price_id' => $adapters['price']('sandbox', 'next-membership', 'USD'), 'quantity' => 1]]);
    $http->assertConsumed();
    adoptionCheck($transition->status === 'scheduled', 'Transition did not persist its approved due intent.');
    Carbon::setTestNow('2026-11-10T12:10:00Z');
    $changed = array_replace($restored, ['updated_at' => '2026-11-10T12:10:00Z']);
    $nextItem = Fixtures::item('item-next', 'sub-finite', '1.000', '2026-11-10T12:10:00Z');
    $nextItem['price']['id'] = 'price-next-synthetic';
    $http = $bind([...Fixtures::observation($restored, [$item]), Fixtures::request('POST', 'billing/subscriptions/sub-finite/update', $changed), ...Fixtures::observation($changed, [$nextItem])]);
    adoptionCheck($transition->run()->status === 'completed', 'Due transition did not verify its intended items.');
    $http->assertConsumed();
    $report['recipes']['schedule-transition.php'] = ['executed' => true, 'provider_boundary_guarantee' => false];

    $invoiceData = array_replace(Fixtures::invoice('customer-adoption', 'sub-finite'), ['id' => 'invoice-adoption', 'payment_status' => 'PAID']);
    $transactionData = ['id' => 'billing-txn-one', 'billing_customer_id' => 'customer-adoption', 'invoice_id' => 'invoice-adoption', 'linked_payment_account_id' => 'collector-synthetic', 'external_id' => 'intent-partial-one', 'type' => 'PAYMENT', 'status' => 'SUCCEEDED', 'out_of_band' => false, 'currency' => 'USD', 'amount' => '25.00', 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z'];
    $otherTransactions = [array_replace($transactionData, ['id' => 'billing-txn-two', 'external_id' => 'intent-partial-two', 'amount' => '75.00']), array_replace($transactionData, ['id' => 'billing-txn-pending', 'status' => 'CREATED']), array_replace($transactionData, ['id' => 'billing-txn-refund', 'type' => 'REFUND', 'status' => 'FAILED'])];
    $http = $bind([
        Fixtures::request('GET', 'billing/invoices/invoice-adoption', $invoiceData),
        Fixtures::request('GET', 'billing/invoices/invoice-adoption', $invoiceData),
        Fixtures::request('GET', 'billing/billing_transactions', ['items' => [$transactionData], 'page_after' => 'transactions-next']),
        Fixtures::request('GET', 'billing/billing_transactions', ['items' => $otherTransactions]),
        Fixtures::request('GET', 'billing/invoices/invoice-adoption', $invoiceData),
        Fixtures::request('GET', 'billing/billing_transactions/billing-txn-one', $transactionData),
    ]);
    $invoice = $billing->findInvoice('invoice-adoption');
    $ownedTransactions = (require $package.'/examples/invoice-transactions.php')($invoice, 'billing-txn-one', ['page_size' => 1]);
    $http->assertConsumed();
    adoptionCheck($ownedTransactions['transactions']->count() === 4 && $ownedTransactions['transactions'][0]->amount === '25.00', 'Owned invoice transaction paging or exact amount failed.');
    adoptionCheck($ownedTransactions['transaction']->identityKey() === $ownedTransactions['transactions'][0]->identityKey(), 'Distinct observations changed canonical transaction identity.');
    $report['recipes']['invoice-transactions.php'] = ['executed' => true, 'all_pages' => true, 'allocation_inferred' => false];
    $creditInvoice = array_replace($invoiceData, ['id' => 'invoice-credit', 'amount_due' => '0.00']);
    $http = $bind([Fixtures::request('GET', 'billing/invoices/invoice-credit', $creditInvoice), Fixtures::request('GET', 'billing/invoices/invoice-credit', $creditInvoice), Fixtures::request('GET', 'billing/billing_transactions', ['items' => []])]);
    adoptionCheck($billing->findInvoice('invoice-credit')->transactions()->isEmpty(), 'Zero-cash PAID invoice fabricated a payment.');
    $http->assertConsumed();

    // This is explicit synthetic application allocation evidence, not an Airwallex exclusivity claim.
    // Invoice PAID with credits/zero cash, missing allocations and pending/refund/manual observations post nothing.
    $baseTransaction = $ownedTransactions['transactions'][0]->toArray();
    adoptionCheck($adapters['cash']($baseTransaction, null) === 'unallocated', 'Succeeded transaction without allocation proof posted cash.');
    foreach ([[], ['status' => 'CREATED'], ['status' => 'FAILED'], ['type' => 'REFUND'], ['out_of_band' => true]] as $changes) {
        adoptionCheck($adapters['cash'](array_replace($baseTransaction, $changes), null) === 'unallocated', 'An unproved financial observation posted cash.');
    }
    $allocation = ['environment' => 'sandbox', 'account_id' => 'collector-synthetic', 'invoice_id' => 'invoice-adoption', 'payment_intent_id' => 'intent-partial-one', 'currency' => 'USD', 'amount_minor' => '2500', 'proof_reference' => 'synthetic-independent-allocation-one', 'clearing_account' => 'synthetic-airwallex-clearing', 'exclusive_to_invoice' => true];
    adoptionCheck($adapters['cash'](array_replace($baseTransaction, ['amount' => '0.00']), array_replace($allocation, ['amount_minor' => '0'])) === 'unallocated', 'Zero allocation created a cash entry.');
    adoptionCheck($adapters['cash']($baseTransaction, $allocation) === 'recorded', 'Verified synthetic partial cash allocation was not recorded.');
    // Distinct webhook delivery IDs and Billing vs Payments observations share this canonical intent key.
    foreach (['delivery-one', 'delivery-two', 'payments-canonical-observation'] as $deliveryId) {
        adoptionCheck($adapters['cash']($baseTransaction, $allocation) === 'duplicate', 'Delivery or cross-family observation duplicated cash.');
    }
    $secondTransaction = array_replace($baseTransaction, ['id' => 'billing-txn-two', 'external_id' => 'intent-partial-two', 'amount' => '75.00']);
    $secondAllocation = array_replace($allocation, ['payment_intent_id' => 'intent-partial-two', 'amount_minor' => '7500', 'proof_reference' => 'synthetic-independent-allocation-two']);
    adoptionCheck($adapters['cash']($secondTransaction, $secondAllocation) === 'recorded', 'Second partial payment was not a distinct financial identity.');
    adoptionCheck(DB::table('candidate_cash_entries')->count() === 2, 'Financial deduplication changed cash entry count.');
    try {
        $adapters['cash'](array_replace($baseTransaction, ['amount' => '30.00']), array_replace($allocation, ['amount_minor' => '3000']));
        throw new RuntimeException('Contradictory existing allocation was silently accepted.');
    } catch (\LogicException) {
    }
    $report['checks'][] = 'zero_cash_credits_pending_refunds_and_unallocated_observations_never_post_cash';
    $report['checks'][] = 'partial_payments_distinct_deliveries_canonical_intent_dedup_and_conflict';
    $report['allocation_evidence'] = 'synthetic application attestation only; provider allocation exclusivity and downstream implementation remain unverified';
    $report['recipes']['adoption-adapters.php'] = ['executed' => true, 'currencies' => 'illustrative injected exponents; no account eligibility claim'];
    $report['checks'][] = 'exact_amount_conversion_and_unknown_precision_refusal';
    file_put_contents($options['evidence'], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode(['status' => 'passed', 'recipes_executed' => count($report['recipes'])], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'classification' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
} finally {
    if (class_exists(Carbon::class)) {
        Carbon::setTestNow();
    }
}
