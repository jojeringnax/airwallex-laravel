<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airwallex_payment_method_observations', function (Blueprint $table): void {
            $this->storage($table);
            $table->ulid('id')->primary();
            $environment = $table->string('environment', 10);
            if (DB::connection()->getDriverName() === 'mysql') {
                $environment->charset('ascii')->collation('ascii_bin');
            }
            $table->string('account_id');
            $table->ulid('customer_id');
            $table->string('remote_id');
            $table->string('status');
            $table->string('method_type');
            $table->text('provider_updated_at');
            $table->timestamps(6);
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_pa_method_observation_remote');
            $table->foreign(['customer_id', 'environment', 'account_id'], 'aw_pa_method_observation_owner')->references(['id', 'environment', 'account_id'])->on('airwallex_payment_customers')->restrictOnDelete();
        });
        Schema::create('airwallex_payment_instruments', function (Blueprint $table): void {
            $this->storage($table);
            $table->ulid('id')->primary();
            $environment = $table->string('environment', 10);
            if (DB::connection()->getDriverName() === 'mysql') {
                $environment->charset('ascii')->collation('ascii_bin');
            }
            $table->string('account_id');
            $table->ulid('customer_id');
            $table->ulid('create_operation_id')->nullable();
            $table->string('remote_id');
            $table->string('method_id')->nullable();
            foreach (['status', 'method_status', 'method_type', 'next_triggered_by', 'merchant_trigger_reason', 'correlation_key'] as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamp('synced_at', 6)->nullable();
            $table->longText('snapshot');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps(6);
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_pa_instrument_remote');
            $table->unique(['id', 'customer_id'], 'aw_pa_instrument_customer');
            $table->foreign(['customer_id', 'environment', 'account_id'], 'aw_pa_instrument_owner')->references(['id', 'environment', 'account_id'])->on('airwallex_payment_customers')->restrictOnDelete();
            $table->foreign(['create_operation_id', 'customer_id', 'environment', 'account_id'], 'aw_pa_instrument_creation')->references(['id', 'customer_id', 'environment', 'account_id'])->on('airwallex_payment_operations')->restrictOnDelete();
        });
        Schema::create('airwallex_payment_instrument_defaults', function (Blueprint $table): void {
            $this->storage($table);
            $table->ulid('customer_id')->primary();
            $table->ulid('instrument_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('airwallex_payment_customers')->restrictOnDelete();
            $table->foreign(['instrument_id', 'customer_id'], 'aw_pa_default_instrument_owner')->references(['id', 'customer_id'])->on('airwallex_payment_instruments')->restrictOnDelete();
        });
    }

    private function storage(Blueprint $table): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_bin');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_payment_instrument_defaults');
        Schema::dropIfExists('airwallex_payment_instruments');
        Schema::dropIfExists('airwallex_payment_method_observations');
    }
};
