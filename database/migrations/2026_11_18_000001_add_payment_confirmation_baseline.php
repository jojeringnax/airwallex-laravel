<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airwallex_payment_operations', function (Blueprint $table): void {
            $table->text('confirmation_baseline')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('airwallex_payment_operations', function (Blueprint $table): void {
            $table->dropColumn('confirmation_baseline');
        });
    }
};
