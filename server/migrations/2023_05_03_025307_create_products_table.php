<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(config('storefront.connection.db'))->create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('public_id', 191)->nullable()->index();
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('company_uuid', 191)->nullable()->index('products_company_uuid_foreign');
            $table->string('created_by_uuid', 191)->nullable()->index('products_created_by_uuid_foreign');
            $table->string('primary_image_uuid', 191)->nullable()->index('products_primary_image_uuid_foreign');
            $table->string('store_uuid', 191)->nullable()->index('products_store_uuid_foreign');
            $table->string('category_uuid', 191)->nullable()->index('products_category_uuid_foreign');
            $table->string('name')->nullable();
            $table->longText('description')->nullable();
            $table->json('tags')->nullable();
            $table->json('meta')->nullable();
            $table->json('translations')->nullable();
            $table->mediumText('qr_code')->nullable();
            $table->mediumText('barcode')->nullable();
            $table->json('youtube_urls')->nullable();
            $table->string('sku')->nullable();
            $table->integer('price')->default(0);
            $table->integer('sale_price')->default(0);
            $table->boolean('is_on_sale')->nullable();
            $table->boolean('is_service')->nullable()->index();
            $table->boolean('is_bookable')->default(false);
            $table->boolean('is_available')->default(true)->index();
            $table->boolean('is_recommended')->nullable();
            $table->boolean('can_pickup')->default(false);
            $table->string('currency', 191)->nullable()->index();
            $table->string('status', 191)->nullable()->index();
            $table->string('slug', 191)->nullable()->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('products');
    }
};
