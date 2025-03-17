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
        Schema::connection(config('storefront.connection.db'))->create('product_store_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('product_uuid', 191)->nullable()->index('product_store_locations_product_uuid_foreign');
            $table->string('store_location_uuid', 191)->nullable()->index('product_store_locations_store_location_uuid_foreign');
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('product_store_locations');
    }
};
