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

        Schema::connection(config('storefront.connection.db'))->table('carts', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['checkout_uuid'])->references(['uuid'])->on('checkouts')->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['company_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.companies'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['user_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.users'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('carts', function (Blueprint $table) {
            $table->dropForeign('carts_checkout_uuid_foreign');
            $table->dropForeign('carts_company_uuid_foreign');
            $table->dropForeign('carts_user_uuid_foreign');
        });
    }
};
