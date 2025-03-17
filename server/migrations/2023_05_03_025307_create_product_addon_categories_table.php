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
        Schema::connection(config('storefront.connection.db'))->create('product_addon_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('product_uuid', 191)->nullable()->index('product_addon_categories_product_uuid_foreign');
            $table->string('category_uuid', 191)->nullable()->index('product_addon_categories_category_uuid_foreign');
            $table->json('excluded_addons')->nullable();
            $table->mediumInteger('max_selectable')->nullable();
            $table->boolean('is_required')->nullable();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('product_addon_categories');
    }
};
