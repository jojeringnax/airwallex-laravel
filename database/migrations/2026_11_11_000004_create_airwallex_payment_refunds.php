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
        Schema::create('airwallex_payment_refunds', function (Blueprint $table): void {
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
            $table->ulid('customer_id');
            $table->ulid('intent_id');
            $table->ulid('operation_id')->unique();
            $table->string('remote_id')->nullable();
            $table->text('amount');
            $table->string('currency', 3);
            $table->string('status')->default('UNCONFIRMED');
            $table->longText('snapshot')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps(6);
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_pa_refund_remote');
            $table->foreign(['intent_id', 'customer_id', 'environment', 'account_id'], 'aw_pa_refund_intent')->references(['id', 'customer_id', 'environment', 'account_id'])->on('airwallex_payment_intents')->restrictOnDelete();
            $table->foreign(['operation_id', 'customer_id', 'environment', 'account_id'], 'aw_pa_refund_operation')->references(['id', 'customer_id', 'environment', 'account_id'])->on('airwallex_payment_operations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_payment_refunds');
    }
};
