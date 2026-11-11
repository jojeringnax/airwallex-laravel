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
        // The preceding package migration enforces the qualified database profile.
        Schema::create('airwallex_payment_customers', function (Blueprint $table): void {
            $this->context($table);
            $table->string('billable_type');
            $table->string('billable_id');
            $table->string('merchant_customer_id', 64)->nullable();
            $table->string('remote_id')->nullable();
            $table->longText('snapshot')->nullable();
            $table->timestamp('synced_at', 6)->nullable();
            $table->timestamp('disabled_at', 6)->nullable();
            $table->unique(['environment', 'account_id', 'billable_type', 'billable_id'], 'aw_pa_customer_local');
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_pa_customer_remote');
            $table->unique(['id', 'environment', 'account_id'], 'aw_pa_customer_context');
        });
        Schema::create('airwallex_payment_operations', function (Blueprint $table): void {
            $this->context($table);
            $table->ulid('customer_id');
            $table->string('kind');
            $table->string('action_key');
            $table->string('target_id')->nullable();
            $table->string('remote_id')->nullable();
            $table->uuid('request_id');
            $table->string('intent_hash', 64);
            $table->longText('intent');
            $table->string('status');
            $table->string('lock_key')->nullable();
            $table->string('error_code')->nullable();
            $table->unique(['environment', 'account_id', 'action_key'], 'aw_pa_operation_action');
            $table->unique(['environment', 'account_id', 'lock_key'], 'aw_pa_operation_lock');
            $table->unique(['id', 'customer_id', 'environment', 'account_id'], 'aw_pa_operation_context');
            $table->foreign(['customer_id', 'environment', 'account_id'], 'aw_pa_operation_customer')->references(['id', 'environment', 'account_id'])->on('airwallex_payment_customers')->restrictOnDelete();
        });
    }

    private function context(Blueprint $table): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_0900_bin');
        }
        $table->ulid('id')->primary();
        $environment = $table->string('environment', 10);
        if (DB::connection()->getDriverName() === 'mysql') {
            $environment->charset('ascii')->collation('ascii_bin');
        }
        $table->string('account_id');
        $table->timestamps(6);
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_payment_operations');
        Schema::dropIfExists('airwallex_payment_customers');
    }
};
