<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;

final readonly class BillingContext
{
    public function __construct(public string $environment, public string $organizationId)
    {
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new ConfigurationException('Airwallex environment must be sandbox or production.');
        }
        if ($organizationId === '' || preg_match('/[\x00-\x20\x7f]/', $organizationId) === 1) {
            throw new ConfigurationException('Airwallex organization identity must be explicitly configured.');
        }
    }

    /** @return array{environment: string, organization_id: string} */
    public function attributes(): array
    {
        return ['environment' => $this->environment, 'organization_id' => $this->organizationId];
    }

    public function assertModel(Model $model): void
    {
        if ($model->getAttribute('environment') !== $this->environment
            || $model->getAttribute('organization_id') !== $this->organizationId) {
            throw new OwnershipException('The billing record belongs to a different context.');
        }
    }
}
