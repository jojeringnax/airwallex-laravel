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
        Schema::create('airwallex_subscription_transitions', function (Blueprint $table): void {
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
            $table->string('organization_id');
            $table->foreignUlid('subscription_id')->constrained('airwallex_subscriptions')->restrictOnDelete();
            $table->string('action_key');
            $table->char('intent_hash', 64);
            $table->longText('intent');
            $table->text('effective_at');
            $table->text('expires_at');
            $table->timestamp('due_index_at', 6);
            $table->string('status')->default('scheduled');
            $table->unsignedBigInteger('attempts')->default(0);
            $table->ulid('active_key')->nullable()->unique();
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'action_key'], 'airwallex_transition_action');
            $table->index(['status', 'due_index_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airwallex_subscription_transitions');
    }
};
