<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Customer;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\CheckoutManager;
use Jojeringnax\AirwallexLaravel\Services\CustomerManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionSynchronizer;
use Throwable;

class ReconcileCommand extends BillingCommand
{
    protected $signature = 'airwallex:reconcile {--type=all : all, customer, subscription or checkout} {--limit=100 : Maximum rows per resource type} {--after= : Resume one resource type after this local ID}';

    protected $description = 'Refresh known owned resources using canonical reads only';

    public function handle(): int
    {
        try {
            $limit = $this->batchLimit();
            $type = $this->option('type');
            if (! in_array($type, ['all', 'customer', 'subscription', 'checkout'], true) || ($type === 'all' && $this->option('after') !== null)) {
                throw new ConflictException('Choose one resource type when resuming with --after.');
            }
            $types = $type === 'all' ? ['customer', 'subscription', 'checkout'] : [$type];
            $failed = false;
            foreach ($types as $selected) {
                $query = $this->scoped($selected);
                if ($selected === 'customer') {
                    $query->whereNotNull('remote_id');
                }
                $query->limit($limit);
                $rows = $query->get();
                foreach ($rows as $row) {
                    try {
                        match (true) {
                            $row instanceof Customer => app(CustomerManager::class)->reconcile($row),
                            $row instanceof Subscription => app(SubscriptionSynchronizer::class)->reconcile($row),
                            $row instanceof Checkout => app(CheckoutManager::class)->reconcile($row),
                            default => throw new ConflictException('Unsupported configured resource model.'),
                        };
                        $this->reportRow(['type' => $selected, 'id' => $row->getKey(), 'outcome' => 'reconciled', 'after' => $row->getKey()]);
                    } catch (Throwable $error) {
                        $failed = true;
                        $this->reportRow(['type' => $selected, 'id' => $row->getKey(), 'outcome' => $this->classification($error), 'after' => $row->getKey()]);
                    }
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
