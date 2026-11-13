<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\Airwallex\Exception\ValidationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Checkout;
use Jojeringnax\AirwallexLaravel\Models\Subscription;
use Jojeringnax\AirwallexLaravel\Services\CheckoutManager;
use Jojeringnax\AirwallexLaravel\Services\SubscriptionManager;

class SubscriptionBuilder
{
    /** @var list<array{price_id:string,quantity:mixed}> */
    private array $items;

    private ?string $couponId = null;

    private ?int $trialDays = null;

    private ?string $trialEndsAt = null;

    private ?string $startsAt = null;

    public function __construct(private readonly Model $model, private readonly string $subscriptionName, string $priceId)
    {
        if ($subscriptionName === '' || strlen($subscriptionName) > 255) {
            throw new ConflictException('An explicit bounded subscription name is required.');
        }
        $this->items = [['price_id' => $priceId, 'quantity' => 1]];
    }

    public function withCoupon(string $couponId): static
    {
        if ($couponId === '') {
            throw new ConflictException('A Coupon ID is required.');
        }
        if ($this->couponId !== null && $this->couponId !== $couponId) {
            throw new ConflictException('Coupon stacking requires a separately qualified strategy.');
        }
        $this->couponId = $couponId;

        return $this;
    }

    public function quantity(mixed $quantity): static
    {
        if (! is_int($quantity)) {
            throw new ValidationException('Quantity must be a native integer.');
        }
        $this->items[0]['quantity'] = $quantity;

        return $this;
    }

    public function addPrice(string $priceId, mixed $quantity = 1): static
    {
        if (! is_int($quantity)) {
            throw new ValidationException('Quantity must be a native integer.');
        }
        $this->items[] = ['price_id' => $priceId, 'quantity' => $quantity];

        return $this;
    }

    public function trialDays(mixed $days): static
    {
        if ($this->trialEndsAt !== null) {
            throw new ConflictException('Choose trialDays or trialUntil for one subscription.');
        }
        if (! is_int($days) || $days < 1) {
            throw new ConflictException('Provider trial days must be positive.');
        }
        $this->trialDays = $days;

        return $this;
    }

    public function trialUntil(string|DateTimeInterface $endsAt): static
    {
        if ($this->trialDays !== null) {
            throw new ConflictException('Choose trialDays or trialUntil for one subscription.');
        }
        $value = is_string($endsAt) ? $endsAt : $endsAt->format('Y-m-d\TH:i:s.uP');
        Timestamp::compare($value, $value);
        $this->trialEndsAt = $value;

        return $this;
    }

    public function startsAt(string|DateTimeInterface $startsAt): static
    {
        $this->startsAt = is_string($startsAt) ? $startsAt : $startsAt->format('Y-m-d\TH:i:s.uP');

        return $this;
    }

    /** @param array<string,mixed> $options */
    public function create(string $actionKey, array $options = []): Subscription
    {
        return app(SubscriptionManager::class)->create($this, $actionKey, $options);
    }

    /** @param array<string,mixed> $options */
    public function checkout(string $actionKey, array $options = []): Checkout
    {
        return app(CheckoutManager::class)->subscription($this, $actionKey, $options);
    }

    /** @internal */
    public function billable(): Model
    {
        return $this->model;
    }

    /** @internal */
    public function name(): string
    {
        return $this->subscriptionName;
    }

    /** @internal @return array{items:list<array{price_id:string,quantity:mixed}>,trial_days:?int,starts_at:?string,trial_ends_at?:string,coupon_id?:string} */
    public function definition(): array
    {
        return ['items' => $this->items, 'trial_days' => $this->trialDays, 'starts_at' => $this->startsAt, ...($this->trialEndsAt === null ? [] : ['trial_ends_at' => $this->trialEndsAt]), ...($this->couponId === null ? [] : ['coupon_id' => $this->couponId])];
    }

    /** @internal @return array<string,mixed> */
    public function resolvePayload(): array
    {
        $payload = ['items' => $this->items];
        if ($this->startsAt !== null) {
            $payload['starts_at'] = $this->startsAt;
        }
        if ($this->trialDays !== null) {
            $startsAt = $this->startsAt ?? now()->format('Y-m-d\TH:i:s.uP');
            Timestamp::compare($startsAt, $startsAt);
            preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/D', $startsAt, $match);
            $date = (new DateTimeImmutable($match[1].$match[3]))->modify('+'.$this->trialDays.' days');
            $payload['trial_ends_at'] = $date->format('Y-m-d\TH:i:s').$match[2].$match[3];
        }
        if ($this->trialEndsAt !== null) {
            if (Timestamp::compare($this->trialEndsAt, $this->startsAt ?? now()->format('Y-m-d\TH:i:s.uP')) <= 0) {
                throw new ConflictException('An explicit trial end must follow the subscription start.');
            }
            $payload['trial_ends_at'] = $this->trialEndsAt;
        }

        return $payload;
    }
}
