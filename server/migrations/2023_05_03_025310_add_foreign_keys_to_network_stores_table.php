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

        Schema::connection(config('storefront.connection.db'))->table('network_stores', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['category_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.categories'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['network_uuid'])->references(['uuid'])->on('networks')->onUpdate('NO ACTION')->onDelete('NO ACTION');
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
        Schema::connection(config('storefront.connection.db'))->table('network_stores', function (Blueprint $table) {
            $table->dropForeign('network_stores_category_uuid_foreign');
            $table->dropForeign('network_stores_network_uuid_foreign');
            $table->dropForeign('network_stores_store_uuid_foreign');
        });
    }
};
