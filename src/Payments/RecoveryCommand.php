<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Console\BillingCommand;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Payments\Models\Receipt;
use Throwable;

class RecoveryCommand extends BillingCommand
{
    protected $signature = 'airwallex:payments-recover {--family=all : all, intent, refund, instrument, method, operation, receipt, event or prune} {--limit=100 : Rows per family} {--after=} {--id=} {--remote-id=} {--replay : Explicit replay of one retained receipt} {--sweep : Persist fair per-family progress using the recovery foundation} {--seconds=30 : Sweep scheduling seconds per family}';

    protected $description = 'Recover canonical account-owned Payments state without replaying financial writes';

    public function handle(Recovery $recovery): int
    {
        try {
            $limit = $this->batchLimit();
            $name = $this->identifierOption('family');
            if ($name === null) {
                throw new ConflictException('A Payments recovery family is required.');
            }
            $id = $this->identifierOption('id');
            $after = $this->identifierOption('after');
            $candidate = $this->identifierOption('remote-id');
            if (($name === 'all' && ($id !== null || $after !== null)) || ($candidate !== null && ($name !== 'operation' || $id === null))
                || ($this->option('replay') && ($name !== 'receipt' || $id === null)) || ($this->option('sweep') && ($id !== null || $after !== null))) {
                throw new ConflictException('Select one explicit family for manual cursors, receipt replay or candidate association.');
            }
            if ($name === 'prune') {
                if ($this->option('sweep') || $id !== null || $after !== null) {
                    throw new ConflictException('Body pruning is a bounded retention operation.');
                }
                $this->reportRow(['family' => 'prune', 'outcome' => 'pruned', 'count' => app(Receipts::class)->prune($limit)]);

                return self::SUCCESS;
            }
            $failed = false;
            if ($this->option('sweep')) {
                $seconds = $this->option('seconds');
                if (! is_string($seconds) || ! preg_match('/^[1-9][0-9]{0,3}$/D', $seconds) || (int) $seconds > 3600) {
                    throw new ConfigurationException('Sweep seconds must be an integer from 1 to 3600.');
                }
                $managerClass = 'Jojeringnax\\AirwallexLaravel\\Services\\SweepManager';
                if (! class_exists($managerClass)) {
                    throw new ConfigurationException('Durable Payments sweeps require the fair recovery foundation.');
                }
                foreach ($recovery->sweepFamilies($name) as $family) {
                    try {
                        $result = app($managerClass)->run($family, $limit, (int) $seconds, fn (array $row) => $this->reportRow($row));
                        $this->reportRow($result);
                        $failed = $failed || ($result['failures'] ?? 0) > 0;
                    } catch (Throwable $error) {
                        $failed = true;
                        $this->reportRow(['family' => $family->name, 'outcome' => $this->classification($error)]);
                    }
                }
            } else {
                foreach ($recovery->names($name) as $family) {
                    $query = $recovery->query($family, $id !== null);
                    $query->orderBy('id')->limit($limit);
                    if ($after !== null) {
                        $query->where('id', '>', $after);
                    }
                    if ($id !== null) {
                        $query->whereKey($id);
                    }
                    $rows = $query->get();
                    if ($id !== null && $rows->isEmpty()) {
                        throw new ConflictException('Payments recovery row is unavailable in the configured account.');
                    }
                    foreach ($rows as $row) {
                        try {
                            if ($this->option('replay') && $row instanceof Receipt) {
                                $row = app(Receipts::class)->requestReplay($row);
                            }
                            $done = $recovery->process($family, $row, $candidate);
                            $failed = $failed || ! $done;
                            $this->reportRow(['family' => $family, 'id' => $row->getKey(), 'outcome' => $done ? 'completed' : 'retained', 'after' => $row->getKey()]);
                        } catch (Throwable $error) {
                            $failed = true;
                            $this->reportRow(['family' => $family, 'id' => $row->getKey(), 'outcome' => $this->classification($error), 'after' => $row->getKey()]);
                        }
                    }
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
