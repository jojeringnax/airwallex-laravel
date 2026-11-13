<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Console;

use Jojeringnax\AirwallexLaravel\Exception\ConflictException;
use Jojeringnax\AirwallexLaravel\Models\Operation;
use Jojeringnax\AirwallexLaravel\Services\RecoveryManager;
use Throwable;

class OperationsCommand extends BillingCommand
{
    protected $signature = 'airwallex:operations {--reconcile : Perform reads for attempted operations; default is inspection} {--id= : One local operation ID} {--remote-id= : Candidate ID for one correlated creation} {--limit=100} {--after=}';

    protected $description = 'Inspect durable intents or reconcile attempted operations without replaying writes';

    public function handle(RecoveryManager $recovery): int
    {
        try {
            $limit = $this->batchLimit();
            $id = $this->identifierOption('id');
            $candidate = $this->identifierOption('remote-id');
            if ($candidate !== null && ($id === null || ! $this->option('reconcile'))) {
                throw new ConflictException('Candidate recovery requires --id and --reconcile.');
            }
            $query = $this->scoped('operation');
            if (is_string($id) && $id !== '') {
                $query->whereKey($id);
            } else {
                $query->whereIn('status', ['prepared', 'in_flight', 'ambiguous', 'acknowledged', 'superseded']);
            }
            $query->limit($limit);
            $rows = $query->get();
            if ($id !== null && $rows->isEmpty()) {
                throw new ConflictException('Operation was not found in the configured context.');
            }
            $failed = false;
            foreach ($rows as $operation) {
                if (! $operation instanceof Operation) {
                    throw new ConflictException('Configured operation model is invalid.');
                }
                $outcome = 'inspection';
                if ($this->option('reconcile')) {
                    try {
                        $recovery->operation($operation, $candidate);
                        $operation->refresh();
                        $outcome = $operation->status;
                    } catch (Throwable $error) {
                        $failed = true;
                        $outcome = $this->classification($error);
                    }
                }
                $this->reportRow(['id' => $operation->id, 'kind' => $operation->kind, 'status' => $operation->status, 'remote_id' => $operation->remote_id, 'reason' => $operation->error_code, 'outcome' => $outcome, 'after' => $operation->id]);
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->reportRow(['outcome' => $this->classification($error)]);

            return self::FAILURE;
        }
    }
}
