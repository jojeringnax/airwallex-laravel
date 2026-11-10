<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\Airwallex\Exception\SdkException;
use Jojeringnax\AirwallexLaravel\BillingContext;
use Jojeringnax\AirwallexLaravel\Exception\AmbiguousOperationException;
use Jojeringnax\AirwallexLaravel\Exception\BillingException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Exception\OwnershipException;
use Jojeringnax\AirwallexLaravel\ModelRegistry;
use Throwable;

abstract class BillingCommand extends Command
{
    protected function batchLimit(): int
    {
        $value = $this->option('limit');
        if (is_int($value) && $value >= 1 && $value <= 1000) {
            return $value;
        }
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,3}$/D', $value) || (int) $value > 1000) {
            throw new ConflictException('Batch limit must be an integer from 1 to 1000.');
        }

        return (int) $value;
    }

    /** @return Builder<Model> */
    protected function scoped(string $model): Builder
    {
        $query = ModelRegistry::query($model);
        $query->where(app(BillingContext::class)->attributes());
        $query->orderBy('id');
        if ($this->hasOption('after')) {
            $after = $this->identifierOption('after');
            if ($after !== null) {
                $query->where('id', '>', $after);
            }
        }

        return $query;
    }

    protected function identifierOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new ConflictException('Identifier options must be nonempty bounded identifiers.');
        }

        return $value;
    }

    /** Safe machine-readable output; untrusted identifiers cannot inject lines. */
    protected function reportRow(array $fields): void
    {
        $this->line(json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    protected function classification(Throwable $error): string
    {
        return match (true) {
            $error instanceof AmbiguousOperationException => $error->causeCategory,
            $error instanceof OwnershipException => 'ownership',
            $error instanceof ConflictException => 'conflict',
            $error instanceof EvidenceException => 'evidence',
            $error instanceof SdkException => 'sdk',
            $error instanceof BillingException => 'configuration',
            default => 'retryable_local_failure',
        };
    }
}
