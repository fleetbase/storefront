<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Promotions: automatic and code-based discounts owned by a store or network.
     *
     * Money columns are integers in the storefront currency's minor unit, like cart
     * subtotals. `promotion_redemptions` records every use: a checkout reserves the
     * discount when it is created and the reservation is redeemed when the order is
     * captured, so usage limits and budgets count both.
     */
    public function up(): void
    {
        $databaseName = Utils::getFleetbaseDatabaseName();
        $schema       = Schema::connection(config('storefront.connection.db'));

        $schema->create('promotions', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id')->unique()->nullable();
            $table->foreignUuid('company_uuid')->nullable()->index()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreignUuid('created_by_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.users'))->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->uuid('owner_uuid')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('trigger')->default('automatic');
            $table->string('type');
            $table->decimal('value', 12, 2)->nullable();
            $table->unsignedBigInteger('max_discount_amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedBigInteger('min_subtotal')->nullable();
            $table->unsignedInteger('min_items')->nullable();
            $table->json('applies_to')->nullable();
            $table->json('bogo_config')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedBigInteger('budget_amount')->nullable();
            $table->boolean('stackable')->default(false);
            $table->integer('priority')->default(0);
            $table->boolean('is_public')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('schedule')->nullable();
            $table->string('timezone')->nullable();
            $table->uuid('image_uuid')->nullable();
            $table->json('translations')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_uuid', 'status']);
        });

        $schema->create('promotion_codes', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id')->unique()->nullable();
            $table->foreignUuid('company_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreignUuid('promotion_uuid')->references('uuid')->on('promotions')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->string('code');
            $table->string('status')->default('active');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->uuid('customer_uuid')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_uuid', 'code']);
        });

        $schema->create('promotion_redemptions', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignUuid('company_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreignUuid('promotion_uuid')->references('uuid')->on('promotions')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->uuid('promotion_code_uuid')->nullable()->index();
            $table->uuid('customer_uuid')->nullable()->index();
            $table->uuid('checkout_uuid')->nullable()->index();
            $table->uuid('order_uuid')->nullable()->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency', 3)->nullable();
            $table->string('status')->default('reserved');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['promotion_uuid', 'status']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('storefront.connection.db'));
        $schema->dropIfExists('promotion_redemptions');
        $schema->dropIfExists('promotion_codes');
        $schema->dropIfExists('promotions');
    }
};
