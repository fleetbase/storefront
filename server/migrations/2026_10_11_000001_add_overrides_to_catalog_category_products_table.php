<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Per-catalog overrides on a catalog product: `price` replaces the store price while the
     * product is sold through that catalog (minor units, like products.price), and
     * `is_available` set to false hides the product in that catalog only. Both are null when
     * the product inherits from the store.
     */
    public function up(): void
    {
        $connection = config('storefront.connection.db');

        Schema::connection($connection)->table('catalog_category_products', function (Blueprint $table) use ($connection) {
            if (!Schema::connection($connection)->hasColumn('catalog_category_products', 'price')) {
                $table->integer('price')->nullable()->after('product_uuid');
            }

            if (!Schema::connection($connection)->hasColumn('catalog_category_products', 'is_available')) {
                $table->boolean('is_available')->nullable()->after('price');
            }
        });
    }

    public function down(): void
    {
        $connection = config('storefront.connection.db');

        Schema::connection($connection)->table('catalog_category_products', function (Blueprint $table) use ($connection) {
            foreach (['price', 'is_available'] as $column) {
                if (Schema::connection($connection)->hasColumn('catalog_category_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
