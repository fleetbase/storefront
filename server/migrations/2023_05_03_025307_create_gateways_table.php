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
        Schema::connection(config('storefront.connection.db'))->create('gateways', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->index();
            $table->char('company_uuid', 36)->nullable()->index('gateways_company_uuid_foreign');
            $table->char('created_by_uuid', 36)->nullable()->index('gateways_created_by_uuid_foreign');
            $table->char('logo_file_uuid', 36)->nullable()->index('gateways_logo_file_uuid_foreign');
            $table->char('owner_uuid', 36)->nullable();
            $table->string('owner_type')->nullable();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->boolean('sandbox')->default(false)->index();
            $table->json('meta')->nullable();
            $table->json('config')->nullable();
            $table->string('return_url')->nullable();
            $table->string('callback_url')->nullable();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('gateways');
    }
};
