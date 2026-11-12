<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Billing;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;

class SubscriptionNextCycleTest extends TestCase
{
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10T12:00:00Z');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $this->team = Team::create(['id' => 'team']);
        app(OperationManager::class)->reserveCustomer($this->team)->update(['remote_id' => 'customer-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('states')]
    public function test_native_change_is_submitted_before_the_original_paid_or_trial_boundary(string $state): void
    {
        $remote = $this->remote($state);
        $this->http($this->observation($remote));
        $subscription = $this->team->importSubscription('membership', 'sub-test');
        $changed = [...$remote, 'updated_at' => '2026-11-10T12:01:00Z'];
        $http = $this->http([...$this->observation($remote), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($changed), ...$this->observation($changed, 'price-lower')]);
        $result = $subscription->updateItemsForNextCycle('downgrade', [['id' => 'item-test', 'deleted' => true], ['price_id' => 'price-lower', 'quantity' => 1]], '2026-12-01T00:00:00Z');
        self::assertSame($state, $result->status);
        self::assertSame('price-lower', $result->items()->sole()->price_id);
        $body = json_decode((string) $http->requests[5]->getBody(), true);
        self::assertSame('NONE', $body['default_proration_mode']);
        self::assertSame('DEFER_CHARGE_AND_KEEP_CYCLE', $body['billing_action']);
        self::assertArrayNotHasKey('next_cycle_boundary', $body);
        self::assertSame('succeeded', Operation::sole()->status);
        self::assertSame('2026-12-01T00:00:00Z', Operation::sole()->intent['next_cycle_boundary']);
        $this->http([]);
        $result->updateItemsForNextCycle('downgrade', [['id' => 'item-test', 'deleted' => true], ['price_id' => 'price-lower', 'quantity' => 1]], '2026-12-01T00:00:00Z');
    }

    public static function states(): array
    {
        return [['ACTIVE'], ['IN_TRIAL']];
    }

    public function test_an_elapsed_or_changed_boundary_cannot_silently_target_another_invoice(): void
    {
        $remote = $this->remote();
        $this->http($this->observation($remote));
        $subscription = $this->team->importSubscription('membership', 'sub-test');
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        $http = $this->http($this->observation($remote));
        try {
            $subscription->updateItemsForNextCycle('late', [['id' => 'item-test', 'quantity' => 2]], '2026-12-01T00:00:00Z');
            self::fail('An elapsed boundary targeted the following invoice.');
        } catch (EvidenceException) {
            self::assertSame(0, Operation::count());
            self::assertCount(4, $http->requests);
        }
    }

    public function test_source_preflight_crossing_the_boundary_refuses_the_first_write(): void
    {
        $remote = $this->remote();
        $this->http($this->observation($remote));
        $subscription = $this->team->importSubscription('membership', 'sub-test');
        Carbon::setTestNow('2026-11-30T23:59:59Z');
        $http = new InterleavingHttpClient([FakeHttpClient::login(), ...$this->observation($remote), function () {
            Carbon::setTestNow('2026-12-01T00:00:00Z');

            return FakeHttpClient::json(Billing::source());
        }]);
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);
        try {
            $subscription->updateItemsForNextCycle('crossed', [['id' => 'item-test', 'quantity' => 2]], '2026-12-01T00:00:00Z');
            self::fail('Source verification crossed the financial boundary.');
        } catch (EvidenceException) {
            self::assertSame('failed', Operation::sole()->status);
            self::assertCount(5, $http->requests);
        }
    }

    public function test_accepted_request_recovers_after_renewal_without_another_write(): void
    {
        $remote = $this->remote();
        $this->http($this->observation($remote));
        $subscription = $this->team->importSubscription('membership', 'sub-test');
        $changed = [...$remote, 'updated_at' => '2026-11-10T12:01:00Z'];
        $this->http([...$this->observation($remote), FakeHttpClient::json(Billing::source()), FakeHttpClient::json($changed), FakeHttpClient::json(['code' => 'unavailable'], 503)]);
        try {
            $subscription->updateItemsForNextCycle('recover', [['id' => 'item-test', 'quantity' => 2]], '2026-12-01T00:00:00Z');
            self::fail('Failed readback confirmed an item mutation.');
        } catch (AmbiguousOperationException) {
            self::assertSame('ambiguous', Operation::sole()->status);
        }
        Carbon::setTestNow('2026-12-02T00:00:00Z');
        $http = $this->http([FakeHttpClient::json($changed), Billing::page([Billing::item(['quantity' => '2', 'updated_at' => $changed['updated_at']])]), FakeHttpClient::json($changed)]);
        $subscription->updateItemsForNextCycle('recover', [['id' => 'item-test', 'quantity' => 2]], '2026-12-01T00:00:00Z');
        self::assertSame('succeeded', Operation::sole()->status);
        self::assertCount(4, $http->requests);
    }

    private function remote(string $state = 'ACTIVE'): array
    {
        return Billing::subscription(['status' => $state, 'collection_method' => 'AUTO_CHARGE', 'payment_source_id' => 'source-test', 'current_period_starts_at' => '2026-11-01T00:00:00Z', 'current_period_ends_at' => '2026-12-01T00:00:00Z', ...($state === 'IN_TRIAL' ? ['trial_starts_at' => '2026-11-01T00:00:00Z', 'trial_ends_at' => '2026-12-01T00:00:00Z'] : [])]);
    }

    private function observation(array $remote, string $price = 'price-test'): array
    {
        $item = Billing::item(['quantity' => '1', 'updated_at' => $remote['updated_at'], 'price' => ['id' => $price, 'pricing_model' => 'PER_UNIT', 'metered' => false]]);
        if ($price !== 'price-test') {
            $item['id'] = 'item-lower';
        }

        return [FakeHttpClient::json($remote), Billing::page([$item]), FakeHttpClient::json($remote)];
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
