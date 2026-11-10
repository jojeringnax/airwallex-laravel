<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Jojeringnax\AirwallexLaravel\Casts\LosslessJson;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Jojeringnax\AirwallexLaravel\Timestamp;

/** @property string $remote_id
 * @property array<string,mixed> $snapshot
 * @property ?string $provider_updated_at
 */
class SubscriptionItem extends BillingModel
{
    protected $table = 'airwallex_subscription_items';

    protected $casts = ['snapshot' => LosslessJson::class];

    protected function immutable(): array
    {
        return ['id', 'subscription_id', 'remote_id'];
    }

    public function canMutate(): bool
    {
        foreach (['created_at', 'updated_at'] as $key) {
            $value = $this->snapshot[$key] ?? null;
            if (! is_string($value)) {
                return false;
            }
            try {
                Timestamp::compare($value, $value);
            } catch (EvidenceException) {
                return false;
            }
        }
        $price = $this->snapshot['price'] ?? null;

        return is_array($price) && is_string($price['id'] ?? null) && $price['id'] !== ''
            && in_array($price['pricing_model'] ?? null, ['FLAT', 'PER_UNIT', 'VOLUME', 'GRADUATED'], true)
            && ($price['metered'] ?? null) === false
            && ($this->snapshot['rate_card_version'] ?? null) === null;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class('subscription'), 'subscription_id');
    }
}
