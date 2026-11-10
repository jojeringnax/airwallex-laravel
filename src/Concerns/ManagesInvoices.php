<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Concerns;

use Illuminate\Support\Collection;
use Jojeringnax\AirwallexLaravel\Invoice;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;
use Jojeringnax\AirwallexLaravel\Services\InvoiceManager;

trait ManagesInvoices
{
    public function findInvoice(string $id): Invoice
    {
        return app(InvoiceManager::class)->retrieve(app(CustomerManager::class)->requireCustomer($this), $id);
    }

    /** @return Collection<int,Invoice> */
    public function invoices(array $query = []): Collection
    {
        return app(InvoiceManager::class)->all(app(CustomerManager::class)->requireCustomer($this), $query);
    }
}
