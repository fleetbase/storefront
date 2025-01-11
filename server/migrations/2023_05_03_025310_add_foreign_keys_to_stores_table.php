<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
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
        $databaseName = Utils::getFleetbaseDatabaseName();

        Schema::connection(config('storefront.connection.db'))->table('stores', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['backdrop_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.files'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['company_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.companies'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['created_by_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.users'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['logo_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.files'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('stores', function (Blueprint $table) {
            $table->dropForeign('stores_backdrop_uuid_foreign');
            $table->dropForeign('stores_company_uuid_foreign');
            $table->dropForeign('stores_created_by_uuid_foreign');
            $table->dropForeign('stores_logo_uuid_foreign');
        });
    }
};
