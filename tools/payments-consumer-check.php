<?php

declare(strict_types=1);

namespace AirwallexPaymentsQualification;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Events\PaymentObserved;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Client;
use Jojeringnax\AirwallexLaravel\Payments\Models\Observation;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\ObservationDispatcher;
use Jojeringnax\AirwallexLaravel\Payments\Receipts;
use Laravel\Cashier\Cashier;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Stripe\ApiRequestor;
use Throwable;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

try {
    $options = getopt('', ['consumer:', 'evidence:']);
    $consumer = realpath($options['consumer'] ?? '');
    check($consumer !== false && getcwd() === $consumer && isset($options['evidence']), 'An explicit isolated native consumer and evidence path are required.');
    require $consumer.'/vendor/autoload.php';
    require __DIR__.'/payments-consumer-fixtures.php';
    $app = require $consumer.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    check($app->configurationIsCached(), 'Installed consumer configuration must be cached.');
    Carbon::setTestNow('2026-11-11T12:00:00Z');
    config(['airwallex.payments.account_id' => 'account-synthetic-payments', 'airwallex.payments.client_id' => 'client-synthetic-payments', 'airwallex.payments.api_key' => 'key-synthetic-payments',
        'airwallex.payments.webhook.secret' => 'secret-synthetic-payments', 'airwallex.payments.webhook.version' => '2026-08-21', 'airwallex.payments.events.queue_connection' => 'qualification-hold',
        'queue.connections.qualification-hold' => ['driver' => 'null'], 'cashier.secret' => 'sk_test_synthetic']);
    $http = new PaymentsTransport;
    $app->instance(ClientInterface::class, $http);
    $app->forgetInstance(Client::class);
    Relation::enforceMorphMap(['payments_owner' => PaymentsOwner::class]);
    Cashier::useCustomerModel(PaymentsOwner::class);
    $stripe = new LegacyStripeTransport;
    ApiRequestor::setHttpClient($stripe);
    Schema::create('candidate_payments_owners', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('stripe_id')->nullable();
    });
    Schema::create('candidate_payment_commands', static function (Blueprint $table): void {
        $table->string('action_key')->primary();
        $table->text('amount');
        $table->string('provider');
    });
    Schema::create('candidate_payment_ledger', static function (Blueprint $table): void {
        $table->string('financial_identity', 64)->primary();
        foreach (['provider', 'environment', 'account_id', 'resource_id', 'currency', 'proof_reference', 'clearing_account'] as $column) {
            $table->string($column);
        }
        $table->string('invoice_id')->nullable();
        $table->text('amount_minor');
    });
    Schema::create('candidate_payment_ledger_entries', static function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        foreach (['financial_identity', 'delivery_id', 'kind', 'currency', 'delta_minor'] as $column) {
            $table->string($column);
        }
    });
    Schema::create('candidate_payment_ledger_credit_notes', static function (Blueprint $table): void {
        $table->string('financial_identity', 64)->primary();
        foreach (['refund_id', 'intent_id', 'currency', 'amount_minor', 'status'] as $column) {
            $table->string($column);
        }
    });
    Schema::create('candidate_payables', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('amount_minor');
        $table->string('status');
    });
    DB::table('candidate_payables')->insert([['id' => 'zero-payable', 'amount_minor' => '0', 'status' => 'completed_without_charge'],
        ['id' => 'payable-one', 'amount_minor' => '10000', 'status' => 'awaiting_payment']]);
    $owner = PaymentsOwner::create(['id' => 'payable-owner', 'stripe_id' => 'cus_legacy']);
    $payments = Payments::for($owner);
    $package = $consumer.'/vendor/jojeringnax/airwallex-laravel';
    $recipe = static fn (string $name): \Closure => require $package.'/examples/'.$name;
    $report = ['status' => 'passed', 'provider_observations' => 'unexecuted', 'recipes' => [], 'checks' => []];
    $done = static function (string $name) use (&$report): void {
        $report['recipes'][$name] = 'passed';
    };
    // Application command identity and amount precede I/O, including configuration repair.
    DB::table('candidate_payment_commands')->insert(['action_key' => 'customer-original', 'amount' => '0', 'provider' => 'airwallex']);
    config(['airwallex.payments.api_key' => null]);
    try {
        $recipe('payments-customer.php')($owner, 'customer-original');
        throw new RuntimeException('Missing local credentials reached a provider mutation.');
    } catch (ConfigurationException) {
        $prepared = Operation::where('action_key', 'customer-original')->sole();
        check($prepared->status === 'prepared' && $http->requests === [], 'Configuration failure changed the original prepared operation.');
    }
    config(['airwallex.payments.api_key' => 'key-synthetic-payments']);
    $customer = $recipe('payments-customer.php')($owner, 'customer-original');
    check($prepared->fresh()->request_id === $prepared->request_id && $http->writes() === 1 && $owner->fresh()->stripe_id === 'cus_legacy', 'Configuration repair changed identity or Cashier mapping.');
    $done('payments-customer.php');
    $setup = $recipe('payments-setup.php')($owner, 'setup-original');
    check($setup->expose()['client_secret'] === 'SYNTHETIC-PRIVATE-HANDOFF', 'Explicit setup bootstrap was unavailable.');
    check(! str_contains(json_encode($setup).print_r($setup, true), 'SYNTHETIC-PRIVATE'), 'Setup diagnostics exposed a secret.');
    $http->methods['mtd-payments'] = ['id' => 'mtd-payments', 'customer_id' => $customer->remote_id, 'status' => 'CREATED', 'type' => 'card', 'updated_at' => $http->time()];
    $http->consents['cst-payments'] = [...$http->consents['cst-payments'], 'status' => 'VERIFIED', 'payment_method' => ['id' => 'mtd-payments', 'type' => 'card'], 'updated_at' => $http->time()];
    $instruments = $recipe('payments-instruments.php')($owner, 'cst-payments');
    check($instruments['default']->id === $instruments['instrument']->id && $instruments['all']->count() === 1, 'Canonical setup and explicit local default did not converge.');
    $done('payments-setup.php');
    $done('payments-instruments.php');
    $intentRecipe = $recipe('payments-intent.php');
    $writes = $http->writes();
    try {
        $intentRecipe($owner, 'zero-command', 'zero-payable', '0', 'USD');
        throw new RuntimeException('Zero payable created a financial intent.');
    } catch (ConflictException) {
        check($writes === $http->writes(), 'Zero business flow issued a mutation.');
    }
    DB::table('candidate_payment_commands')->insert(['action_key' => 'payable-create', 'amount' => '10000', 'provider' => 'airwallex']);
    $intent = $intentRecipe($owner, 'payable-create', 'payable-one', '10000', 'USD');
    check($intent->amount === '100.00' && ! $intent->paid(), 'Minor-unit payable conversion failed.');
    $done('payments-intent.php');
    $bootstrap = $recipe('payments-browser.php')($owner, $intent);
    check($bootstrap->expose()['client_secret'] === 'SYNTHETIC-PRIVATE-HANDOFF' && ! str_contains(json_encode($bootstrap).print_r($bootstrap, true), 'SYNTHETIC-PRIVATE'), 'Browser handoff was missing or leaked.');
    $done('payments-browser.php');
    $confirmed = $recipe('payments-confirm.php')($owner, $intent, 'confirm-original', 'cst-payments', 'merchant');
    check($confirmed->status === 'REQUIRES_CUSTOMER_ACTION' && ! $confirmed->paid(), '3DS requirement was mistaken for financial completion.');
    $done('payments-confirm.php');
    $http->advanceIntent($intent->remote_id, ['status' => 'REQUIRES_CAPTURE']);
    $writes = $http->writes();
    check(! $payments->continueIntent($intent)->paid() && $writes === $http->writes(), 'Browser callback mutated or fulfilled the payable.');
    // Capture acknowledgement reserves the original command until captured amount advances.
    $capturing = $payments->captureIntent($intent, 'capture-original', '40.00');
    check(! $capturing->paid(), 'Pending capture fulfilled a payable.');
    $capture = Operation::where('action_key', 'capture-original')->sole();
    $writes = $http->writes();
    try {
        $payments->captureIntent($intent, 'capture-replacement', '40.00');
        throw new RuntimeException('Pending capture allowed replacement.');
    } catch (ConflictException) {
    }
    check($writes === $http->writes(), 'Replacement capture reached the provider.');
    $http->advanceIntent($intent->remote_id, ['status' => 'SUCCEEDED', 'captured_amount' => '40.00']);
    $captured = $recipe('payments-recovery.php')($owner, 'intent', $capture->id);
    check($captured->paid() && $captured->captured_amount === '40.00' && $writes === $http->writes(), 'Capture recovery did not use canonical reads.');
    $done('payments-recovery.php');
    $ledger = $recipe('payments-ledger.php');
    $deliver = static function (Observation $observation) use ($ledger, $customer): array {
        return $ledger(new PaymentObserved($observation->id, $observation->environment, $observation->account_id, $observation->resource_type, $observation->resource_id, $observation->payload), $customer->id, 'candidate_payment_ledger', 'airwallex-clearing-USD');
    };
    $cashObservation = Observation::where('resource_id', $intent->id)->orderByDesc('created_at')->get()->first(fn ($row) => ($row->payload['captured_amount'] ?? null) === '40.00');
    check($cashObservation !== null && $deliver($cashObservation)['delta_minor'] === '4000', 'Canonical partial capture was not recorded exactly.');
    check($deliver($cashObservation)['outcome'] === 'duplicate', 'Duplicate cash observation booked twice.');
    // A Billing transaction external_id is only a locator: canonical Payments state uses the same writer.
    $billingHint = ['external_id' => $intent->remote_id, 'currency' => 'USD'];
    check($payments->reconcileIntent($intent)->remote_id === $billingHint['external_id'] && $deliver($cashObservation)['outcome'] === 'duplicate', 'A Billing cross-reference created a second cash identity.');
    $http->advanceIntent($intent->remote_id, ['captured_amount' => '60.00']);
    $payments->reconcileIntent($intent);
    $larger = Observation::where('resource_id', $intent->id)->get()->first(fn ($row) => ($row->payload['captured_amount'] ?? null) === '60.00');
    check($larger !== null && $deliver($larger)['delta_minor'] === '2000', 'Cumulative capture observations lost or duplicated a delta.');
    check(DB::table('candidate_payment_ledger_entries')->where('kind', 'cash_received')->count() === 2, 'Partial capture accounting was not incremental.');
    DB::table('candidate_payables')->where('id', 'payable-one')->update(['status' => 'partially_funded']);
    check(DB::table('candidate_payables')->where('id', 'zero-payable')->value('status') === 'completed_without_charge'
        && DB::table('candidate_payables')->where('id', 'payable-one')->value('status') === 'partially_funded', 'Application business completion confused zero and partial funding.');
    // Listener crash after its application commit redelivers the same delivery ID safely.
    $crash = true;
    Event::listen(PaymentObserved::class, static function (PaymentObserved $event) use (&$crash, $ledger, $customer): void {
        if (! in_array($event->resourceType, ['airwallex_payment_intents', 'airwallex_payment_refunds'], true)) {
            return;
        }
        $ledger($event, $customer->id, 'candidate_payment_ledger', 'airwallex-clearing-USD');
        if ($crash) {
            $crash = false;
            throw new RuntimeException('Synthetic listener interruption after application commit.');
        }
    });
    try {
        app(ObservationDispatcher::class)->dispatch($larger->id);
        throw new RuntimeException('Listener interruption was not surfaced.');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'Synthetic listener interruption after application commit.', 'Unexpected observation dispatch failure.');
    }
    check(app(ObservationDispatcher::class)->dispatch($larger->id) && DB::table('candidate_payment_ledger_entries')->count() === 2, 'Listener redelivery duplicated application cash.');
    $refund = $recipe('payments-refund.php')($owner, $intent, 'refund-partial', '30.00');
    check(! $refund->completed() && $payments->refundableAmount($intent) === '30.00', 'Pending refund did not reserve captured capacity.');
    $done('payments-refund.php');
    foreach (['ACCEPTED', 'SETTLED'] as $status) {
        $http->advanceRefund($refund->remote_id, $status);
        $payments->reconcileRefund($refund);
        $observation = Observation::where('resource_id', $refund->id)->get()->first(fn ($row) => ($row->payload['status'] ?? null) === $status);
        check($observation !== null, 'Canonical refund observation was missing.');
        $deliver($observation);
    }
    check(DB::table('candidate_payment_ledger_credit_notes')->where('status', 'pending_review')->count() === 1, 'Refund completion did not hand off one credit-note review.');
    check(DB::table('candidate_payment_ledger_entries')->where('kind', 'refund_completed')->count() === 1, 'Accepted-to-settled refund booked two credits.');
    // Full remaining refund uses one amount persisted before calling, stable on repeats.
    $remaining = $payments->refundableAmount($intent);
    DB::table('candidate_payment_commands')->insert(['action_key' => 'refund-remaining', 'amount' => $remaining, 'provider' => 'airwallex']);
    $remainingRefund = $recipe('payments-refund.php')($owner, $intent, 'refund-remaining', $remaining);
    $writes = $http->writes();
    check($recipe('payments-refund.php')($owner, $intent, 'refund-remaining', $remaining)->id === $remainingRefund->id && $writes === $http->writes(), 'Full-remaining retry replaced its original financial intent.');
    // Signed duplicate/reordered event names are locators; canonical state still governs.
    $raw = json_encode(['id' => 'evt-payments-qualified', 'name' => 'payment_intent.payment_failed', 'created_at' => '2026-11-11T12:00:00Z', 'version' => '2026-08-21', 'account_id' => 'account-synthetic-payments', 'data' => ['object' => ['id' => $intent->remote_id]]], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->getTimestampMs();
    $signature = hash_hmac('sha256', $timestamp.$raw, 'secret-synthetic-payments');
    $receipt = $recipe('payments-receipt.php')($raw, $timestamp, $signature);
    check($recipe('payments-receipt.php')($raw, $timestamp, $signature)->id === $receipt->id, 'Duplicate signed delivery created another receipt.');
    check(app(Receipts::class)->process($receipt->id)->status === 'processed' && $payments->reconcileIntent($intent)->paid(), 'Event name overrode canonical successful payment.');
    $done('payments-receipt.php');
    // An uncertain original stays read-only; a distinct consumer command may risk a duplicate.
    $http->loseNextIntentResponse = true;
    try {
        $intentRecipe($owner, 'lost-create', 'payable-lost', '123', 'USD');
        throw new RuntimeException('Lost response did not become ambiguous.');
    } catch (AmbiguousOperationException) {
    }
    $lost = Operation::where('action_key', 'lost-create')->sole();
    $originalIdentity = [$lost->request_id, $lost->intent_hash, $lost->intent];
    $writes = $http->writes();
    try {
        $intentRecipe($owner, 'lost-create', 'payable-lost', '123', 'USD');
        throw new RuntimeException('Same-key recovery replayed an uncertain creation.');
    } catch (AmbiguousOperationException) {
    }
    check($writes === $http->writes(), 'The original uncertain creation was replayed.');
    $replacement = $intentRecipe($owner, 'replace-lost', 'payable-lost', '123', 'USD');
    $lost->refresh();
    check($lost->status === 'superseded' && $lost->retry_release['outcome'] === 'unknown' && $lost->remote_id === null
        && $originalIdentity === [$lost->request_id, $lost->intent_hash, $lost->intent]
        && $replacement->remote_id !== null && $writes + 1 === $http->writes(), 'A new command blocked the customer or changed the original uncertain identity.');
    $writes = $http->writes();
    try {
        $recipe('payments-recovery.php')($owner, 'intent', $lost->id, 'int-2');
        throw new RuntimeException('A superseded original resource was silently adopted.');
    } catch (ConflictException) {
    }
    check($writes === $http->writes() && $lost->fresh()->remote_id === null
        && Observation::where('resource_id', $lost->id)->get()->contains(static fn ($row) => ($row->payload['status'] ?? null) === 'SUPERSEDED_CREATION_LOCATOR' && ($row->payload['remote_id'] ?? null) === 'int-2'),
        'Late original locator was lost, adopted or replayed.');
    $report['checks'][] = 'new_key_creation_retry_retains_unknown_original_and_quarantines_late_locator';
    // Synthetic customer-present wallet methods exercise the public PHP seam, never real client availability.
    foreach (['applepay', 'googlepay'] as $wallet) {
        $http->methods['mtd-'.$wallet] = ['id' => 'mtd-'.$wallet, 'customer_id' => $customer->remote_id, 'status' => 'CREATED', 'type' => $wallet, 'updated_at' => $http->time()];
        $walletIntent = $intentRecipe($owner, 'create-'.$wallet, 'order-'.$wallet, '200', 'USD');
        $walletIntent = $recipe('payments-confirm.php')($owner, $walletIntent, 'confirm-'.$wallet, 'mtd-'.$wallet, 'customer');
        check(! $walletIntent->paid() && $recipe('payments-browser.php')($owner, $walletIntent)->expose()['payment_intent_id'] === $walletIntent->remote_id, 'Wallet seam bypassed owned client bootstrap.');
        $http->advanceIntent($walletIntent->remote_id, ['status' => 'CANCELLED']);
        check(! $payments->continueIntent($walletIntent)->paid(), 'Unpaid cancelled wallet callback fulfilled a payable.');
    }
    // Original provider is an application-owned route, even when this billable has a Payments customer.
    $route = static fn (string $provider) => match ($provider) {
        'stripe' => $owner->refund('pi_legacy', ['amount' => 100]), 'airwallex' => $remainingRefund, default => throw new RuntimeException('Unknown original provider.')
    };
    check($route('stripe')->id === 're_legacy' && count($stripe->requests) === 1 && $owner->fresh()->stripe_id === 'cus_legacy', 'Legacy refund left its original Cashier provider.');
    $done('payments-ledger.php');
    foreach (['airwallex_payment_customers' => 'snapshot', 'airwallex_payment_instruments' => 'snapshot', 'airwallex_payment_intents' => 'snapshot', 'airwallex_payment_refunds' => 'snapshot'] as $table => $column) {
        foreach (DB::table($table)->pluck($column) as $stored) {
            check(! str_contains((string) $stored, 'SYNTHETIC-PRIVATE'), 'A durable snapshot exposed browser secrets.');
        }
    }
    $report['checks'] = ['cached_native_consumer', 'original_model_cashier_refund', 'prepared_configuration_repair', 'ephemeral_setup_and_intent_bootstrap', 'zero_no_charge', 'merchant_initiated_saved_card', 'customer_present_wallet_php_seams', 'refund_credit_note_review_handoff', 'synthetic_3ds_canonical_return', 'pending_capture_no_replay', 'partial_cumulative_capture_ledger', 'billing_hint_canonical_dedup', 'listener_redelivery', 'pending_partial_full_remaining_refund', 'accepted_settled_single_credit', 'signed_duplicate_reordered_receipt', 'ambiguous_create_read_only_recovery'];
    $report['limitations'] = ['Synthetic transport only; no account, browser, wallet, 3DS, settlement, target deployment or accounting policy qualification.', 'Billing hint is synthetic; exact installed Billing transaction reads are qualified by the separate subscriptions track.', 'Application payable remains partially funded; canonical paid() is not an order-total assertion.'];
    file_put_contents($options['evidence'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo json_encode(['status' => 'passed', 'recipes' => count($report['recipes']), 'checks' => count($report['checks'])])."\n";
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'type' => $error::class, 'reason' => $error->getMessage()])."\n");
    exit(1);
}
