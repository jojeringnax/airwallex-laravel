<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;

/** Query and processor must both enforce the same immutable owning context. */
final readonly class SweepFamily
{
    /** @param Closure(): Builder<covariant Model> $query
     * @param  Closure(Model): bool  $process  True means completed, false retained for another sweep.
     */
    public function __construct(
        public string $name,
        public string $environment,
        public string $ownerType,
        public string $ownerId,
        public Closure $query,
        public Closure $process,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)
            || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $ownerType)
            || ! in_array($environment, ['sandbox', 'production'], true)
            || $ownerId === '' || mb_strlen($ownerId) > 255 || preg_match('/[\x00-\x20\x7f]/', $ownerId)) {
            throw new ConfigurationException('Sweep family requires a bounded name and immutable owning context.');
        }
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->environment, $this->ownerType, $this->ownerId, $this->name], JSON_THROW_ON_ERROR));
    }
}
