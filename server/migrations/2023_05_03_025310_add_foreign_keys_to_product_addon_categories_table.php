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

        Schema::connection(config('storefront.connection.db'))->table('product_addon_categories', function (Blueprint $table) use ($databaseName) {
            $table->foreign(['category_uuid'])->references(['uuid'])->on(new Expression($databaseName . '.categories'))->onUpdate('NO ACTION')->onDelete('NO ACTION');
            $table->foreign(['product_uuid'])->references(['uuid'])->on('products')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('product_addon_categories', function (Blueprint $table) {
            $table->dropForeign('product_addon_categories_category_uuid_foreign');
            $table->dropForeign('product_addon_categories_product_uuid_foreign');
        });
    }
};
