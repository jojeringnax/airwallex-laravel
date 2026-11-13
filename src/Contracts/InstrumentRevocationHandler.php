<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Contracts;

use Jojeringnax\AirwallexLaravel\Events\PaymentObserved;

/** Consumer policy; deliveries can repeat, so use the event ID in durable action keys. */
interface InstrumentRevocationHandler
{
    public function handle(PaymentObserved $event): void;
}
