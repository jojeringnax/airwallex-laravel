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
        foreach (['airwallex_operations', 'airwallex_payment_operations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->longText('retry_release')->nullable());
        }
        Schema::table('airwallex_payment_intents', fn (Blueprint $table) => $table->string('order_reservation')->nullable());
        DB::table('airwallex_payment_intents')->update(['order_reservation' => DB::raw('merchant_order_id')]);
        Schema::table('airwallex_payment_intents', function (Blueprint $table): void {
            $table->dropUnique('aw_pa_intent_order');
            $table->unique(['environment', 'account_id', 'order_reservation'], 'aw_pa_intent_order_reservation');
        });
    }

    public function down(): void
    {
        if (DB::table('airwallex_payment_intents')->select('environment', 'account_id', 'merchant_order_id')
            ->groupBy('environment', 'account_id', 'merchant_order_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('The previous schema cannot preserve duplicate-order history; retain this migration.');
        }
        Schema::table('airwallex_payment_intents', function (Blueprint $table): void {
            $table->dropUnique('aw_pa_intent_order_reservation');
            $table->unique(['environment', 'account_id', 'merchant_order_id'], 'aw_pa_intent_order');
            $table->dropColumn('order_reservation');
        });
        foreach (['airwallex_operations', 'airwallex_payment_operations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('retry_release'));
        }
    }
};
