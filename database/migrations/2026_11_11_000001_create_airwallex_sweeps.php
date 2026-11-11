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
        Schema::create('airwallex_sweeps', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'mysql') {
                $table->engine = 'InnoDB';
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_0900_bin';
            }
            $table->string('id', 64)->primary();
            $table->string('environment', 32);
            $table->string('owner_type', 32);
            $table->string('owner_id');
            $table->string('family', 64);
            $table->string('cursor')->nullable();
            $table->string('upper_id')->nullable();
            $table->timestamp('sweep_started_at', 6)->nullable();
            $table->timestamp('last_completed_at', 6)->nullable();
            $table->unsignedBigInteger('completed_sweeps')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedInteger('attempted')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_sweeps');
    }
};
