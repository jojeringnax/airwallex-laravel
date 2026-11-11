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
        Schema::create('airwallex_payment_intents', function (Blueprint $table): void {
            $this->context($table);
            $table->ulid('customer_id');
            $table->ulid('operation_id')->unique();
            $table->string('remote_id')->nullable();
            $table->string('merchant_order_id');
            $table->text('amount');
            $table->string('currency', 3);
            $table->string('status')->default('UNCONFIRMED');
            $table->text('captured_amount')->nullable();
            $table->longText('snapshot')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unique(['environment', 'account_id', 'merchant_order_id'], 'aw_pa_intent_order');
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_pa_intent_remote');
            $table->unique(['id', 'customer_id', 'environment', 'account_id'], 'aw_pa_intent_context');
            $table->foreign(['customer_id', 'environment', 'account_id'], 'aw_pa_intent_customer')->references(['id', 'environment', 'account_id'])->on('airwallex_payment_customers')->restrictOnDelete();
            $table->foreign(['operation_id', 'customer_id', 'environment', 'account_id'], 'aw_pa_intent_operation')->references(['id', 'customer_id', 'environment', 'account_id'])->on('airwallex_payment_operations')->restrictOnDelete();
        });
        Schema::create('airwallex_payment_observations', function (Blueprint $table): void {
            $this->context($table);
            $table->string('observation_key', 64);
            $table->string('resource_type');
            $table->ulid('resource_id');
            $table->longText('payload');
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->unique(['environment', 'account_id', 'observation_key'], 'aw_pa_observation_identity');
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
        Schema::dropIfExists('airwallex_payment_observations');
        Schema::dropIfExists('airwallex_payment_intents');
    }
};
