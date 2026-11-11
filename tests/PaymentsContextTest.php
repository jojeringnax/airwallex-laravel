<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments;
use Jojeringnax\AirwallexLaravel\Payments\Client;
use Jojeringnax\AirwallexLaravel\Payments\Context;
use Jojeringnax\AirwallexLaravel\Payments\Models\Customer;
use Jojeringnax\AirwallexLaravel\Payments\Models\Operation;
use Jojeringnax\AirwallexLaravel\Payments\Operations;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class PaymentsContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['airwallex.payments.account_id' => 'account-payments', 'airwallex.payments.client_id' => 'payments-client', 'airwallex.payments.api_key' => 'payments-key']);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('payments_owners', fn (Blueprint $table) => $table->string('id')->primary());
    }

    public function test_explicit_account_login_and_customer_creation_preserve_command_and_hide_profile(): void
    {
        $owner = PaymentsOwner::create(['id' => 'owner-one']);
        $http = $this->transport(function (RequestInterface $request): ResponseInterface {
            $body = json_decode((string) $request->getBody(), true);
            self::assertSame(0, DB::transactionLevel());
            $operation = Operation::firstOrFail();
            if ($request->getMethod() === 'POST') {
                self::assertSame('in_flight', $operation->status);
                self::assertSame($operation->request_id, $body['request_id']);
                self::assertSame(Customer::first()->merchant_customer_id, $body['merchant_customer_id']);
            }

            return FakeHttpClient::json(['id' => 'cus-one', ...$operation->intent, 'request_id' => $operation->request_id, 'client_secret' => 'secret-must-stay-private']);
        });
        $payments = Payments::for($owner);
        $customer = $payments->createCustomer('customer-one', ['email' => 'private@example.test']);
        self::assertSame('cus-one', $customer->remote_id);
        self::assertSame('account-payments', $http->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('payments-client', $http->requests[0]->getHeaderLine('x-client-id'));
        self::assertSame('succeeded', Operation::first()->status);
        self::assertNull(Operation::first()->lock_key);
        self::assertStringNotContainsString('private@example.test', Operation::first()->getRawOriginal('intent'));
        self::assertStringNotContainsString('private@example.test', $customer->toJson());
        self::assertArrayNotHasKey('client_secret', $customer->snapshot);
        self::assertSame(0, \Jojeringnax\AirwallexLaravel\Models\Customer::count());
        self::assertSame($customer->id, $payments->createCustomer('customer-one', ['email' => 'private@example.test'])->id);
        self::assertCount(4, $http->requests); // login, create, canonical, read-only repeat
        $this->expectException(ConflictException::class);
        $payments->createCustomer('customer-one', ['email' => 'different@example.test']);
    }

    public function test_timeout_retains_original_identity_and_prohibits_new_key_or_explicit_link(): void
    {
        $payments = Payments::for(PaymentsOwner::create(['id' => 'owner-one']));
        $http = $this->transport(fn () => throw new RuntimeException('synthetic timeout'));
        try {
            $payments->createCustomer('first');
            self::fail('Expected ambiguity.');
        } catch (AmbiguousOperationException $exception) {
            self::assertSame(Operation::first()->id, $exception->operationId);
        }
        $original = Operation::first();
        foreach (['first', 'replacement'] as $key) {
            try {
                $payments->createCustomer($key);
                self::fail('Unresolved command must remain reserved.');
            } catch (AmbiguousOperationException|ConflictException) {
                self::assertSame($original->request_id, Operation::first()->request_id);
            }
        }
        self::assertCount(2, $http->requests);
        self::assertSame(1, Operation::count());
    }

    public function test_explicit_link_is_owned_unique_and_can_sync_without_reassigning_merchant_identity(): void
    {
        $payments = Payments::for(PaymentsOwner::create(['id' => 'owner-one']));
        $http = $this->transport(function (RequestInterface $request): ResponseInterface {
            $operation = Operation::first();

            return FakeHttpClient::json(['id' => 'cus-link', 'merchant_customer_id' => 'existing-merchant', 'first_name' => 'Name', ...($operation === null ? [] : ['request_id' => $operation->request_id])]);
        });
        $customer = $payments->linkCustomer('cus-link');
        self::assertSame('existing-merchant', $customer->merchant_customer_id);
        self::assertSame($customer->id, $payments->syncCustomer('sync', ['first_name' => 'Name'])->id);
        self::assertSame(['POST', 'GET', 'POST', 'GET'], array_map(fn ($request) => $request->getMethod(), $http->requests));
        $this->expectException(ConflictException::class);
        Payments::for(PaymentsOwner::create(['id' => 'owner-two']))->linkCustomer('cus-link');
    }

    public function test_foreign_account_and_mutable_local_owner_are_rejected(): void
    {
        $payments = Payments::for(PaymentsOwner::create(['id' => 'owner-one']));
        $this->transport(fn () => FakeHttpClient::json(['id' => 'cus-link', 'merchant_customer_id' => 'existing', 'account_id' => 'foreign-account']));
        try {
            $payments->linkCustomer('cus-link');
            self::fail('Foreign account must fail.');
        } catch (OwnershipException) {
            self::assertNull(Customer::first()->remote_id);
        }
        $customer = Customer::first();
        $customer->account_id = 'foreign-account';
        $this->expectException(OwnershipException::class);
        app(Context::class)->assertModel($customer);
    }

    public function test_operator_known_creation_recovery_requires_original_request_and_owned_intent(): void
    {
        $payments = Payments::for(PaymentsOwner::create(['id' => 'owner-one']));
        $this->transport(fn () => throw new RuntimeException('synthetic timeout'));
        try {
            $payments->createCustomer('first');
        } catch (AmbiguousOperationException) {
        }
        $operation = Operation::first();
        $http = $this->transport(fn () => FakeHttpClient::json(['id' => 'recovered', ...$operation->intent, 'request_id' => $operation->request_id]));
        $result = $payments->recoverCustomer($operation->id, 'recovered');
        self::assertSame('recovered', $result->remote_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame('GET', $http->requests[array_key_last($http->requests)]->getMethod());
        $payments->offboard();
        $this->expectException(ConflictException::class);
        $payments->syncCustomer('offboarded-write', ['first_name' => 'Name']);
    }

    public function test_link_between_create_preflight_and_reservation_cannot_create_another_remote_customer(): void
    {
        $owner = PaymentsOwner::create(['id' => 'owner-one']);
        $stale = Customer::create([...app(Context::class)->attributes(), 'billable_type' => $owner->getMorphClass(), 'billable_id' => $owner->id]);
        $http = $this->transport(fn () => FakeHttpClient::json(['id' => 'linked', 'merchant_customer_id' => 'merchant-linked']));
        $payments = Payments::for($owner);
        $payments->linkCustomer('linked');
        try {
            app(Operations::class)->prepare($stale, 'concurrent-create', 'customer.create', []);
            self::fail('Stale mapping must not authorize a new create.');
        } catch (ConflictException) {
            self::assertSame(0, Operation::count());
            self::assertCount(2, $http->requests);
        }
        config(['airwallex.payments.client_id' => null, 'airwallex.payments.api_key' => null]);
        $this->app->forgetInstance(Client::class);
        self::assertSame('linked', $payments->createCustomer('local-only')->remote_id);
        self::assertSame('linked', $payments->customer()->remote_id);
        $this->expectException(ConflictException::class);
        $payments->createCustomer('');
    }

    public function test_missing_local_sdk_configuration_keeps_the_original_command_prepared_until_repair(): void
    {
        $payments = Payments::for(PaymentsOwner::create(['id' => 'owner-one']));
        $http = $this->transport(function (): ResponseInterface {
            $operation = Operation::firstOrFail();

            return FakeHttpClient::json(['id' => 'customer-after-repair', ...$operation->intent, 'request_id' => $operation->request_id]);
        });
        config(['airwallex.payments.api_key' => null]);
        try {
            $payments->createCustomer('repair-original-command');
            self::fail('Missing local SDK credentials reached the mutation claim.');
        } catch (ConfigurationException) {
            $operation = Operation::firstOrFail();
            self::assertSame('prepared', $operation->status);
            self::assertNotNull($operation->lock_key);
            self::assertSame([], $http->requests);
        }
        $requestId = $operation->request_id;
        config(['airwallex.payments.api_key' => 'payments-key']);
        self::assertSame('customer-after-repair', $payments->createCustomer('repair-original-command')->remote_id);
        self::assertSame($requestId, $operation->fresh()->request_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertCount(1, Operation::all());
        self::assertCount(1, array_filter($http->requests, fn (RequestInterface $request) => $request->getMethod() === 'POST' && str_contains($request->getUri()->getPath(), '/pa/customers/create')));
    }

    private function transport(Closure $reply): object
    {
        $http = new class($reply) implements ClientInterface
        {
            public array $requests = [];

            public function __construct(private Closure $reply) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests[] = $request;
                if (str_ends_with($request->getUri()->getPath(), '/authentication/login')) {
                    return FakeHttpClient::login();
                }

                return ($this->reply)($request);
            }
        };
        $this->app->instance(ClientInterface::class, $http);
        $this->app->forgetInstance(Client::class);

        return $http;
    }
}

class PaymentsOwner extends Model
{
    protected $table = 'payments_owners';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
