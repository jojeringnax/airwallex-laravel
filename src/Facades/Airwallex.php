<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Facades;

use Illuminate\Support\Facades\Facade;
use Jojeringnax\Airwallex\Client;

/**
 * @method static \Jojeringnax\Airwallex\Resource\Billing billing()
 * @method static \Jojeringnax\Airwallex\Resource\Payments payments()
 *
 * @see Client
 */
class Airwallex extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
