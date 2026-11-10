<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Billable;

class Team extends Model
{
    use Billable;

    protected $table = 'local_teams';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}
