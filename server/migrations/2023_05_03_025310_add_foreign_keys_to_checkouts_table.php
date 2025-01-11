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

        Schema::connection(config('storefront.connection.db'))->table('checkouts', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['cart_uuid'])->references(['uuid'])->on('carts')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['company_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.companies'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['gateway_uuid'])->references(['uuid'])->on('gateways')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['network_uuid'])->references(['uuid'])->on('networks')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['order_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.orders'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['service_quote_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.service_quotes'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
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
        Schema::connection(config('storefront.connection.db'))->table('checkouts', function (Blueprint $table) {
            $table->dropForeign('checkouts_cart_uuid_foreign');
            $table->dropForeign('checkouts_company_uuid_foreign');
            $table->dropForeign('checkouts_gateway_uuid_foreign');
            $table->dropForeign('checkouts_network_uuid_foreign');
            $table->dropForeign('checkouts_order_uuid_foreign');
            $table->dropForeign('checkouts_service_quote_uuid_foreign');
            $table->dropForeign('checkouts_store_uuid_foreign');
        });
    }
};
