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
        Schema::connection(config('storefront.connection.db'))->create('notification_channels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->index();
            $table->char('company_uuid', 36)->nullable()->index('notification_channels_company_uuid_foreign');
            $table->char('created_by_uuid', 36)->nullable()->index('notification_channels_created_by_uuid_foreign');
            $table->string('owner_uuid')->nullable();
            $table->string('owner_type')->nullable();
            $table->char('certificate_uuid', 36)->nullable()->index('notification_channels_certificate_uuid_foreign');
            $table->json('config')->nullable();
            $table->json('options')->nullable();
            $table->string('name')->nullable();
            $table->string('scheme')->nullable();
            $table->string('app_key')->nullable();
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('notification_channels');
    }
};
