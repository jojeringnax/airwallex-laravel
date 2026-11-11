<?php

declare(strict_types=1);

// Inspection/plan output only. No application bootstrap, credential file or SDK is loaded.
$options = getopt('', ['package-commit:', 'sdk-commit:', 'lock-sha256:']);
$value = static fn (string $name): string => getenv($name) ?: '';
$identifier = static fn (string $text): bool => $text !== '' && preg_match('/[\x00-\x20\x7f]/', $text) === 0;
$positiveUnits = static fn (string $text): bool => preg_match('/^[1-9][0-9]{0,30}$/D', $text) === 1;
$checks = [
    'cli_only' => PHP_SAPI === 'cli',
    'exact_package_commit' => preg_match('/^[0-9a-f]{40}$/D', $options['package-commit'] ?? '') === 1,
    'exact_sdk_commit' => preg_match('/^[0-9a-f]{40}$/D', $options['sdk-commit'] ?? '') === 1,
    'exact_dependency_lock' => preg_match('/^[0-9a-f]{64}$/D', $options['lock-sha256'] ?? '') === 1,
    'separate_payments_opt_in' => $value('AIRWALLEX_PAYMENTS_SANDBOX_OPT_IN') === 'I_AUTHORIZE_OWNED_PAYMENTS_SANDBOX_VALIDATION',
    'explicit_sandbox' => $value('AIRWALLEX_PAYMENTS_ENVIRONMENT') === 'sandbox',
    'external_client_id_present' => $value('AIRWALLEX_PAYMENTS_CLIENT_ID') !== '',
    'external_api_key_present' => $value('AIRWALLEX_PAYMENTS_API_KEY') !== '',
    'merchant_account_configured' => $identifier($value('AIRWALLEX_PAYMENTS_ACCOUNT_ID')),
    'dedicated_billable_configured' => $identifier($value('AIRWALLEX_PAYMENTS_SANDBOX_BILLABLE_ID')),
    'private_run_id_configured' => $identifier($value('AIRWALLEX_PAYMENTS_SANDBOX_RUN_ID')),
    'currency_selected' => preg_match('/^[A-Z]{3}$/D', $value('AIRWALLEX_PAYMENTS_SANDBOX_CURRENCY')) === 1,
    'per_payment_cap_configured' => $positiveUnits($value('AIRWALLEX_PAYMENTS_SANDBOX_PAYMENT_CAP_MINOR')),
    'aggregate_cap_configured' => $positiveUnits($value('AIRWALLEX_PAYMENTS_SANDBOX_TOTAL_CAP_MINOR')),
    'ownership_scope_attested' => $value('AIRWALLEX_PAYMENTS_SANDBOX_OWNERSHIP') === 'DEDICATED_AUTHORIZED_ACCOUNT_CUSTOMER_INSTRUMENTS',
    'owned_cleanup_attested' => $value('AIRWALLEX_PAYMENTS_SANDBOX_CLEANUP') === 'JOURNALLED_ORIGINAL_PAYMENTS_AND_REFUNDS_ONLY',
    'client_plan_attested' => $value('AIRWALLEX_PAYMENTS_SANDBOX_CLIENTS') === 'METHOD_SPECIFIC_CLIENT_PLAN_REVIEWED',
];
$complete = ! in_array(false, $checks, true);
echo json_encode([
    'status' => $complete ? 'manual_journey_prepared' : 'blocked_missing_prerequisites',
    'checks' => $checks,
    'provider_observations' => 'unexecuted',
    'financial_actions_run' => false,
    'authorization' => 'separate_scoped_approval_required; environment_attestation_is_not_approval',
    'actual_cached_configuration' => 'not_loaded_or_verified',
    'provider_ownership_permissions_client_eligibility_and_cleanup' => 'not_verified',
    'cap_enforcement' => 'application_and_operator_must_enforce_private_per_action_and_aggregate_plan',
    'versions' => ['api' => '2026-08-21', 'webhook' => '2026-08-21', 'package_sdk_lock' => 'supplied_identifiers_require_installed_manifest_verification'],
    'guide' => 'docs/payments-sandbox.md',
    'steps' => ['verify_exact_installed_candidate_and_owned_merchant_context', 'verify_account_activation_and_selected_client_methods',
        'create_or_link_owned_payments_customer_and_consent', 'verify_callback_default_and_local_revocation',
        'observe_customer_present_card_and_each_selected_wallet', 'observe_saved_card_customer_action_failure_and_capture',
        'reconcile_signed_deliveries_through_actual_workers', 'observe_partial_full_and_competing_refund_commands',
        'verify_one_application_cash_and_credit_effect_per_financial_identity', 'reconcile_original_ambiguities_and_journal_owned_cleanup',
        'bind_both_tracks_to_final_cumulative_candidate'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($complete ? 0 : 2);
