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
        Schema::table('airwallex_payment_refunds', function (Blueprint $table): void {
            $table->ulid('operation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('airwallex_payment_refunds')->whereNull('operation_id')->exists()) {
            throw new RuntimeException('The previous schema cannot retain provider-created refunds.');
        }
        Schema::table('airwallex_payment_refunds', function (Blueprint $table): void {
            $table->ulid('operation_id')->change();
        });
    }
};
