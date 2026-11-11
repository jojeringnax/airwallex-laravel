<?php

declare(strict_types=1);

// Pure plan output. No application/SDK/environment-file loading or provider request occurs.
$options = getopt('', ['package-commit:', 'sdk-commit:', 'lock-sha256:']);
$checks = [
    'exact_package_commit' => preg_match('/^[0-9a-f]{40}$/D', $options['package-commit'] ?? '') === 1,
    'exact_sdk_commit' => preg_match('/^[0-9a-f]{40}$/D', $options['sdk-commit'] ?? '') === 1,
    'exact_dependency_lock' => preg_match('/^[0-9a-f]{64}$/D', $options['lock-sha256'] ?? '') === 1,
    'cli_only' => PHP_SAPI === 'cli',
];
echo json_encode([
    'status' => in_array(false, $checks, true) ? 'blocked_missing_provenance' : 'manual_journey_prepared',
    'checks' => $checks,
    'provider_observations' => 'unexecuted',
    'scoped_authorization' => 'separately_required',
    'actual_cached_configuration' => 'not_loaded_or_verified',
    'provider_ownership_and_cleanup' => 'not_verified',
    'financial_actions_run' => false,
    'guide' => 'docs/sandbox.md#adoption-gate-63',
    'steps' => [
        'authorize_scoped_sandbox_and_validate_exact_installed_graph',
        'verify_owned_customer_and_cashier_provider_boundary',
        'observe_hosted_setup_direct_and_hosted_subscription_resources',
        'observe_natural_trial_future_start_and_initial_free_period',
        'observe_explicit_immediate_and_due_transition_invoice_effects',
        'observe_supported_finite_cancellation_restoration_only',
        'read_owned_invoice_transactions_and_independent_allocation_evidence',
        'observe_signed_delivery_actual_workers_reconciliation_and_dunning',
        'confirm_journalled_owned_cancellation_and_retained_obligations',
        'bind_final_cumulative_candidate_and_repeat_affected_cases',
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit(in_array(false, $checks, true) ? 2 : 0);
