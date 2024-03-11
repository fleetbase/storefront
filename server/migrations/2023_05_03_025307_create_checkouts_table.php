<?php

use Illuminate\Database\Migrations\Migration;
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
        Schema::connection(config('storefront.connection.db'))->create('checkouts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->index();
            $table->char('company_uuid', 36)->nullable()->index('checkouts_company_uuid_foreign');
            $table->char('order_uuid', 36)->nullable()->index('checkouts_order_uuid_foreign');
            $table->char('cart_uuid', 36)->nullable()->index('checkouts_cart_uuid_foreign');
            $table->char('store_uuid', 36)->nullable()->index('checkouts_store_uuid_foreign');
            $table->char('network_uuid', 36)->nullable()->index('checkouts_network_uuid_foreign');
            $table->char('gateway_uuid', 36)->nullable()->index('checkouts_gateway_uuid_foreign');
            $table->char('service_quote_uuid', 36)->nullable()->index('checkouts_service_quote_uuid_foreign');
            $table->char('owner_uuid', 36)->nullable();
            $table->string('owner_type')->nullable();
            $table->string('token')->nullable();
            $table->integer('amount')->nullable();
            $table->string('currency')->nullable();
            $table->boolean('is_cod')->default(false);
            $table->boolean('is_pickup')->default(false);
            $table->json('options')->nullable();
            $table->json('cart_state')->nullable();
            $table->boolean('captured')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('checkouts');
    }
};
