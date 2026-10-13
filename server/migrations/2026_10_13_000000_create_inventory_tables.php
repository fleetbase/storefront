<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Simple stock for storefront products: an on-hand quantity per product per store location
     * (or food truck), held for a checkout while it is paid and taken when the order is placed.
     * Off unless a product tracks inventory. The Pallet extension can take this over later.
     */
    public function up(): void
    {
        $schema = Schema::connection(config('storefront.connection.db'));

        if (!$schema->hasColumn('products', 'track_inventory')) {
            $schema->table('products', function (Blueprint $table) {
                $table->boolean('track_inventory')->default(false)->after('is_available');
            });
        }

        if (!$schema->hasTable('product_inventories')) {
            $schema->create('product_inventories', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->string('public_id')->nullable()->unique();
                $table->uuid('company_uuid')->nullable()->index();
                $table->uuid('product_uuid')->index();
                $table->uuid('location_uuid');
                $table->string('location_type')->nullable();
                $table->integer('quantity')->default(0);
                $table->integer('reserved')->default(0);
                $table->integer('low_stock_threshold')->nullable();
                $table->boolean('allow_backorder')->default(false);
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['product_uuid', 'location_uuid'], 'product_inventories_product_location_unique');
            });
        }

        if (!$schema->hasTable('inventory_movements')) {
            $schema->create('inventory_movements', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->uuid('company_uuid')->nullable()->index();
                $table->uuid('inventory_uuid')->index();
                $table->string('type', 30);
                $table->integer('quantity');
                $table->integer('quantity_after')->nullable();
                $table->uuid('reference_uuid')->nullable()->index();
                $table->string('reference_type')->nullable();
                $table->uuid('actor_uuid')->nullable();
                $table->string('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!$schema->hasTable('inventory_reservations')) {
            $schema->create('inventory_reservations', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->uuid('company_uuid')->nullable()->index();
                $table->uuid('inventory_uuid')->index();
                $table->uuid('checkout_uuid')->index();
                $table->uuid('order_uuid')->nullable()->index();
                $table->string('cart_item_id')->nullable();
                $table->integer('quantity');
                $table->timestamp('expires_at')->nullable()->index();
                $table->string('status', 20)->default('held')->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(config('storefront.connection.db'));

        $schema->dropIfExists('inventory_reservations');
        $schema->dropIfExists('inventory_movements');
        $schema->dropIfExists('product_inventories');

        if ($schema->hasColumn('products', 'track_inventory')) {
            $schema->table('products', function (Blueprint $table) {
                $table->dropColumn('track_inventory');
            });
        }
    }
};
