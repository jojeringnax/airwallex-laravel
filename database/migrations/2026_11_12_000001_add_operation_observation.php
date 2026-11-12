<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airwallex_operations', fn (Blueprint $table) => $table->longText('observation')->nullable());
    }

    public function down(): void
    {
        Schema::table('airwallex_operations', fn (Blueprint $table) => $table->dropColumn('observation'));
    }
};
