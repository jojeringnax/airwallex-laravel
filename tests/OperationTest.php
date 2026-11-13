<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Models\SubscriptionSlot;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Services\RecoveryManager;
use PHPUnit\Framework\Attributes\DataProvider;

class OperationTest extends TestCase
{
    private Customer $customer;

    private OperationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->customer = Customer::create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'billable_type' => 'team', 'billable_id' => '1', 'remote_id' => 'customer']);
        $this->manager = app(OperationManager::class);
    }

    public function test_intent_is_durable_exact_and_same_key_is_stable(): void
    {
        $operation = $this->manager->prepare($this->customer, 'command', 'create', ['b' => null, 'a' => '1.0000000000000001'], ['starts_at' => '2026-12-01T00:00:00.123456789Z'], name: 'default');
        $repeat = $this->manager->prepare($this->customer, 'command', 'create', ['a' => '1.0000000000000001', 'b' => null], ['starts_at' => 'DIFFERENT RESOLVED CLOCK'], name: 'default');
        self::assertSame($operation->id, $repeat->id);
        self::assertSame($operation->request_id, $repeat->request_id);
        self::assertSame('2026-12-01T00:00:00.123456789Z', $repeat->intent['starts_at']);
        self::assertStringNotContainsString('2026-12-01', $operation->getRawOriginal('intent'));
        self::assertArrayNotHasKey('intent', $operation->toArray());
        self::assertSame($operation->id, SubscriptionSlot::first()->operation_id);
        $this->expectException(ConflictException::class);
        $this->manager->prepare($this->customer, 'command', 'create', ['a' => '2'], [], name: 'default');
    }

    public function test_intent_preserves_object_maps_and_equivalent_map_order(): void
    {
        $specification = ['metadata' => (object) ['b' => 'second', '0' => 'zero', 'a' => 'first']];
        $intent = ['metadata' => new \stdClass, 'map' => (object) ['0' => 'zero'], 'list' => [], 'items' => [['price_id' => 'price', 'quantity' => 1]], 'decimal' => '1.00000000000000001', 'null' => null, 'false' => false, 'zero' => 0];
        $operation = $this->manager->prepare($this->customer, 'command', 'create', $specification, $intent, name: 'default');
        self::assertSame(json_encode($intent), json_encode($operation->fresh()->intent));
        $repeat = $this->manager->prepare($this->customer, 'command', 'create', ['metadata' => (object) ['a' => 'first', '0' => 'zero', 'b' => 'second']], $intent, name: 'default');
        self::assertSame($operation->id, $repeat->id);
    }

    public function test_numeric_object_map_order_never_collapses_into_list_intent(): void
    {
        $manager = $this->manager;
        $operation = $manager->prepare($this->customer, 'command', 'create', ['metadata' => [1 => 'one', 0 => 'zero']], [], name: 'default');
        $equivalent = $manager->prepare($this->customer, 'command', 'create', ['metadata' => (object) ['0' => 'zero', '1' => 'one']], [], name: 'default');
        self::assertSame($operation->id, $equivalent->id);
        $this->expectException(ConflictException::class);
        $manager->prepare($this->customer, 'command', 'create', ['metadata' => ['zero', 'one']], [], name: 'default');
    }

    public function test_different_action_cannot_take_reserved_name_or_customer_lock(): void
    {
        $this->manager->prepare($this->customer, 'first', 'create', [], [], name: 'default');
        $this->expectException(ConflictException::class);
        $this->manager->prepare($this->customer, 'second', 'create', [], [], name: 'default');
    }

    public function test_timeout_keeps_identity_and_never_replays_mutation(): void
    {
        $operation = $this->manager->prepare($this->customer, 'command', 'subscription.create', [], [], name: 'default');
        $calls = 0;
        $write = function () use (&$calls): array {
            $calls++;
            throw new \RuntimeException('transport');
        };
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->manager->execute($operation, $write, fn () => null);
                self::fail('Expected ambiguity');
            } catch (AmbiguousOperationException $exception) {
                self::assertSame($operation->id, $exception->operationId);
            }
        }
        self::assertSame(1, $calls);
        self::assertSame('ambiguous', $operation->fresh()->status);
        self::assertNotNull($operation->fresh()->lock_key);
        self::assertSame(1, SubscriptionSlot::count());
        $replacement = $this->manager->prepare($this->customer, 'new-command', 'subscription.create', [], [], name: 'default');
        self::assertSame('superseded', $operation->fresh()->status);
        self::assertNull($operation->fresh()->lock_key);
        self::assertSame('unknown', $operation->fresh()->getAttribute('retry_release')['outcome']);
        self::assertSame($replacement->id, SubscriptionSlot::firstOrFail()->operation_id);
        self::assertSame($operation->id, $this->manager->prepare($this->customer, 'command', 'subscription.create', [], [], name: 'default')->id);
        app(RecoveryManager::class)->operation($operation, 'late-subscription');
        self::assertSame($replacement->id, SubscriptionSlot::firstOrFail()->operation_id);
        self::assertSame(0, Subscription::count());
        self::assertSame(1, $calls);
        self::assertSame('quarantined', DomainEvent::latest('id')->firstOrFail()->payload['outcome']);
    }

    public function test_local_sdk_configuration_failure_keeps_original_command_prepared_for_correction(): void
    {
        config(['airwallex.api_key' => null]);
        $operation = $this->manager->prepare($this->customer, 'configure', 'customer.create', [], []);
        $calls = 0;
        $write = function () use (&$calls): array {
            $calls++;

            return ['id' => 'created'];
        };
        try {
            $this->manager->execute($operation, $write, fn () => null);
            self::fail('Missing configuration must fail before claiming dispatch.');
        } catch (ConfigurationException) {
            self::assertSame('prepared', $operation->fresh()->status);
            self::assertSame(0, $calls);
        }
        config(['airwallex.api_key' => 'corrected-synthetic-key']);
        $this->app->forgetInstance(Client::class);
        $this->manager->execute($operation, $write, fn () => null);
        self::assertSame(1, $calls);
        self::assertSame('succeeded', $operation->fresh()->status);
    }

    #[DataProvider('confirmationFailures')]
    public function test_confirmed_response_then_local_failure_retains_remote_identity(bool $supersede): void
    {
        $operation = $this->manager->prepare($this->customer, 'command', 'subscription.create', [], [], name: 'default');
        $replacement = null;
        $confirmed = false;
        try {
            $this->manager->execute($operation, function () use ($supersede, &$replacement): array {
                if ($supersede) {
                    $replacement = $this->manager->prepare($this->customer, 'replacement', 'subscription.create', [], [], name: 'default');
                }

                return ['id' => 'remote-subscription'];
            }, function () use (&$confirmed): void {
                $confirmed = true;
                throw new \RuntimeException('local write failed');
            });
            self::fail('Expected ambiguity');
        } catch (AmbiguousOperationException) {
        }
        self::assertSame('remote-subscription', $operation->fresh()->remote_id);
        self::assertSame($supersede ? 'superseded' : 'ambiguous', $operation->fresh()->status);
        self::assertSame(! $supersede, $confirmed);
        self::assertSame($replacement?->id ?? $operation->id, SubscriptionSlot::firstOrFail()->operation_id);
        self::assertSame(1, SubscriptionSlot::count());
    }

    public static function confirmationFailures(): array
    {
        return [[false], [true]];
    }

    public function test_sdk_preflight_rejection_releases_only_its_unconfirmed_reservation(): void
    {
        $operation = $this->manager->prepare($this->customer, 'command', 'create', [], [], name: 'default');
        try {
            $this->manager->execute($operation, fn () => throw new ValidationException('invalid'), fn () => null);
            self::fail('Expected SDK rejection');
        } catch (ValidationException) {
        }
        self::assertSame('failed', $operation->fresh()->status);
        self::assertNull($operation->fresh()->lock_key);
        self::assertSame(0, SubscriptionSlot::count());
        $replacement = $this->manager->prepare($this->customer, 'next-command', 'create', [], [], name: 'default');
        self::assertNotSame($operation->id, $replacement->id);
    }

    public function test_only_confirmed_termination_releases_its_own_generation(): void
    {
        $old = $this->customer->subscriptions()->create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'name' => 'default', 'remote_id' => 'old', 'status' => 'UNPAID', 'snapshot' => []]);
        SubscriptionSlot::create(['customer_id' => $this->customer->id, 'name' => 'default', 'subscription_id' => $old->id]);
        try {
            $this->manager->releaseTerminated($old);
            self::fail('Expected retained reservation');
        } catch (ConflictException) {
        }
        self::assertSame(1, SubscriptionSlot::count());
        $old->update(['status' => 'CANCELLED']);
        $this->manager->releaseTerminated($old);
        self::assertSame(0, SubscriptionSlot::count());
        $new = $this->customer->subscriptions()->create(['environment' => 'sandbox', 'organization_id' => 'org-test', 'name' => 'default', 'remote_id' => 'new', 'status' => 'PENDING', 'snapshot' => []]);
        SubscriptionSlot::create(['customer_id' => $this->customer->id, 'name' => 'default', 'subscription_id' => $new->id]);
        $this->manager->releaseTerminated($old);
        self::assertSame($new->id, SubscriptionSlot::first()->subscription_id);
        self::assertSame(2, $this->customer->subscriptions()->count());
    }

    public function test_ordinary_failure_diagnostics_drop_untrusted_causes_and_retain_sdk_classification(): void
    {
        $operation = $this->manager->prepare($this->customer, 'command', 'create', [], [], name: 'default');
        try {
            $this->manager->execute($operation, fn () => ['id' => 'remote'], fn () => throw new \RuntimeException('SYNTHETIC-CLIENT-SECRET'));
            self::fail('Expected ambiguity');
        } catch (AmbiguousOperationException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('SYNTHETIC-CLIENT-SECRET', (string) $exception);
            self::assertStringNotContainsString('SYNTHETIC-CLIENT-SECRET', print_r($exception, true));
            try {
                serialize($exception);
                self::fail('Expected trace serialization rejection');
            } catch (BillingException $serialization) {
                self::assertStringNotContainsString('SYNTHETIC-CLIENT-SECRET', (string) $serialization);
            }
        }
        $sdk = new ApiException(422);
        $wrapped = new AmbiguousOperationException($operation->id, $sdk);
        self::assertSame($sdk, $wrapped->getPrevious());
        self::assertSame(422, $wrapped->getPrevious()->httpStatus());
    }

    public function test_validation_after_remote_success_is_ambiguous(): void
    {
        $operation = $this->manager->prepare($this->customer, 'command', 'create', [], [], name: 'default');
        try {
            $this->manager->execute($operation, fn () => ['id' => 'remote'], fn () => throw new ValidationException('bad follow-up ID'));
            self::fail('Expected ambiguity');
        } catch (AmbiguousOperationException) {
        }
        self::assertSame('ambiguous', $operation->fresh()->status);
        self::assertSame(1, SubscriptionSlot::count());
    }
}
