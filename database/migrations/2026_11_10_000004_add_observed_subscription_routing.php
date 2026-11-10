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
        Schema::table('airwallex_subscriptions', fn (Blueprint $table) => $table->text('observed_routing')->nullable());
        Schema::table('airwallex_operations', fn (Blueprint $table) => $table->text('observed_routing')->nullable());
        DB::table('airwallex_subscriptions')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                try {
                    $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                    $customer = DB::table('airwallex_customers')->where('id', $row->customer_id)->first();
                    if (! $customer || ($snapshot['id'] ?? null) !== $row->remote_id || ($snapshot['billing_customer_id'] ?? null) !== $customer->remote_id) {
                        continue;
                    }
                    $routing = array_filter(array_intersect_key($snapshot, array_flip(['legal_entity_id', 'linked_payment_account_id'])), fn ($value) => is_string($value) && $value !== '');
                    DB::table('airwallex_subscriptions')->where('id', $row->id)->update(['observed_routing' => json_encode((object) $routing, JSON_THROW_ON_ERROR)]);
                } catch (JsonException) {
                    // Unverified historical snapshots cannot supply routing evidence.
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('airwallex_operations', fn (Blueprint $table) => $table->dropColumn('observed_routing'));
        Schema::table('airwallex_subscriptions', fn (Blueprint $table) => $table->dropColumn('observed_routing'));
    }
};
