<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection(config('storefront.connection.db'))->table('store_hours', function (Blueprint $table) {
            $table->foreign(['store_location_uuid'])->references(['uuid'])->on('store_locations')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('store_hours', function (Blueprint $table) {
            $table->dropForeign('store_hours_store_location_uuid_foreign');
        });
    }
};
