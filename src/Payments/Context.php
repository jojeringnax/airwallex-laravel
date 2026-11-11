<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\Payments\Models\PaymentModel;

final readonly class Context
{
    public function __construct(public string $environment, public string $accountId)
    {
        if (! in_array($environment, ['sandbox', 'production'], true) || $accountId === '' || strlen($accountId) > 255 || preg_match('/[\x00-\x20\x7f]/', $accountId)) {
            throw new ConfigurationException('Payments requires an explicit environment and merchant account.');
        }
    }

    /** @return array{environment:string,account_id:string} */
    public function attributes(): array
    {
        return ['environment' => $this->environment, 'account_id' => $this->accountId];
    }

    public function assertModel(PaymentModel $model): void
    {
        $model->assertIdentity();
        foreach ($this->attributes() as $key => $value) {
            if ($model->getAttribute($key) !== $value || ($model->exists && $model->getRawOriginal($key) !== $value)) {
                throw new OwnershipException('Payments record belongs to a different merchant context.');
            }
        }
    }
}
