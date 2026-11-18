<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use GuzzleHttp\Psr7\Response;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Reconciliation;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;

final class PaymentsReconciliationTest extends TestCase
{
    public function test_settlement_items_balance_and_wallet_sources_trace_separate_payout(): void
    {
        config(['airwallex.preserve_response_objects' => true]);
        $http = $this->transport([
            '{"items":[{"settlement_id":"batch-eur","amount":73.00,"fee":3.50,"currency":"EUR","status":"SETTLED"}],"has_more":true}',
            '{"items":[],"has_more":false}',
            '{"settlement_id":"batch-eur","amount":73.00,"fee":3.50,"currency":"EUR","status":"SETTLED"}',
            <<<'JSON'
            {"items":[
                {"account_id":"merchant","settlement_batch_id":"batch-eur","transaction_id":"tx-payment","transaction_type":"Payment",
                 "source_id":"att-1","source_entity":"PaymentAttempt","payment_attempt_ids":["att-1"],"payment_intent_id":"int-1",
                 "settlement_currency":"EUR","transaction_currency":"USD","transaction_amount":108.66,"exchange_rate":0.920301859744155,
                 "gross_amount":100.00,"fees":3.00,"taxes_on_fees":0.50,"net_amount":96.50,"customer_email":"private@example.test",
                 "fee_details_list":[{"fee_type":"Gateway fee","fee_currency":"EUR","fee_subtotal_amount":3.00,
                   "settlement_currency":"EUR","settlement_subtotal_amount":3.00,"settlement_tax_amount":0.50,"settlement_total_amount":3.50}]},
                {"account_id":"merchant","settlement_batch_id":"batch-eur","transaction_id":"tx-refund","transaction_type":"Refund",
                 "source_id":"ref-1","source_entity":"Refund","payment_intent_id":"int-1","settlement_currency":"EUR",
                 "gross_amount":-20.00,"fees":0.50,"taxes_on_fees":0.00,"net_amount":-20.50}
            ],"has_more":true,"page_after":"next+1"}
            JSON,
            <<<'JSON'
            {"items":[
                {"account_id":"merchant","settlement_batch_id":"batch-eur","transaction_id":"tx-hold","transaction_type":"Payment Reserve Hold",
                 "settlement_currency":"EUR","net_amount":-5.00},
                {"account_id":"merchant","settlement_batch_id":"batch-eur","transaction_id":"tx-release","transaction_type":"Payment Reserve Release",
                 "settlement_currency":"EUR","net_amount":2.00}
            ],"has_more":false,"page_after":null}
            JSON,
            <<<'JSON'
            {"items":[
                {"id":"wallet-settle","currency":"EUR","amount":73.00,"balance":83.00,"source":"batch-eur","source_type":"PAYMENT_SETTLEMENT"},
                {"id":"wallet-adjust","currency":"EUR","amount":-1.25,"balance":81.75,"source_type":"ADJUSTMENT","transaction_type":"ADJUSTMENT"},
                {"id":"wallet-fx","currency":"EUR","amount":-1.75,"balance":80.00,"source_type":"CONVERSION","transaction_type":"CONVERSION_SELL"},
                {"id":"wallet-payout","currency":"EUR","amount":-80.00,"balance":0.00,"source":"transfer-1","source_type":"PAYOUT","transaction_type":"PAYOUT"}
            ],"has_more":false,"page_after":null}
            JSON,
            <<<'JSON'
            {"id":"transfer-1","transfer_method":"LOCAL","status":"PAID","source_amount":79.50,"source_currency":"EUR","transfer_amount":85.87,
             "transfer_currency":"USD","amount_payer_pays":80.00,"amount_beneficiary_receives":85.87,"fee_amount":0.50,"fee_currency":"EUR",
             "conversion":{"currency_pair":"EURUSD","rate":1.0801257862},"dispatch_info":{"external_reference":"bank-reference"},
             "funding":{"status":"FUNDED","funding_source_id":null},"beneficiary":{"name":"private@example.test"}}
            JSON,
        ]);
        $reads = app(Reconciliation::class);

        $first = $reads->settlements('EUR', '2026-11-01', '2026-11-30', 'SETTLED');
        self::assertSame(1, $first['next_page']);
        self::assertSame(['items' => [], 'next_page' => null], $reads->settlements('EUR', '2026-11-01', '2026-11-30', 'SETTLED', page: 1));
        $batch = $reads->settlement($first['items'][0]['settlement_id']);
        $records = $reads->settlementRecords($batch['settlement_id']);
        $remainder = $reads->settlementRecords($batch['settlement_id'], $records['next_page']);

        $rows = [...$records['items'], ...$remainder['items']];
        self::assertSame(['credit', 'debit', 'debit', 'credit'], array_column($rows, 'direction'));
        self::assertSame('3.50', $rows[0]['fee_details_list'][0]['settlement_total_amount']);
        self::assertSame('0.920301859744155', $rows[0]['exchange_rate']);
        self::assertSame('ref-1', $rows[1]['source_id']);
        self::assertArrayNotHasKey('customer_email', $rows[0]);
        $cents = static fn (string $value): int => (int) str_replace('.', '', $value);
        self::assertSame($cents($batch['amount']), array_sum(array_map($cents, array_column($rows, 'net_amount'))));
        self::assertNull($remainder['next_page']);

        $wallet = $reads->walletActivity('EUR')['items'];
        self::assertSame($batch['settlement_id'], $wallet[0]['source']);
        self::assertSame('ADJUSTMENT', $wallet[1]['transaction_type']);
        self::assertSame('CONVERSION_SELL', $wallet[2]['transaction_type']);
        $payout = $reads->bankPayout($wallet[3]['source']);
        self::assertSame('PAYOUT', $wallet[3]['source_type']);
        self::assertSame('80.00', $payout['amount_payer_pays']);
        self::assertSame('85.87', $payout['amount_beneficiary_receives']);
        self::assertSame('1.0801257862', $payout['conversion']['rate']);
        self::assertSame('bank-reference', $payout['dispatch_info']['external_reference']);
        self::assertSame('debit', $payout['direction']);
        self::assertArrayNotHasKey('beneficiary', $payout);
        self::assertArrayNotHasKey('settlement_id', $payout);

        self::assertSame('merchant', $http->requests[0]->getHeaderLine('x-login-as'));
        self::assertSame('currency=EUR&from_settled_at=2026-11-01&to_settled_at=2026-11-30&status=SETTLED&page_num=1&page_size=100', $http->requests[2]->getUri()->getQuery());
        self::assertSame('settlement_id=batch-eur&page_size=50&page=next%2B1', $http->requests[5]->getUri()->getQuery());
        self::assertSame('account_type=cash&currency=EUR&page=0&page_size=100', $http->requests[6]->getUri()->getQuery());
        self::assertSame('/api/v1/transfers/transfer-1', $http->requests[7]->getUri()->getPath());
    }

