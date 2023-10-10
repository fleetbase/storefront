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
        Schema::connection(config('storefront.connection.db'))->create('carts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('public_id')->nullable()->index();
            $table->char('company_uuid', 36)->nullable()->index('carts_company_uuid_foreign');
            $table->char('user_uuid', 36)->nullable()->index('carts_user_uuid_foreign');
            $table->char('checkout_uuid', 36)->nullable()->index('carts_checkout_uuid_foreign');
            $table->string('customer_id')->nullable();
            $table->string('unique_identifier')->nullable();
            $table->string('currency')->nullable();
            $table->string('discount_code')->nullable();
            $table->json('items')->nullable();
            $table->json('events')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('carts');
    }
};
