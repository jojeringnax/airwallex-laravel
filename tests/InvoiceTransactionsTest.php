<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Resource\Billing;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Models\DomainEvent;
use Jojeringnax\AirwallexLaravel\Services\OperationManager;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\InterleavingHttpClient;
use Jojeringnax\AirwallexLaravel\Tests\Fixtures\Team;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class InvoiceTransactionsTest extends TestCase
{
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        if (! method_exists(Billing::class, 'transactions')) {
            self::markTestSkipped('Requires exact SDK PR160 archive4d24a1a; final released-SDK qualification remains pending.');
        }
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Schema::create('local_teams', fn (Blueprint $table) => $table->string('id')->primary());
        $this->team = Team::create(['id' => 'team']);
        app(OperationManager::class)->reserveCustomer($this->team)->update(['remote_id' => 'customer-test']);
    }

    public function test_multiple_precise_observations_keep_payment_refund_pending_and_out_of_band_distinct(): void
    {
        $records = [self::transaction(), self::transaction(['id' => 'partial-two', 'amount' => '0.000000000000000001', 'status' => 'CREATED']),
            self::transaction(['id' => 'refund', 'type' => 'REFUND', 'status' => 'FAILED', 'external_id' => 'refund-external']),
            self::transaction(['id' => 'manual', 'out_of_band' => true]), self::transaction(['id' => 'future', 'status' => 'FUTURE_STATUS', 'type' => 'FUTURE_TYPE'])];
        $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response(['items' => $records])]);
        $invoice = $this->team->findInvoice('invoice-test');
        $transactions = $invoice->transactions();
        self::assertCount(5, $transactions);
        self::assertSame('9007199254740993.000000000000000001', $transactions[0]->amount);
        self::assertSame('0.000000000000000001', $transactions[1]->amount);
        self::assertSame('CREATED', $transactions[1]->status);
        self::assertSame('REFUND', $transactions[2]->type);
        self::assertSame('FAILED', $transactions[2]->status);
        self::assertTrue($transactions[3]->outOfBand);
        self::assertSame('FUTURE_STATUS', $transactions[4]->status);
        $ordinary = json_encode($transactions[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE-PROFILE', $ordinary);
        self::assertStringNotContainsString('CLIENT-SECRET', $ordinary);
        self::assertSame('1.000000000000000001', $transactions[0]->toArray()['future_amount']);
        self::assertArrayNotHasKey('client_secret', $transactions[0]->toArray());
        self::assertSame('2026-11-11T01:02:03.123456789+01:00', $transactions[0]->toArray()['succeeded_at']);
    }

    public function test_distinct_invoice_observations_deduplicate_by_financial_identity_without_inventing_allocations(): void
    {
        $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response(self::transaction()),
            self::response(self::invoice()), self::response(self::transaction())]);
        $invoice = $this->team->findInvoice('invoice-test');
        $first = $invoice->transaction('transaction-test');
        $second = $invoice->transaction('transaction-test');
        self::assertSame($first->identityKey(), $second->identityKey());
        self::assertSame(3, DomainEvent::count());
        self::assertCount(3, DomainEvent::pluck('id')->unique());
        // Application-side synthetic example: allocations are separately supplied, never copied from total amount.
        $posted = [];
        foreach ([$first, $second] as $observation) {
            $allocation = ['transaction-test' => '30.00'][$observation->id] ?? null;
            if ($allocation !== null && $observation->type === 'PAYMENT' && $observation->status === 'SUCCEEDED' && $observation->outOfBand === false) {
                $posted[$observation->identityKey()] = $allocation;
            }
        }
        self::assertSame([$first->identityKey() => '30.00'], $posted);
        self::assertArrayNotHasKey('allocated_amount', $first->toArray());
    }

    public function test_pages_keep_owned_parent_and_no_partial_result_survives_a_foreign_later_page(): void
    {
        $http = $this->http([self::response(self::invoice()), self::response(self::invoice()),
            self::response(['items' => [self::transaction()], 'page_after' => 'next /+']),
            self::response(['items' => [self::transaction(['id' => 'foreign', 'invoice_id' => 'other'])]])]);
        $invoice = $this->team->findInvoice('invoice-test');
        try {
            $invoice->transactions(['page_size' => 1]);
            self::fail('Foreign later page yielded partial observations.');
        } catch (OwnershipException) {
            self::assertSame(1, DomainEvent::count());
            self::assertStringContainsString('invoice_id=invoice-test', $http->requests[3]->getUri()->getQuery());
            self::assertStringContainsString('invoice_id=invoice-test', $http->requests[4]->getUri()->getQuery());
            self::assertStringContainsString('page=next%20%2F%2B', $http->requests[4]->getUri()->getQuery());
        }
    }

    #[DataProvider('foreignTransactions')]
    public function test_direct_reads_require_invoice_customer_collector_context_and_exact_identity(array $changes): void
    {
        $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response(self::transaction($changes))]);
        $invoice = $this->team->findInvoice('invoice-test');
        $this->expectException(OwnershipException::class);
        $invoice->transaction('transaction-test');
    }

    public static function foreignTransactions(): array
    {
        return [[['invoice_id' => 'other']], [['invoice_id' => null]], [['billing_customer_id' => 'other']],
            [['linked_payment_account_id' => 'other']], [['org_id' => 'other']], [['id' => 'other']]];
    }

    public function test_optional_linkage_omission_remains_missing_and_empty_paid_invoice_has_no_cash_transaction(): void
    {
        $record = self::transaction();
        unset($record['external_id'], $record['type'], $record['linked_payment_account_id'], $record['out_of_band']);
        $this->http([self::response(self::invoice()), self::response(self::invoice()), self::response($record),
            self::response(self::invoice()), self::response(['items' => []])]);
        $invoice = $this->team->findInvoice('invoice-test');
        $observation = $invoice->transaction('transaction-test');
        self::assertNull($observation->externalId);
        self::assertNull($observation->outOfBand);
        self::assertNull($observation->type);
        self::assertNull($observation->linkedPaymentAccountId);
        self::assertArrayNotHasKey('external_id', $observation->toArray());
        self::assertCount(0, $invoice->transactions());
    }

    public function test_repeated_identity_or_malformed_list_cannot_be_complete_transaction_evidence(): void
    {
        $this->http([self::response(self::invoice()), self::response(self::invoice()),
            self::response(['items' => [self::transaction()], 'page_after' => 'next']),
            self::response(['items' => [self::transaction()]])]);
        $invoice = $this->team->findInvoice('invoice-test');
        $this->expectException(EvidenceException::class);
        $invoice->transactions();
    }

    public function test_mapping_changed_during_transaction_io_is_rechecked_before_returning_observations(): void
    {
        $http = new InterleavingHttpClient([FakeHttpClient::login(), self::response(self::invoice()), self::response(self::invoice()), function (): ResponseInterface {
            self::assertSame(0, DB::transactionLevel());
            DB::table('airwallex_customers')->update(['remote_id' => 'changed']);

            return self::response(['items' => [self::transaction()]]);
        }]);
        $this->bindHttp($http);
        $invoice = $this->team->findInvoice('invoice-test');
        $this->expectException(OwnershipException::class);
        $invoice->transactions();
    }

    private static function invoice(): array
    {
        return ['id' => 'invoice-test', 'billing_customer_id' => 'customer-test', 'status' => 'FINALIZED', 'payment_status' => 'PAID',
            'currency' => 'USD', 'created_at' => '2026-11-11T00:00:00Z', 'updated_at' => '2026-11-11T00:00:00Z',
            'linked_payment_account_id' => 'collector-test', 'pdf_url' => null, 'hosted_url' => null];
    }

    private static function transaction(array $changes = []): array
    {
        return array_replace(['id' => 'transaction-test', 'invoice_id' => 'invoice-test', 'billing_customer_id' => 'customer-test',
            'amount' => '9007199254740993.000000000000000001', 'currency' => 'USD', 'type' => 'PAYMENT', 'status' => 'SUCCEEDED',
            'out_of_band' => false, 'external_id' => 'intent-external', 'linked_payment_account_id' => 'collector-test',
            'created_at' => '2026-11-11T00:00:00Z', 'updated_at' => '2026-11-11T00:00:00Z',
            'succeeded_at' => '2026-11-11T01:02:03.123456789+01:00', 'client_secret' => 'CLIENT-SECRET',
            'customer_profile' => ['email' => 'PRIVATE-PROFILE'], 'future_amount' => '1.000000000000000001'], $changes);
    }

    private static function response(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $json = preg_replace('/("(?:amount|future_amount)":)"(-?[0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }

    private function http(array $responses): FakeHttpClient
    {
        $http = new FakeHttpClient([FakeHttpClient::login(), ...$responses]);
        $this->bindHttp($http);

        return $http;
    }

    private function bindHttp(ClientInterface $http): void
    {
        app('cache')->flush();
        $this->app->bind(ClientInterface::class, fn () => $http);
        $this->app->forgetInstance(Client::class);
    }
}
