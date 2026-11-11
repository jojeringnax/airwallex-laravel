<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Services\SweepManager;
use Jojeringnax\AirwallexLaravel\Services\SweepRegistry;
use Throwable;

class SweepCommand extends BillingCommand
{
    protected $signature = 'airwallex:sweep {--family=all : Registered resource family} {--limit=100 : Maximum attempted rows per family} {--seconds=30 : Scheduling time budget per family; an in-flight read may finish later}';

    protected $description = 'Advance durable fair read-only reconciliation and recovery sweeps';

    public function handle(SweepRegistry $registry, SweepManager $sweeps): int
    {
        try {
            $limit = $this->batchLimit();
            $seconds = $this->option('seconds');
            $name = $this->option('family');
            if (! is_string($seconds) || ! preg_match('/^[1-9][0-9]{0,3}$/D', $seconds) || (int) $seconds > 3600 || ! is_string($name)) {
                throw new ConflictException('Sweep time budget must be an integer from 1 to 3600.');
            }
            $failed = false;
            foreach ($registry->families($name) as $family) {
                try {
                    $result = $sweeps->run($family, $limit, (int) $seconds, fn (array $row) => $this->reportRow($row));
                    $this->reportRow($result);
                    $failed = $failed || ($result['failures'] ?? 0) > 0;
                } catch (Throwable $error) {
                    $failed = true;
                    $this->reportRow(['family' => $family->name, 'outcome' => $this->classification($error)]);
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
