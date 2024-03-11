<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(config('storefront.connection.db'))->create('store_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('public_id', 191)->nullable()->index();
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('store_uuid', 191)->nullable()->index('store_locations_store_uuid_foreign');
            $table->string('created_by_uuid', 191)->nullable()->index('store_locations_created_by_uuid_foreign');
            $table->string('place_uuid', 191)->nullable()->index('store_locations_place_uuid_foreign');
            $table->string('name')->nullable();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('store_locations');
    }
};
