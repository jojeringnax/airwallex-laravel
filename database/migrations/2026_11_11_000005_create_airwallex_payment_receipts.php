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
        Schema::create('airwallex_payment_receipts', function (Blueprint $table): void {
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
            $table->string('event_id');
            $table->string('digest', 64);
            $table->string('event_name')->nullable();
            $table->string('version')->nullable();
            $table->string('resource_id')->nullable();
            $table->longText('body')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'account_id', 'event_id'], 'aw_pa_receipt_event');
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_payment_receipts');
    }
};
