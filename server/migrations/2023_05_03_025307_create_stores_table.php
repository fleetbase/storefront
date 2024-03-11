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
        Schema::connection(config('storefront.connection.db'))->create('stores', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('public_id', 191)->nullable()->index();
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('created_by_uuid', 191)->nullable()->index('stores_created_by_uuid_foreign');
            $table->string('company_uuid', 191)->nullable()->index('stores_company_uuid_foreign');
            $table->string('logo_uuid', 191)->nullable()->index('stores_logo_uuid_foreign');
            $table->char('backdrop_uuid', 36)->nullable()->index('stores_backdrop_uuid_foreign');
            $table->string('name')->nullable();
            $table->longText('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('instagram')->nullable();
            $table->string('twitter')->nullable();
            $table->json('tags')->nullable();
            $table->json('translations')->nullable();
            $table->longText('key')->nullable();
            $table->boolean('online')->default(true)->index();
            $table->string('currency')->nullable();
            $table->string('timezone')->nullable();
            $table->string('pod_method')->nullable();
            $table->json('options')->nullable();
            $table->json('alertable')->nullable();
            $table->json('meta')->nullable();
            $table->string('slug', 191)->nullable()->index();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('stores');
    }
};
