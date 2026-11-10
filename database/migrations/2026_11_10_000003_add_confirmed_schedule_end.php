<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use Jojeringnax\AirwallexLaravel\Timestamp;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airwallex_subscriptions', function (Blueprint $table): void {
            $table->text('scheduled_ends_at')->nullable();
        });
        DB::table('airwallex_subscriptions')->where('cancel_at_period_end', true)->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                try {
                    $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                    $customer = DB::table('airwallex_customers')->where('id', $row->customer_id)->first();
                    if (! $customer || ($snapshot['id'] ?? null) !== $row->remote_id || ($snapshot['billing_customer_id'] ?? null) !== $customer->remote_id || ($snapshot['status'] ?? null) !== $row->status || ($snapshot['cancel_at_period_end'] ?? null) !== true) {
                        continue;
                    }
                    $end = null;
                    foreach (['ends_at', 'current_period_ends_at'] as $key) {
                        if (is_string($snapshot[$key] ?? null)) {
                            Timestamp::compare($snapshot[$key], $snapshot[$key]);
                            if ($end === null || Timestamp::compare($snapshot[$key], $end) < 0) {
                                $end = $snapshot[$key];
                            }
                        }
                    }
                    if ($end !== null) {
                        DB::table('airwallex_subscriptions')->where('id', $row->id)->update(['scheduled_ends_at' => $end]);
                    }
                } catch (JsonException|EvidenceException) {
                    // A missing or malformed historical proof cannot grant access.
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('airwallex_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('scheduled_ends_at');
        });
    }
};
