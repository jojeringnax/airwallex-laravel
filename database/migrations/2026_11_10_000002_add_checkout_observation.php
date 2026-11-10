<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airwallex_checkouts', function (Blueprint $table): void {
            $table->longText('snapshot')->nullable();
            $table->string('remote_payment_source_id')->nullable();
            $table->text('provider_created_at')->nullable();
            $table->text('provider_updated_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('airwallex_checkouts', function (Blueprint $table): void {
            $table->dropColumn(['snapshot', 'remote_payment_source_id', 'provider_created_at', 'provider_updated_at', 'revision']);
        });
    }
};
