<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $databaseName = Utils::getFleetbaseDatabaseName();

        Schema::connection(config('storefront.connection.db'))->create('catalog_category_products', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->default(Str::uuid()->toString());
            $table->foreignUuid('catalog_category_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.categories'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreignUuid('product_uuid')->nullable()->references('uuid')->on('products')->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('catalog_category_products');
    }
};
