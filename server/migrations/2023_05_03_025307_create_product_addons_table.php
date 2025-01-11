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
        Schema::connection(config('storefront.connection.db'))->create('product_addons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('public_id', 191)->nullable()->index();
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('created_by_uuid', 191)->nullable()->index('product_addons_created_by_uuid_foreign');
            $table->string('category_uuid', 191)->nullable()->index('product_addons_category_uuid_foreign');
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->json('translations')->nullable();
            $table->integer('price')->default(0);
            $table->integer('sale_price')->default(0);
            $table->boolean('is_on_sale')->nullable();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('product_addons');
    }
};
