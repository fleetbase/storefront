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
        Schema::connection(config('storefront.connection.db'))->table('product_variant_options', function (Blueprint $table) {
            $table->foreign(['product_variant_uuid'])->references(['uuid'])->on('product_variants')->onUpdate('NO ACTION')->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->table('product_variant_options', function (Blueprint $table) {
            $table->dropForeign('product_variant_options_product_variant_uuid_foreign');
        });
    }
};
