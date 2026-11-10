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
        if (DB::connection()->getDriverName() !== 'sqlite' || version_compare(DB::selectOne('select sqlite_version() as version')->version, '3.43.2', '<')) {
            throw new RuntimeException('Laravel Airwallex currently requires SQLite >=3.43.2.');
        }
        if ((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1) {
            throw new RuntimeException('Laravel Airwallex requires SQLite foreign keys enabled.');
        }
        Schema::create('airwallex_customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $this->context($table);
            $table->string('billable_type');
            $table->string('billable_id');
            $table->string('remote_id')->nullable();
            $table->timestamp('synced_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'billable_type', 'billable_id'], 'airwallex_customer_local');
            $table->unique(['environment', 'organization_id', 'remote_id'], 'airwallex_customer_remote');
            $table->unique(['id', 'environment', 'organization_id'], 'airwallex_customer_context');
        });
        Schema::create('airwallex_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $this->context($table);
            $table->string('remote_id');
            $table->string('name');
            $table->string('status');
            foreach (['starts_at', 'ends_at', 'trial_starts_at', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'cancel_requested_at', 'provider_created_at', 'provider_updated_at'] as $field) {
                $table->text($field)->nullable();
            }
            $table->boolean('cancel_at_period_end')->nullable();
            $table->text('snapshot');
            $table->timestamp('synced_at', 6)->nullable();
            $table->timestamp('items_synced_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'remote_id'], 'airwallex_subscription_remote');
            $table->unique(['id', 'customer_id'], 'airwallex_subscription_customer');
            $table->unique(['id', 'customer_id', 'name'], 'airwallex_subscription_name');
            $table->unique(['id', 'customer_id', 'environment', 'organization_id'], 'airwallex_subscription_context');
            $table->foreign(['customer_id', 'environment', 'organization_id'])->references(['id', 'environment', 'organization_id'])->on('airwallex_customers')->restrictOnDelete();
        });
        Schema::create('airwallex_subscription_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('subscription_id')->constrained('airwallex_subscriptions')->restrictOnDelete();
            $table->string('remote_id');
            $table->string('price_id')->nullable();
            $table->text('quantity')->nullable();
            $table->text('snapshot');
            $table->text('provider_created_at')->nullable();
            $table->text('provider_updated_at')->nullable();
            $table->timestamps(6);
            $table->unique(['subscription_id', 'remote_id'], 'airwallex_item_remote');
        });
        Schema::create('airwallex_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $this->context($table);
            $table->ulid('customer_id');
            $table->ulid('subscription_id')->nullable();
            $table->string('kind');
            $table->string('action_key');
            $table->string('intent_hash', 64);
            $table->text('intent');
            $table->uuid('request_id');
            $table->string('remote_id')->nullable();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->string('lock_key')->nullable();
            $table->unique(['environment', 'organization_id', 'lock_key'], 'airwallex_operation_lock');
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'action_key'], 'airwallex_operation_action');
            $table->unique(['id', 'customer_id'], 'airwallex_operation_customer');
            $table->unique(['id', 'customer_id', 'environment', 'organization_id'], 'airwallex_operation_context');
            $table->foreign(['customer_id', 'environment', 'organization_id'])->references(['id', 'environment', 'organization_id'])->on('airwallex_customers')->restrictOnDelete();
            $table->foreign(['subscription_id', 'customer_id', 'environment', 'organization_id'])->references(['id', 'customer_id', 'environment', 'organization_id'])->on('airwallex_subscriptions')->restrictOnDelete();
        });
        Schema::create('airwallex_subscription_slots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('airwallex_customers')->restrictOnDelete();
            $table->string('name');
            $table->ulid('subscription_id')->nullable();
            $table->ulid('operation_id')->nullable();
            $table->timestamps(6);
            $table->unique(['customer_id', 'name'], 'airwallex_slot_name');
            $table->foreign(['subscription_id', 'customer_id', 'name'])->references(['id', 'customer_id', 'name'])->on('airwallex_subscriptions')->restrictOnDelete();
            $table->foreign(['operation_id', 'customer_id'])->references(['id', 'customer_id'])->on('airwallex_operations')->restrictOnDelete();
        });
        Schema::create('airwallex_checkouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $this->context($table);
            $table->ulid('customer_id');
            $table->ulid('operation_id')->unique();
            $table->string('name')->nullable();
            $table->string('mode');
            $table->string('remote_id');
            $table->string('status');
            $table->text('expires_at')->nullable();
            $table->ulid('subscription_id')->nullable();
            $table->string('remote_subscription_id')->nullable();
            $table->string('payment_source_id')->nullable();
            $table->text('url')->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'remote_id'], 'airwallex_checkout_remote');
            $table->foreign(['customer_id', 'environment', 'organization_id'])->references(['id', 'environment', 'organization_id'])->on('airwallex_customers')->restrictOnDelete();
            $table->foreign(['operation_id', 'customer_id', 'environment', 'organization_id'])->references(['id', 'customer_id', 'environment', 'organization_id'])->on('airwallex_operations')->restrictOnDelete();
            $table->foreign(['subscription_id', 'customer_id'])->references(['id', 'customer_id'])->on('airwallex_subscriptions')->restrictOnDelete();
        });
        Schema::create('airwallex_webhook_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $this->context($table);
            $table->string('event_id');
            $table->string('digest', 64);
            $table->string('event_name')->nullable();
            $table->string('version')->nullable();
            $table->string('resource_id')->nullable();
            $table->text('body')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['environment', 'organization_id', 'event_id'], 'airwallex_receipt_identity');
        });
        Schema::create('airwallex_domain_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $this->context($table);
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('event_name');
            $table->text('payload');
            $table->timestamp('dispatched_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_until', 6)->nullable();
            $table->timestamps(6);
        });
    }

    private function context(Blueprint $table): void
    {
        $table->string('environment');
        $table->string('organization_id');
    }

    public function down(): void
    {
        foreach (['domain_events', 'webhook_receipts', 'checkouts', 'subscription_slots', 'operations', 'subscription_items', 'subscriptions', 'customers'] as $table) {
            Schema::dropIfExists('airwallex_'.$table);
        }
    }
};
