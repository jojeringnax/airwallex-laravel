<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Jojeringnax\Airwallex\Resource\Billing;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Invoice;

class InvoiceTransactionsCompatibilityTest extends TestCase
{
    public function test_released_baseline_exposes_a_clear_prerequisite_without_performing_http(): void
    {
        if (method_exists(Billing::class, 'transactions')) {
            self::markTestSkipped('Selected SDK extension installed; baseline guard is covered on released v0.1.1.');
        }
        $invoice = new Invoice('invoice', 'customer', null, app(BillingContext::class), 'local', null, []);
        $this->expectException(ConfigurationException::class);
        $invoice->transactions();
    }
}
