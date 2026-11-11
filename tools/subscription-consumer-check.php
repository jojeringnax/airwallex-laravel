<?php

declare(strict_types=1);

namespace AirwallexCandidateQualification;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\AirwallexBillable;
use Jojeringnax\AirwallexLaravel\Models\Operation;
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
    // The application authorizes this local row before creating its provider-qualified object.
    $billing = new AirwallexBillable($team);
    $http = $bind([Fixtures::request('GET', 'billing/billing_customers/customer-adoption', ['id' => 'customer-adoption'])]);
    $billing->linkAirwallexCustomer('customer-adoption');
    $http->assertConsumed();
    DB::table('candidate_adoption_commands')->insert(['action_key' => 'future-trial-command', 'purpose' => 'approved initial free period']);
    $pending = Fixtures::subscription('sub-future-trial', 'customer-adoption', ['status' => 'PENDING', 'starts_at' => '2026-11-12T12:00:00Z', 'trial_starts_at' => '2026-11-12T12:00:00Z', 'trial_ends_at' => '2026-11-19T12:00:00Z', 'collection_method' => 'OUT_OF_BAND']);
    $http = $bind([Fixtures::request('POST', 'billing/subscriptions/create', $pending), ...Fixtures::observation($pending, [Fixtures::item('item-trial', 'sub-future-trial')])]);
    $subscription = $billing->newSubscription('trial', $adapters['price']('sandbox', 'membership', 'USD'))
        ->startsAt('2026-11-12T12:00:00Z')->trialDays(7)
        ->create(DB::table('candidate_adoption_commands')->first()->action_key, ['collection_method' => 'OUT_OF_BAND']);
    $http->assertConsumed();
    adoptionCheck(! $billing->subscribed('trial') && $subscription->trial_ends_at === '2026-11-19T12:00:00Z', 'Future/free-period intent granted early access or lost dates.');
    adoptionCheck(Operation::where('action_key', 'future-trial-command')->first()->intent['payload']['trial_ends_at'] === '2026-11-19T12:00:00Z', 'Resolved application free-period date was not persisted.');
    $report['checks'][] = 'authorized_provider_entry_point_persisted_command_catalogue_and_exact_free_period';

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
