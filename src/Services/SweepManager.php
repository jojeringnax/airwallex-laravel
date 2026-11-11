<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Services;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Support\FailureClassification;
use Jojeringnax\AirwallexLaravel\Support\SweepFamily;
use Throwable;

class SweepManager
{
    /** One cursor is committed after each attempted row, including a retained/failing row.
     * @param  Closure(array<string, mixed>): void|null  $report
     * @return array<string, mixed>
     */
    public function run(SweepFamily $family, int $limit = 100, int $seconds = 30, ?Closure $report = null): array
    {
        $leaseSeconds = config('airwallex.sweep.lease_seconds', 300);
        if ($limit < 1 || $limit > 1000 || $seconds < 1 || $seconds > 3600 || ! is_int($leaseSeconds) || $leaseSeconds < 1) {
            throw new ConfigurationException('Sweep row/time budgets and lease must be bounded positive integers.');
        }
        $started = hrtime(true);
        $key = $family->key();
        DB::table('airwallex_sweeps')->insertOrIgnore([
            'id' => $key, 'environment' => $family->environment, 'owner_type' => $family->ownerType,
            'owner_id' => $family->ownerId, 'family' => $family->name, 'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);
        $token = (string) Str::uuid();
        $query = $this->query($family);
        if ((clone $query)->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()->utc()))
            ->update(['lease_token' => $token, 'lease_until' => now()->utc()->addSeconds($leaseSeconds), 'updated_at' => now()->utc()]) !== 1) {
            return ['family' => $family->name, 'progress_id' => $key, 'outcome' => 'busy', 'attempted' => 0];
        }
        $attempted = 0;
        $failed = 0;
        try {
            $state = $query->first();
            if ($state->sweep_started_at === null) {
                $source = ($family->query)();
                $source->reorder();
                $upper = $source->max('id');
                $this->advance($family, $token, ['upper_id' => $upper, 'cursor' => null, 'sweep_started_at' => now()->utc(), 'attempted' => 0, 'failures' => 0, 'last_error' => null]);
                $state = $query->first();
            }
            while ($attempted < $limit && hrtime(true) - $started < $seconds * 1_000_000_000) {
                $this->advance($family, $token, ['lease_until' => now()->utc()->addSeconds($leaseSeconds)]);
                $row = null;
                if ($state->upper_id !== null) {
                    $rows = ($family->query)();
                    $rows->reorder()->orderBy('id')->where('id', '<=', $state->upper_id);
                    if ($state->cursor !== null) {
                        $rows->where('id', '>', $state->cursor);
                    }
                    $row = $rows->first();
                }
                if ($row === null) {
                    $this->advance($family, $token, ['cursor' => null, 'upper_id' => null, 'sweep_started_at' => null, 'last_completed_at' => now()->utc(), 'completed_sweeps' => DB::raw('completed_sweeps + 1')]);
                    break;
                }
                $id = (string) $row->getKey();
                try {
                    $outcome = ($family->process)($row) ? 'completed' : 'retained';
                } catch (Throwable $error) {
                    $outcome = FailureClassification::of($error);
                }
                $failure = $outcome !== 'completed';
                $this->advance($family, $token, [
                    'cursor' => $id, 'attempted' => DB::raw('attempted + 1'),
                    'failures' => DB::raw('failures + '.($failure ? '1' : '0')),
                    ...($failure ? ['last_error' => $outcome] : []),
                ]);
                $state->cursor = $id;
                $attempted++;
                $failed += $failure ? 1 : 0;
                $report?->__invoke(['family' => $family->name, 'id' => $id, 'outcome' => $outcome]);
            }

            $state = $query->first();

            return ['family' => $family->name, 'progress_id' => $key, 'outcome' => $state->sweep_started_at === null ? 'sweep_completed' : 'paused',
                'attempted' => $attempted, 'failures' => $failed, 'cursor' => $state->cursor, 'upper_id' => $state->upper_id,
                'sweep_attempted' => $state->attempted, 'sweep_failures' => $state->failures, 'last_error' => $state->last_error,
                'last_completed_at' => $state->last_completed_at, 'completed_sweeps' => $state->completed_sweeps];
        } finally {
            $query->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null, 'updated_at' => now()->utc()]);
        }
    }

    /** @param array<string, mixed> $values */
    private function advance(SweepFamily $family, string $token, array $values): void
    {
        if ($this->query($family)->where('lease_token', $token)->where('lease_until', '>', now()->utc())
            ->update([...$values, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()->utc()]) !== 1) {
            throw new ConflictException('Sweep lease expired or was superseded; progress remains recoverable.');
        }
    }

    private function query(SweepFamily $family): Builder
    {
        return DB::table('airwallex_sweeps')->where('id', $family->key())->where('environment', $family->environment)
            ->where('owner_type', $family->ownerType)->where('owner_id', $family->ownerId)->where('family', $family->name);
    }
}
