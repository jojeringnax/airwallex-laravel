<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;

/** @internal Fences worker commits without holding a transaction during HTTP. */
class CommitGuard
{
    private ?Closure $guard = null;

    public function during(Closure $guard, Closure $work): mixed
    {
        $previous = $this->guard;
        $this->guard = static function () use ($previous, $guard): void {
            $previous?->__invoke();
            $guard();
        };
        try {
            return $work();
        } finally {
            $this->guard = $previous;
        }
    }

    public function assertMayCommit(): void
    {
        ($this->guard ?? static fn () => null)();
    }
}
