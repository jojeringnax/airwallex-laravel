<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Fixtures;

use Closure;
use GuzzleHttp\Psr7\Response;
use Jojeringnax\AirwallexLaravel\Tests\Support\FakeHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Synthetic API2026-08-21 state; amounts below are fixtures, never provider calculations. */
final class CreditNoteHttpClient implements ClientInterface
{
    public array $requests = [];

    public ?Closure $beforeRequest = null;

    public ?Closure $afterWrite = null;

    public array $invoice;

    public array $note = [];

    public array $lines = [];

    public string $creditAmount = '15.00';

    public function __construct()
    {
        $this->invoice = [
            'id' => 'invoice-test', 'billing_customer_id' => 'customer-test', 'subscription_id' => 'sub-historical',
            'status' => 'FINALIZED', 'payment_status' => 'UNPAID', 'currency' => 'USD',
            'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-13T00:00:00Z',
            'amount_due' => '30.00', 'total_amount' => '30.00', 'total_tax_amount' => '0',
            'credit_note_amount_before_payment' => '0', 'credit_note_amount_after_payment' => '0',
            'applied_discounts' => [], 'total_discount_amounts' => [], 'automatic_tax_status' => 'DISABLED',
            'enable_automatic_tax' => false, 'paid_out_of_band' => false,
        ];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->beforeRequest !== null) {
            $response = ($this->beforeRequest)($request, $this);
            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }
        $path = $request->getUri()->getPath();
        if (str_ends_with($path, '/authentication/login')) {
            return FakeHttpClient::login();
        }
        if ($request->getMethod() === 'GET') {
            if ($path === '/api/v1/billing/billing_customers/customer-test') {
                return self::json(['id' => 'customer-test']);
            }
            if ($path === '/api/v1/billing/invoices/invoice-test') {
                return self::json($this->invoice);
            }
            if ($path === '/api/v1/billing/invoices/invoice-test/line_items/original-line') {
                return self::json(['id' => 'original-line', 'invoice_id' => 'invoice-test', 'currency' => 'USD', 'amount' => '30.00', 'remaining_creditable_amount' => '30.00', 'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-01T00:00:00Z', 'period_starts_at' => '2026-11-01T00:00:00Z', 'period_ends_at' => '2026-12-01T00:00:00Z', 'applied_discounts' => [], 'discount_amounts' => []]);
            }
            if ($path === '/api/v1/billing/credit_notes/credit-test') {
                return self::json($this->note);
            }
            if ($path === '/api/v1/billing/credit_notes/credit-test/line_items') {
                return self::json(['items' => array_values($this->lines)]);
            }
        }
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($path === '/api/v1/billing/credit_notes/create') {
            $this->note = [
                'id' => 'credit-test', 'invoice_id' => $body['invoice_id'], 'billing_customer_id' => 'customer-test', 'currency' => 'USD',
                'type' => $body['type'], 'reason' => $body['reason'], 'status' => 'DRAFT', 'metadata' => $body['metadata'],
                'number' => 'CREDIT-SYNTHETIC', 'created_at' => '2026-11-13T00:00:00Z', 'updated_at' => '2026-11-13T00:00:00Z',
                'total_amount' => '0', 'total_tax_amount' => '0', 'total_discount_amounts' => [],
                'adjustment_amount' => '0', 'refund_amount' => '0', 'out_of_band_amount' => '0', 'refund_status' => null,
            ];
        } elseif ($path === '/api/v1/billing/credit_notes/credit-test/add_line_items') {
            foreach ($body['line_items'] as $index => $item) {
                $id = 'credit-line-'.count($this->lines);
                $this->lines[$id] = ['id' => $id, 'credit_note_id' => 'credit-test', 'invoice_line_item_id' => $item['invoice_line_item_id'], 'currency' => 'USD', 'amount' => $this->creditAmount, 'metadata' => $item['metadata'], 'created_at' => '2026-11-13T00:00:00Z', 'updated_at' => '2026-11-13T00:00:00Z', 'discount_amounts' => [], 'tax_breakdown' => [], 'tax_percent' => '0', 'tax_amount' => '0'];
            }
            $this->note['total_amount'] = $this->creditAmount;
        } elseif ($path === '/api/v1/billing/credit_notes/credit-test/finalize') {
            $this->note['status'] = 'FINALIZED';
            if ($this->note['type'] === 'BEFORE_PAYMENT') {
                $this->note['adjustment_amount'] = $this->creditAmount;
                $this->invoice['amount_due'] = '15.00';
                $this->invoice['credit_note_amount_before_payment'] = $this->creditAmount;
            } else {
                $this->note['refund_amount'] = (string) $body['refund_amount'];
                $this->note['out_of_band_amount'] = (string) $body['out_of_band_amount'];
                $this->note['refund_status'] = 'CREATED';
            }
        } else {
            throw new RuntimeException('Unexpected synthetic Credit Note request.');
        }
        if ($this->afterWrite !== null) {
            ($this->afterWrite)($request, $this);
        }

        return self::json($this->note, str_ends_with($path, '/create') ? 201 : 200);
    }

    public static function json(array $data, int $status = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $json = preg_replace('/("(?:amount|amount_due|total_amount|total_tax_amount|credit_note_amount_before_payment|credit_note_amount_after_payment|remaining_creditable_amount|tax_percent|tax_amount|adjustment_amount|refund_amount|out_of_band_amount)":)"([0-9]+(?:\.[0-9]+)?)"/', '$1$2', $json);

        return new Response($status, ['Content-Type' => 'application/json'], $json);
    }
}
