<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SandboxPreflightTest extends TestCase
{
    public function test_synthetic_complete_configuration_produces_only_a_redacted_unexecuted_plan(): void
    {
        [$code, $result, $output] = $this->runTool($this->inputs());
        self::assertSame(0, $code);
        self::assertSame('ready_for_manual_steps', $result['status']);
        self::assertNotContains(false, $result['checks']);
        self::assertSame('not_loaded', $result['runtime_configuration']);
        self::assertSame('not_verified', $result['provider_ownership_and_eligibility']);
        self::assertSame('unexecuted', $result['provider_observations']);
        self::assertFalse($result['financial_actions_run']);
        self::assertSame(['sdk' => '0.1.1', 'api' => '2026-08-21', 'webhook' => '2026-08-21'], $result['contract_baseline']);
        foreach (['AIRWALLEX_CLIENT_ID', 'AIRWALLEX_API_KEY', 'AIRWALLEX_ORGANIZATION_ID', 'AIRWALLEX_LEGAL_ENTITY_ID', 'AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID', 'AIRWALLEX_SANDBOX_PRICE_ID', 'AIRWALLEX_SANDBOX_BILLABLE_TYPE', 'AIRWALLEX_SANDBOX_BILLABLE_ID', 'AIRWALLEX_SANDBOX_RUN_ID'] as $key) {
            self::assertStringNotContainsString($this->inputs()[$key], $output);
        }
    }

    #[DataProvider('requiredInputs')]
    public function test_each_explicit_prerequisite_fails_closed_before_sdk_or_network(string $input, string $check): void
    {
        $inputs = $this->inputs();
        $inputs[$input] = false;
        [$code, $result] = $this->runTool($inputs);
        self::assertSame(2, $code);
        self::assertSame('blocked_missing_prerequisites', $result['status']);
        self::assertFalse($result['checks'][$check]);
        self::assertFalse($result['financial_actions_run']);
    }

    public static function requiredInputs(): array
    {
        return [
            ['AIRWALLEX_SANDBOX_OPT_IN', 'explicit_opt_in'],
            ['AIRWALLEX_ENVIRONMENT', 'environment_is_sandbox'],
            ['AIRWALLEX_CLIENT_ID', 'external_client_id_present'],
            ['AIRWALLEX_API_KEY', 'external_api_key_present'],
            ['AIRWALLEX_ORGANIZATION_ID', 'organization_identifier_shape'],
            ['AIRWALLEX_LEGAL_ENTITY_ID', 'entity_identifier_shape'],
            ['AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID', 'collector_identifier_shape'],
            ['AIRWALLEX_SANDBOX_PRICE_ID', 'price_identifier_shape'],
            ['AIRWALLEX_CURRENCY', 'currency_shape'],
            ['AIRWALLEX_SANDBOX_BILLABLE_TYPE', 'dedicated_billable_type_shape'],
            ['AIRWALLEX_SANDBOX_BILLABLE_ID', 'dedicated_billable_id_shape'],
            ['AIRWALLEX_SANDBOX_OWNERSHIP', 'ownership_attested'],
            ['AIRWALLEX_SANDBOX_CLEANUP', 'cleanup_scope_attested'],
            ['AIRWALLEX_SANDBOX_RUN_ID', 'run_identifier_shape'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_configuration_shape_and_explicit_attestations_are_not_inferred(string $input, string $value, string $check): void
    {
        [$code, $result] = $this->runTool(array_replace($this->inputs(), [$input => $value]));
        self::assertSame(2, $code);
        self::assertFalse($result['checks'][$check]);
    }

    public static function invalidInputs(): array
    {
        return [
            ['AIRWALLEX_SANDBOX_OPT_IN', 'true', 'explicit_opt_in'],
            ['AIRWALLEX_ENVIRONMENT', 'production', 'environment_is_sandbox'],
            ['AIRWALLEX_ENVIRONMENT', 'Sandbox', 'environment_is_sandbox'],
            ['AIRWALLEX_API_KEY', '   ', 'external_api_key_present'],
            ['AIRWALLEX_ORGANIZATION_ID', "owner\nforeign", 'organization_identifier_shape'],
            ['AIRWALLEX_CURRENCY', 'usd', 'currency_shape'],
            ['AIRWALLEX_SANDBOX_OWNERSHIP', 'yes', 'ownership_attested'],
            ['AIRWALLEX_SANDBOX_CLEANUP', 'delete-everything', 'cleanup_scope_attested'],
        ];
    }

    public function test_no_environment_file_is_loaded_even_when_it_would_supply_every_prerequisite(): void
    {
        $unset = array_fill_keys(array_keys($this->inputs()), false);
        [$code, $result] = $this->runTool($unset, [], true);
        self::assertSame(2, $code);
        self::assertNotContains(true, array_diff_key($result['checks'], ['supported_invocation' => true]));
    }

    public function test_unknown_cli_arguments_are_rejected_without_echoing_private_values(): void
    {
        [$code, $result, $output] = $this->runTool($this->inputs(), ['--api-key=SYNTHETIC-CLI-SECRET']);
        self::assertSame(2, $code);
        self::assertFalse($result['checks']['supported_invocation']);
        self::assertStringNotContainsString('SYNTHETIC-CLI-SECRET', $output);
    }

    public function test_opaque_zero_local_key_is_retained_as_a_present_identifier(): void
    {
        [$code, $result] = $this->runTool(array_replace($this->inputs(), ['AIRWALLEX_SANDBOX_BILLABLE_ID' => '0']));
        self::assertSame(0, $code);
        self::assertTrue($result['checks']['dedicated_billable_id_shape']);
    }

    private function inputs(): array
    {
        return [
            'AIRWALLEX_SANDBOX_OPT_IN' => 'I_AUTHORIZE_SANDBOX_VALIDATION',
            'AIRWALLEX_ENVIRONMENT' => 'sandbox',
            'AIRWALLEX_CLIENT_ID' => 'SYNTHETIC-PRIVATE-CLIENT',
            'AIRWALLEX_API_KEY' => 'SYNTHETIC-PRIVATE-API-KEY',
            'AIRWALLEX_ORGANIZATION_ID' => 'SYNTHETIC-PRIVATE-ORGANIZATION',
            'AIRWALLEX_LEGAL_ENTITY_ID' => 'SYNTHETIC-PRIVATE-ENTITY',
            'AIRWALLEX_LINKED_PAYMENT_ACCOUNT_ID' => 'SYNTHETIC-PRIVATE-COLLECTOR',
            'AIRWALLEX_SANDBOX_PRICE_ID' => 'SYNTHETIC-PRIVATE-PRICE',
            'AIRWALLEX_CURRENCY' => 'USD',
            'AIRWALLEX_SANDBOX_BILLABLE_TYPE' => 'Synthetic\\DedicatedBillable',
            'AIRWALLEX_SANDBOX_BILLABLE_ID' => 'SYNTHETIC-PRIVATE-BILLABLE',
            'AIRWALLEX_SANDBOX_OWNERSHIP' => 'DEDICATED_AUTHORIZED_RESOURCES',
            'AIRWALLEX_SANDBOX_CLEANUP' => 'CONFIRMED_OWN_SUBSCRIPTIONS_ONLY',
            'AIRWALLEX_SANDBOX_RUN_ID' => 'SYNTHETIC-PRIVATE-RUN',
        ];
    }

    private function runTool(array $inputs, array $arguments = [], bool $environmentTrap = false): array
    {
        $directory = sys_get_temp_dir().'/airwallex-preflight-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        mkdir($directory.'/tools');
        mkdir($directory.'/vendor');
        copy(dirname(__DIR__).'/tools/sandbox-preflight.php', $directory.'/tools/sandbox-preflight.php');
        file_put_contents($directory.'/vendor/autoload.php', '<?php touch('.var_export($directory.'/sdk-loaded', true).'); throw new RuntimeException("SDK must not load.");');
        if ($environmentTrap) {
            $lines = [];
            foreach ($this->inputs() as $name => $value) {
                $lines[] = $name.'='.$value;
            }
            file_put_contents($directory.'/.env', implode(PHP_EOL, $lines));
        }
        try {
            $process = new Process([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client', $directory.'/tools/sandbox-preflight.php', ...$arguments], $directory, $inputs);
            $process->setTimeout(5);
            $process->run();
            self::assertSame('', $process->getErrorOutput());
            self::assertFileDoesNotExist($directory.'/sdk-loaded');
            $output = $process->getOutput();

            return [$process->getExitCode(), json_decode($output, true, flags: JSON_THROW_ON_ERROR), $output];
        } finally {
            foreach (glob($directory.'/tools/*') as $file) {
                unlink($file);
            }
            foreach (glob($directory.'/vendor/*') as $file) {
                unlink($file);
            }
            foreach (['.env', 'sdk-loaded'] as $file) {
                if (file_exists($directory.'/'.$file)) {
                    unlink($directory.'/'.$file);
                }
            }
            rmdir($directory.'/tools');
            rmdir($directory.'/vendor');
            rmdir($directory);
        }
    }
}
