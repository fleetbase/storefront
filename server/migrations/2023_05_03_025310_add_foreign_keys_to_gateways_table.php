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

        Schema::connection(config('storefront.connection.db'))->table('gateways', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['company_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.companies'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['created_by_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.users'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['logo_file_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.files'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('gateways', function (Blueprint $table) {
            $table->dropForeign('gateways_company_uuid_foreign');
            $table->dropForeign('gateways_created_by_uuid_foreign');
            $table->dropForeign('gateways_logo_file_uuid_foreign');
        });
    }
};
