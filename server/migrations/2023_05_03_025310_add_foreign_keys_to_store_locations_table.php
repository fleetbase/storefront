<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
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
        $databaseName = Utils::getFleetbaseDatabaseName();

        Schema::connection(config('storefront.connection.db'))->table('store_locations', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['created_by_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.users'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['place_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.places'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['store_uuid'])->references(['uuid'])->on('stores')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('store_locations', function (Blueprint $table) {
            $table->dropForeign('store_locations_created_by_uuid_foreign');
            $table->dropForeign('store_locations_place_uuid_foreign');
            $table->dropForeign('store_locations_store_uuid_foreign');
        });
    }
};
