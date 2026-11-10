<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;

/**
 * @property string $id
 * @property string $environment
 * @property string $organization_id
 */
abstract class BillingModel extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['snapshot', 'intent', 'url', 'body', 'payload'];

    /** @return list<string> */
    protected function immutable(): array
    {
        return ['environment', 'organization_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach ($model->immutable() as $key) {
                if ($model->isDirty($key) && $model->getRawOriginal($key) !== null) {
                    throw new OwnershipException('Billing identity cannot be reassigned.');
                }
            }
        });
    }

    public function freshTimestamp()
    {
        return parent::freshTimestamp()->utc();
    }

    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/D', $value)) {
            return Carbon::parse($value, 'UTC');
        }

        return parent::asDateTime($value)->utc();
    }

    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['model' => static::class, 'id' => $this->getKey(), 'environment' => $this->getAttribute('environment')];
    }
}
