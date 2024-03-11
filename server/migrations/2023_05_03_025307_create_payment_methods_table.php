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
        Schema::connection(config('storefront.connection.db'))->create('payment_methods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->index();
            $table->char('company_uuid', 36)->nullable()->index('payment_methods_company_uuid_foreign');
            $table->char('store_uuid', 36)->nullable()->index('payment_methods_store_uuid_foreign');
            $table->char('gateway_uuid', 36)->nullable()->index('payment_methods_gateway_uuid_foreign');
            $table->char('owner_uuid', 36)->nullable();
            $table->string('owner_type')->nullable();
            $table->string('gateway_id')->nullable();
            $table->string('type')->nullable();
            $table->string('brand')->nullable();
            $table->string('last4')->nullable();
            $table->json('meta')->nullable();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('payment_methods');
    }
};
