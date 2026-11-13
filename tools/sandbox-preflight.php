<?php

declare(strict_types=1);

// Standalone configuration inspection only: no application bootstrap or SDK calls.
$read = static fn (string $name): string|false => getenv($name);
$present = static fn (string|false $value): bool => $value !== false && trim($value) !== '';
$identifier = static fn (string|false $value): bool => $value !== false && $value !== '' && preg_match('/[\x00-\x20\x7f]/', $value) === 0;
$checks = [
    'explicit_opt_in' => $read('AIRWALLEX_SANDBOX_OPT_IN') === 'I_AUTHORIZE_SANDBOX_VALIDATION',
    'environment_is_sandbox' => $read('AIRWALLEX_ENVIRONMENT') === 'sandbox',
    'external_client_id_present' => $present($read('AIRWALLEX_CLIENT_ID')),
    'external_api_key_present' => $present($read('AIRWALLEX_API_KEY')),
    'organization_identifier_shape' => $identifier($read('AIRWALLEX_ORGANIZATION_ID')),
    'entity_identifier_shape' => $identifier($read('AIRWALLEX_LEGAL_ENTITY_ID')),
    'collector_identifier_shape' => $identifier($read('AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID')),
    'price_identifier_shape' => $identifier($read('AIRWALLEX_SANDBOX_PRICE_ID')),
    'currency_shape' => preg_match('/^[A-Z]{3}$/D', $read('AIRWALLEX_CURRENCY') ?: '') === 1,
    'dedicated_billable_type_shape' => $identifier($read('AIRWALLEX_SANDBOX_BILLABLE_TYPE')),
    'dedicated_billable_id_shape' => $identifier($read('AIRWALLEX_SANDBOX_BILLABLE_ID')),
    'ownership_attested' => $read('AIRWALLEX_SANDBOX_OWNERSHIP') === 'DEDICATED_AUTHORIZED_RESOURCES',
    'cleanup_scope_attested' => $read('AIRWALLEX_SANDBOX_CLEANUP') === 'CONFIRMED_OWN_SUBSCRIPTIONS_ONLY',
    'run_identifier_shape' => $identifier($read('AIRWALLEX_SANDBOX_RUN_ID')),
    'supported_invocation' => PHP_SAPI === 'cli' && count($_SERVER['argv'] ?? []) === 1,
];
$ready = ! in_array(false, $checks, true);
echo json_encode([
    'status' => $ready ? 'ready_for_manual_steps' : 'blocked_missing_prerequisites',
    'checks' => $checks,
    'contract_baseline' => ['sdk' => '0.1.3', 'api' => '2026-08-21', 'webhook' => '2026-08-21'],
    'installed_versions' => 'manual_verification_required',
    'runtime_configuration' => 'not_loaded',
    'provider_ownership_and_eligibility' => 'not_verified',
    'provider_observations' => 'unexecuted',
    'financial_actions_run' => false,
    'plan' => ['verify_runtime_and_private_journal', 'create_or_link_owned_customer', 'hosted_setup_and_source_verification', 'direct_and_hosted_subscriptions', 'owned_import_and_exact_item_changes', 'invoice_and_signed_event_observations', 'unpaid_and_authorized_source_recovery', 'confirmed_owned_cancellation_and_retained_cleanup_obligations'],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($ready ? 0 : 2);