    public function test_pending_and_amended_reads_keep_the_original_id_and_replay_is_repeatable(): void
    {
        $row = '{"account_id":"merchant","settlement_batch_id":"batch","transaction_id":"tx","transaction_type":"Refund","settlement_currency":"EUR","net_amount":-12.00}';
        $page = '{"items":['.$row.'],"has_more":false}';
        $this->transport([
            '{"settlement_id":"batch","currency":"EUR","amount":17.00,"status":"PENDING"}',
            '{"settlement_id":"batch","currency":"EUR","amount":15.00,"status":"SETTLED","settled_at":"2026-11-13"}',
            $page,
            $page,
        ]);
        $reads = app(Reconciliation::class);

        $pending = $reads->settlement('batch');
        $settled = $reads->settlement('batch');
        self::assertSame('PENDING', $pending['status']);
        self::assertArrayNotHasKey('settled_at', $pending);
        self::assertSame($pending['settlement_id'], $settled['settlement_id']);
        self::assertSame('15.00', $settled['amount']);
        self::assertSame($reads->settlementRecords('batch'), $reads->settlementRecords('batch'));
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_ownership_identity_and_cursors_fail_closed(string $response, string $exception): void
    {
        $this->transport([$response]);
        $this->expectException($exception);

        app(Reconciliation::class)->settlementRecords('batch', 'previous');
    }

    public static function invalidRows(): array
    {
        return [
            'foreign merchant' => ['{"items":[{"account_id":"foreign","settlement_batch_id":"batch","transaction_id":"tx"}],"has_more":false}', OwnershipException::class],
            'wrong batch' => ['{"items":[{"account_id":"merchant","settlement_batch_id":"wrong","transaction_id":"tx"}],"has_more":false}', OwnershipException::class],
            'no account' => ['{"items":[{"settlement_batch_id":"batch","transaction_id":"tx"}],"has_more":false}', OwnershipException::class],
            'no transaction' => ['{"items":[{"account_id":"merchant","settlement_batch_id":"batch"}],"has_more":false}', EvidenceException::class],
            'no cursor' => ['{"items":[],"has_more":true}', EvidenceException::class],
            'same cursor' => ['{"items":[],"has_more":true,"page_after":"previous"}', EvidenceException::class],
        ];
    }

    public function test_foreign_transfer_is_rejected_before_projecting_sensitive_parties(): void
    {
        $this->transport(['{"id":"transfer","account_id":"foreign","beneficiary":{"name":"private@example.test"}}']);
        $this->expectException(OwnershipException::class);

        app(Reconciliation::class)->bankPayout('transfer');
    }

    /** @param list<string> $responses */
    private function transport(array $responses): FakeHttpClient
    {
        config([
            'airwallex.payments.environment' => 'sandbox',
            'airwallex.payments.account_id' => 'merchant',
            'airwallex.payments.client_id' => 'client-fixture',
            'airwallex.payments.api_key' => 'key-fixture',
        ]);
        $http = new FakeHttpClient([FakeHttpClient::login(), ...array_map(
            static fn (string $body): Response => new Response(200, ['Content-Type' => 'application/json'], $body),
            $responses,
        )]);
        $this->app->instance(ClientInterface::class, $http);

        return $http;
    }
}
