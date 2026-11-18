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
        Schema::create('airwallex_global_account_deposits', function (Blueprint $table): void {
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
            $table->string('global_account_id');
            $table->string('remote_id');
            $table->text('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('provider_created_at');
            $table->string('settled_at')->nullable();
            $table->longText('snapshot')->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'account_id', 'remote_id'], 'aw_ga_deposit_remote');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_global_account_deposits');
    }
};
