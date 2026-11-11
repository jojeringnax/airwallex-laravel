<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Exception\ApiException;
use Jojeringnax\Airwallex\Exception\TransportException;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\Billable;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class CustomerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        foreach (['customer_teams', 'customer_companies'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
            });
        }
        Schema::create('customer_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::tearDown();
    }

    public function test_create_uses_real_sdk_and_durable_identity_then_reuses_mapping_without_http(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A')], function (RequestInterface $request): void {
            self::assertSame(0, DB::transactionLevel());
            if ($request->getUri()->getPath() === '/api/v1/billing/billing_customers/create') {
                self::assertSame('in_flight', Operation::first()->status);
                self::assertNull(Customer::first()->remote_id);
            }
        });
        $recipe = require __DIR__.'/../examples/create-customer.php';
        $customer = $recipe($team, 'create-team', ['nickname' => '', 'metadata' => (object) ['0' => 'zero']]);
        self::assertSame('Customer-A', $customer->remote_id);
        self::assertSame($team->id, $customer->billable_id);
        $body = json_decode((string) $http->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(Operation::first()->request_id, $body['request_id']);
        self::assertSame('Team', $body['name']);
        self::assertSame('team@example.test', $body['email']);
        self::assertSame('', $body['nickname']);
        self::assertSame(['zero'], array_values($body['metadata']));
        self::assertArrayNotHasKey('address', $body);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('create-team', ['nickname' => '', 'metadata' => (object) ['0' => 'zero']])->id);
        config(['airwallex.client_id' => null, 'airwallex.api_key' => null]);
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('get-existing')->id);
        self::assertCount(2, $http->requests);
        self::assertSame(1, Customer::count());
        $this->expectException(ConflictException::class);
        $team->createOrGetAirwallexCustomer('create-team', ['name' => 'Changed intent']);
    }

    public function test_explicit_sync_preserves_null_empty_and_omitted_fields_without_request_id(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A', ['name' => 'Changed', 'email' => null])]);
        $customer = $team->linkAirwallexCustomer('Customer-A');
        $result = $team->syncAirwallexCustomer('sync-profile', ['name' => 'Changed', 'email' => null, 'address' => ['country_code' => 'IT', 'city' => ''], 'phone_number' => '']);
        self::assertSame($customer->id, $result->id);
        self::assertSame('Customer-A', $result->remote_id);
        $body = json_decode((string) $http->requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'Changed', 'email' => null, 'address' => ['country_code' => 'IT', 'city' => ''], 'phone_number' => ''], $body);
        self::assertSame('/api/v1/billing/billing_customers/Customer-A/update', $http->requests[2]->getUri()->getPath());
        $team->syncAirwallexCustomer('sync-profile', ['name' => 'Changed', 'email' => null, 'address' => ['country_code' => 'IT', 'city' => ''], 'phone_number' => '']);
        self::assertCount(3, $http->requests);
        self::assertStringNotContainsString('team@example.test', Operation::first()->getRawOriginal('intent'));
    }

    public function test_eloquent_save_has_no_network_side_effect_and_link_is_read_only_and_repeatable(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A')]);
        $team->update(['email' => 'changed@example.test']);
        self::assertSame([], $http->requests);
        $first = $team->linkAirwallexCustomer('Customer-A');
        $second = $team->linkAirwallexCustomer('Customer-A');
        self::assertSame($first->id, $second->id);
        self::assertSame(0, Operation::count());
        self::assertSame(0, Subscription::count());
        self::assertSame(['POST', 'GET', 'GET'], array_map(fn ($request) => $request->getMethod(), $http->requests));
    }

    public function test_foreign_mapping_and_matching_email_cannot_establish_ownership(): void
    {
        $one = $this->team('one');
        $two = $this->team('two');
        $this->http([FakeHttpClient::login(), self::remote('Customer-A', ['email' => 'team@example.test']), self::remote('Customer-A', ['email' => 'team@example.test'])]);
        $customer = $one->linkAirwallexCustomer('Customer-A');
        try {
            $two->linkAirwallexCustomer('Customer-A');
            self::fail('A foreign mapping was accepted.');
        } catch (OwnershipException) {
            self::assertSame('Customer-A', $customer->fresh()->remote_id);
            self::assertNull($two->customerMapping()->remote_id);
        }
        $this->expectException(OwnershipException::class);
        $one->linkAirwallexCustomer('Customer-B');
    }

    public function test_failed_link_read_can_be_retried_without_a_hold_or_remote_mutation(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), FakeHttpClient::json(['code' => 'resource_not_found'], 404), self::remote('Customer-A')]);
        try {
            $team->linkAirwallexCustomer('Customer-A');
            self::fail('Missing customer was linked.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->httpStatus());
            self::assertNull($team->customerMapping()->remote_id);
            self::assertSame(0, Operation::count());
        }
        self::assertSame('Customer-A', $team->linkAirwallexCustomer('Customer-A')->remote_id);
        self::assertCount(3, $http->requests);
    }

    public function test_link_recipe_preserves_an_unrelated_financial_hold(): void
    {
        $team = $this->team();
        $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A')]);
        $customer = $team->linkAirwallexCustomer('Customer-A');
        $held = app(OperationManager::class)->prepare($customer, 'financial-action', 'subscription.create', [], []);
        $recipe = require __DIR__.'/../examples/customer.php';
        self::assertSame($customer->id, $recipe($team, 'Customer-A')->id);
        self::assertSame('prepared', $held->fresh()->status);
        self::assertNotNull($held->fresh()->lock_key);
    }

    public function test_getting_a_confirmed_mapping_does_not_acquire_or_clear_a_financial_hold(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A')]);
        $customer = $team->linkAirwallexCustomer('Customer-A');
        $held = app(OperationManager::class)->prepare($customer, 'financial-action', 'subscription.create', [], []);
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('get-existing')->id);
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('get-existing', ['name' => 'Only sync changes profiles'])->id);
        self::assertSame(1, Operation::count());
        self::assertSame('prepared', $held->fresh()->status);
        self::assertNotNull($held->fresh()->lock_key);
        self::assertCount(2, $http->requests);
        $this->expectException(ConflictException::class);
        $team->createOrGetAirwallexCustomer('financial-action');
    }

    public function test_existing_mapping_rejects_action_key_reuse_for_another_customer(): void
    {
        $one = $this->team('one');
        $two = $this->team('two');
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-B')]);
        $one->createOrGetAirwallexCustomer('create-one');
        $two->linkAirwallexCustomer('Customer-B');
        try {
            $two->createOrGetAirwallexCustomer('create-one');
            self::fail('Another customer command identity was reused.');
        } catch (ConflictException) {
            self::assertSame('Customer-B', $two->customerMapping()->remote_id);
        }
        self::assertCount(3, $http->requests);
    }

    public function test_existing_mapping_preserves_and_recovers_its_ambiguous_original_create(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A')]);
        $customer = $team->createOrGetAirwallexCustomer('create-team');
        $operation = Operation::first();
        // Simulate the final local outcome write failing after mapping commit.
        $operation->forceFill(['status' => 'ambiguous', 'lock_key' => 'customer/'.$customer->id])->save();
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('read-existing')->id);
        self::assertSame('ambiguous', $operation->fresh()->status);
        self::assertNotNull($operation->fresh()->lock_key);
        self::assertCount(2, $http->requests);
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('create-team')->id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertCount(3, $http->requests);
        self::assertSame('GET', $http->requests[2]->getMethod());
    }

    public function test_unknown_id_timeout_keeps_original_identity_and_prevents_create_or_link_replay(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), new RuntimeException('SYNTHETIC-TRANSPORT-SECRET'), self::remote('Customer-A')]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $team->createOrGetAirwallexCustomer('create-team');
                self::fail('Expected retained ambiguity.');
            } catch (AmbiguousOperationException $exception) {
                self::assertSame(Operation::first()->id, $exception->operationId);
                self::assertStringNotContainsString('SYNTHETIC-TRANSPORT-SECRET', (string) $exception);
                if ($exception->getPrevious() !== null) {
                    self::assertInstanceOf(TransportException::class, $exception->getPrevious());
                    self::assertStringNotContainsString('SYNTHETIC-TRANSPORT-SECRET', (string) $exception->getPrevious());
                }
            }
        }
        self::assertCount(2, $http->requests);
        self::assertNotNull(Operation::first()->lock_key);
        self::assertNull(Customer::first()->remote_id);
        try {
            $team->linkAirwallexCustomer('Customer-A');
            self::fail('Link stole an ambiguous financial hold.');
        } catch (ConflictException) {
            self::assertSame('ambiguous', Operation::first()->status);
            self::assertNull(Customer::first()->remote_id);
        }
    }

    public function test_response_then_local_failure_recovers_by_read_with_the_same_request_identity(): void
    {
        config(['airwallex.models.customer' => CustomerTestFaultyMapping::class]);
        CustomerTestFaultyMapping::$failNext = true;
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A')]);
        try {
            $team->createOrGetAirwallexCustomer('create-team');
            self::fail('Expected local confirmation failure.');
        } catch (AmbiguousOperationException $exception) {
            self::assertNull($exception->getPrevious());
        }
        $operation = Operation::first();
        self::assertSame('Customer-A', $operation->remote_id);
        self::assertSame('ambiguous', $operation->status);
        $customer = $team->createOrGetAirwallexCustomer('create-team');
        self::assertSame('Customer-A', $customer->remote_id);
        self::assertSame($operation->request_id, $operation->fresh()->request_id);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame('GET', $http->requests[2]->getMethod());
        self::assertCount(3, $http->requests);
    }

    public function test_ambiguous_update_requires_confirmed_profile_before_releasing_its_hold(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), new RuntimeException('timeout'), self::remote('Customer-A', ['name' => 'Old', 'email' => 'team@example.test']), self::remote('Customer-A', ['name' => 'New', 'email' => null])]);
        $team->linkAirwallexCustomer('Customer-A');
        foreach ([0, 1] as $attempt) {
            try {
                $team->syncAirwallexCustomer('update-profile', ['name' => 'New', 'email' => null]);
                self::fail('Expected unconfirmed update.');
            } catch (AmbiguousOperationException) {
                self::assertNotNull(Operation::first()->lock_key);
            }
        }
        $team->syncAirwallexCustomer('update-profile', ['name' => 'New', 'email' => null]);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertSame(['POST', 'GET', 'POST', 'GET', 'GET'], array_map(fn ($request) => $request->getMethod(), $http->requests));
    }

    public function test_empty_object_update_cannot_be_confirmed_by_a_malformed_list(): void
    {
        $team = $this->team();
        $profile = ['name' => 'Team', 'email' => 'team@example.test'];
        $http = $this->http([
            FakeHttpClient::login(), self::remote('Customer-A'), new RuntimeException('timeout'),
            self::remote('Customer-A', [...$profile, 'metadata' => []]),
            self::remote('Customer-A', [...$profile, 'metadata' => new \stdClass]),
        ]);
        $team->linkAirwallexCustomer('Customer-A');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $team->syncAirwallexCustomer('empty-metadata', ['metadata' => new \stdClass]);
                self::fail('Unconfirmed object update released its reservation.');
            } catch (AmbiguousOperationException) {
                self::assertSame('ambiguous', Operation::first()->status);
                self::assertNotNull(Operation::first()->lock_key);
            }
        }
        $operation = Operation::first();
        $team->syncAirwallexCustomer('empty-metadata', ['metadata' => new \stdClass]);
        self::assertSame('succeeded', $operation->fresh()->status);
        self::assertSame($operation->request_id, $operation->fresh()->request_id);
        self::assertSame(1, Operation::count());
        self::assertCount(5, $http->requests);
    }

    public function test_numeric_object_metadata_does_not_match_an_observed_list(): void
    {
        $team = $this->team();
        $http = $this->http([
            FakeHttpClient::login(), self::remote('Customer-A'), new RuntimeException('timeout'),
            self::remote('Customer-A', ['name' => 'Team', 'email' => 'team@example.test', 'metadata' => ['zero']]),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"Customer-A","created_at":"2026-11-10T12:00:00Z","updated_at":"2026-11-10T12:00:00Z","automatic_tax_eligibility":"ADDRESS_INCOMPLETE","name":"Team","email":"team@example.test","metadata":{"0":"zero"}}'),
        ]);
        $team->linkAirwallexCustomer('Customer-A');
        $requestId = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $team->syncAirwallexCustomer('numeric-metadata', ['metadata' => (object) ['0' => 'zero']]);
                self::fail('SDK-normalized metadata proved an unavailable object identity.');
            } catch (AmbiguousOperationException) {
                $requestId ??= Operation::first()->request_id;
                self::assertSame($requestId, Operation::first()->request_id);
                self::assertSame('ambiguous', Operation::first()->status);
                self::assertNotNull(Operation::first()->lock_key);
            }
        }
        self::assertSame(1, Operation::count());
        self::assertCount(5, $http->requests);
        self::assertSame('{"0":"zero"}', json_encode(Operation::first()->intent['profile']['metadata']));
    }

    public function test_sdk_preflight_rejection_is_classified_and_a_new_key_can_create_afterward(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A')]);
        try {
            $team->createOrGetAirwallexCustomer('bad-profile', ['email' => null]);
            self::fail('Create accepted a null email.');
        } catch (ValidationException) {
            self::assertSame('failed', Operation::first()->status);
            self::assertNull(Operation::first()->lock_key);
            self::assertSame([], $http->requests);
        }
        self::assertSame('Customer-A', $team->createOrGetAirwallexCustomer('valid-profile')->remote_id);
        self::assertSame(2, Operation::count());
    }

    public function test_missing_and_conflicting_provider_identities_leave_mappings_unconfirmed(): void
    {
        $team = $this->team();
        $this->http([FakeHttpClient::login(), FakeHttpClient::json(['name' => 'Missing identity'])]);
        $this->expectException(AmbiguousOperationException::class);
        try {
            $team->createOrGetAirwallexCustomer('malformed');
        } finally {
            self::assertNull($team->customerMapping()->remote_id);
        }
    }

    public function test_explicit_foreign_context_and_wrong_retrieve_id_are_rejected(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Wrong'), self::remote('Customer-A', ['org_id' => 'foreign']), self::remote('Customer-A', ['environment' => 'production'])]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $team->linkAirwallexCustomer('Customer-A');
                self::fail('Conflicting identity/context was accepted.');
            } catch (OwnershipException) {
                self::assertNull($team->customerMapping()->remote_id);
            }
        }
        self::assertCount(4, $http->requests);
    }

    public function test_customer_create_response_cannot_overwrite_another_existing_mapping(): void
    {
        $one = $this->team('one');
        $two = $this->team('two');
        $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A')]);
        $one->linkAirwallexCustomer('Customer-A');
        try {
            $two->createOrGetAirwallexCustomer('create-two');
            self::fail('Foreign customer was mapped.');
        } catch (AmbiguousOperationException) {
            self::assertNull($two->customerMapping()->remote_id);
            self::assertSame('Customer-A', $one->customerMapping()->remote_id);
        }
    }

    public function test_credential_rotation_keeps_mapping_and_environment_and_org_contexts_are_isolated(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Same-ID'), FakeHttpClient::login(), self::remote('Same-ID'), FakeHttpClient::login(), self::remote('Same-ID')]);
        $sandbox = $team->linkAirwallexCustomer('Same-ID');
        config(['airwallex.api_key' => 'rotated-synthetic']);
        $this->app->forgetScopedInstances();
        self::assertSame($sandbox->id, $team->createOrGetAirwallexCustomer('rotation-get')->id);
        config(['airwallex.environment' => 'production']);
        $this->app->forgetScopedInstances();
        $production = $team->linkAirwallexCustomer('Same-ID');
        config(['airwallex.organization_id' => 'another-org', 'airwallex.api_key' => 'another-org-synthetic']);
        $this->app->forgetScopedInstances();
        $another = $team->linkAirwallexCustomer('Same-ID');
        self::assertCount(3, array_unique([$sandbox->id, $production->id, $another->id]));
        self::assertSame(3, Customer::count());
        self::assertSame('api.airwallex.com', $http->requests[3]->getUri()->getHost());
    }

    public function test_configured_customer_subclasses_and_morph_maps_support_integer_uuid_and_ulid_billables(): void
    {
        Schema::rename('airwallex_customers', 'custom_customers');
        config(['airwallex.models.customer' => CustomerTestCustomMapping::class]);
        Relation::enforceMorphMap(['team' => CustomerTestTeam::class, 'user' => CustomerTestUser::class, 'company' => CustomerTestCompany::class]);
        $team = $this->team('3538ed71-40c7-48e5-99bf-20c057643ca6');
        $user = CustomerTestUser::create(['name' => 'User']);
        $company = CustomerTestCompany::create(['id' => '01JCG5B63M7SW63TAYWH2V4YAX']);
        $this->http([FakeHttpClient::login(), self::remote('team'), self::remote('user'), self::remote('company')]);
        foreach ([$team, $user, $company] as $billable) {
            $customer = $billable->createOrGetAirwallexCustomer('create-'.$billable->getMorphClass());
            self::assertInstanceOf(CustomerTestCustomMapping::class, $customer);
            self::assertSame((string) $billable->getKey(), $customer->billable_id);
            self::assertSame($billable->getMorphClass(), $customer->billable_type);
            self::assertTrue($billable->is($customer->billable));
        }
    }

    public function test_link_cannot_steal_a_create_that_is_already_in_flight(): void
    {
        $team = $this->team();
        $held = false;
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('Customer-A')], function (RequestInterface $request) use ($team, &$held): void {
            self::assertSame(0, DB::transactionLevel());
            if (str_ends_with($request->getUri()->getPath(), '/create')) {
                try {
                    $team->linkAirwallexCustomer('Customer-A');
                    self::fail('Link stole a live create.');
                } catch (ConflictException) {
                    $held = true;
                }
            }
        });
        self::assertSame('Customer-A', $team->createOrGetAirwallexCustomer('create')->remote_id);
        self::assertTrue($held);
        self::assertCount(3, $http->requests);
        self::assertSame(1, Customer::count());
    }

    public function test_competing_create_requests_converge_on_one_confirmed_mapping(): void
    {
        $team = $this->team();
        $blocked = false;
        $http = $this->http([FakeHttpClient::login(), self::remote('Customer-A')], function (RequestInterface $request) use ($team, &$blocked): void {
            if (str_ends_with($request->getUri()->getPath(), '/create')) {
                try {
                    $team->createOrGetAirwallexCustomer('competing-create');
                    self::fail('A competing create acquired the active customer hold.');
                } catch (ConflictException) {
                    $blocked = true;
                }
            }
        });
        $customer = $team->createOrGetAirwallexCustomer('first-create');
        self::assertTrue($blocked);
        self::assertSame($customer->id, $team->createOrGetAirwallexCustomer('competing-create')->id);
        self::assertCount(2, $http->requests);
        self::assertSame(1, Customer::count());
    }

    public function test_link_winning_before_prepare_satisfies_only_the_unsent_create(): void
    {
        $team = $this->team();
        $http = $this->http([FakeHttpClient::login(), self::remote('Linked')]);
        $manager = new class(app(BillingContext::class), fn () => $team->linkAirwallexCustomer('Linked')) extends OperationManager
        {
            public function __construct(BillingContext $context, private ?Closure $hook)
            {
                parent::__construct($context);
            }

            public function prepare(Customer $customer, string $actionKey, string $kind, array $specification, array $intent, ?Subscription $subscription = null, ?string $name = null, ?string $lockKey = null): Operation
            {
                $hook = $this->hook;
                $this->hook = null;
                if ($hook !== null) {
                    $hook();
                }

                return parent::prepare($customer, $actionKey, $kind, $specification, $intent, $subscription, $name, $lockKey);
            }
        };
        $this->app->instance(OperationManager::class, $manager);
        $customer = $team->createOrGetAirwallexCustomer('create');
        self::assertSame('Linked', $customer->remote_id);
        self::assertSame('succeeded', Operation::first()->status);
        self::assertCount(2, $http->requests);
        self::assertSame('GET', $http->requests[1]->getMethod());
    }

    public function test_foreign_create_context_and_wrong_update_identity_remain_ambiguous(): void
    {
        $one = $this->team('one');
        $two = $this->team('two');
        $http = $this->http([FakeHttpClient::login(), self::remote('Wrong-Org', ['organization_id' => 'foreign']), self::remote('Customer-A'), self::remote('Wrong-ID')]);
        try {
            $one->createOrGetAirwallexCustomer('foreign-create');
            self::fail('Foreign context was mapped.');
        } catch (AmbiguousOperationException) {
            self::assertNull($one->customerMapping()->remote_id);
        }
        $two->linkAirwallexCustomer('Customer-A');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $two->syncAirwallexCustomer('wrong-update', ['name' => 'Changed']);
                self::fail('Wrong update identity was accepted.');
            } catch (AmbiguousOperationException) {
                self::assertSame('Customer-A', $two->customerMapping()->remote_id);
            }
        }
        self::assertCount(4, $http->requests);
    }

    public function test_create_winning_during_a_link_read_prevents_late_reassignment(): void
    {
        $team = $this->team();
        $created = false;
        $http = $this->http([FakeHttpClient::login(), self::remote('Created'), self::remote('Linked')], function (RequestInterface $request) use ($team, &$created): void {
            self::assertSame(0, DB::transactionLevel());
            if ($request->getMethod() === 'GET' && ! $created) {
                $created = true;
                $team->createOrGetAirwallexCustomer('create');
            }
        });
        try {
            $team->linkAirwallexCustomer('Linked');
            self::fail('Late link reassigned a confirmed mapping.');
        } catch (OwnershipException) {
            self::assertSame('Created', $team->customerMapping()->remote_id);
        }
        self::assertCount(3, $http->requests);
    }

    public function test_both_case_sensitive_ids_remain_distinct_and_unsaved_models_are_rejected(): void
    {
        $one = $this->team('one');
        $two = $this->team('two');
        $this->http([FakeHttpClient::login(), self::remote('Customer-A'), self::remote('customer-a')]);
        self::assertNotSame($one->linkAirwallexCustomer('Customer-A')->id, $two->linkAirwallexCustomer('customer-a')->id);
        $this->expectException(ConflictException::class);
        (new CustomerTestTeam)->createOrGetAirwallexCustomer('unsaved');
    }

    private function team(string $id = 'team'): CustomerTestTeam
    {
        return CustomerTestTeam::create(['id' => $id, 'name' => 'Team', 'email' => 'team@example.test']);
    }

    private static function remote(string $id, array $extra = []): ResponseInterface
    {
        return FakeHttpClient::json(['id' => $id, 'created_at' => '2026-11-10T12:00:00Z', 'updated_at' => '2026-11-10T12:00:00Z', 'automatic_tax_eligibility' => 'ADDRESS_INCOMPLETE', ...$extra]);
    }

    private function http(array $responses, ?Closure $hook = null): FakeHttpClient
    {
        $fake = new FakeHttpClient($responses);
        $transport = new class($fake, $hook) implements ClientInterface
        {
            public function __construct(private FakeHttpClient $fake, private ?Closure $hook) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->hook !== null) {
                    ($this->hook)($request);
                }

                return $this->fake->sendRequest($request);
            }
        };
        $this->app->bind(ClientInterface::class, fn () => $transport);

        return $fake;
    }
}

class CustomerTestTeam extends Model
{
    use Billable;

    protected $table = 'customer_teams';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function airwallexProfile(): array
    {
        return array_filter(['name' => $this->name, 'email' => $this->email], fn ($value) => $value !== null);
    }
}

class CustomerTestCompany extends CustomerTestTeam
{
    protected $table = 'customer_companies';
}

class CustomerTestUser extends CustomerTestTeam
{
    protected $table = 'customer_users';

    public $incrementing = true;

    protected $keyType = 'int';
}

class CustomerTestCustomMapping extends Customer
{
    protected $table = 'custom_customers';
}

class CustomerTestFaultyMapping extends Customer
{
    public static bool $failNext = false;

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $model): void {
            if (self::$failNext && $model->isDirty('remote_id')) {
                self::$failNext = false;
                throw new RuntimeException('SYNTHETIC-LOCAL-SECRET');
            }
        });
    }
}
