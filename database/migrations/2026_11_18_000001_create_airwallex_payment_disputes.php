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
        Schema::create('airwallex_payment_disputes', function (Blueprint $table): void {
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
            $table->string('remote_id');
            $table->string('payment_attempt_id')->nullable();
            $table->text('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('stage')->nullable();
            $table->longText('reason')->nullable();
            $table->string('due_at')->nullable();
            $table->string('provider_updated_at');
            $table->timestamps(6);
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_pa_dispute_remote');
            $table->foreign(['intent_id', 'customer_id', 'environment', 'account_id'], 'aw_pa_dispute_intent')->references(['id', 'customer_id', 'environment', 'account_id'])->on('airwallex_payment_intents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_payment_disputes');
    }
};
