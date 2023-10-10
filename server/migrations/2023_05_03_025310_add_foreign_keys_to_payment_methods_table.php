<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Schema;
use Fleetbase\Support\Utils;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $databaseName = Utils::getFleetbaseDatabaseName();

        Schema::connection(config('storefront.connection.db'))->table('payment_methods', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['company_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.companies'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['gateway_uuid'])->references(['uuid'])->on('gateways')->onUpdate('NO ACTION')->onDelete('NO ACTION');
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
        Schema::connection(config('storefront.connection.db'))->table('payment_methods', function (Blueprint $table) {
            $table->dropForeign('payment_methods_company_uuid_foreign');
            $table->dropForeign('payment_methods_gateway_uuid_foreign');
            $table->dropForeign('payment_methods_store_uuid_foreign');
        });
    }
};
