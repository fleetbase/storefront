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
        Schema::connection(config('storefront.connection.db'))->create('reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('public_id')->nullable()->unique();
            $table->string('created_by_uuid')->nullable()->index('reviews_created_by_uuid_foreign');
            $table->char('customer_uuid', 36)->nullable()->index('reviews_customer_uuid_foreign');
            $table->char('subject_uuid', 36)->nullable();
            $table->string('subject_type')->nullable();
            $table->mediumInteger('rating')->nullable();
            $table->text('content')->nullable();
            $table->boolean('rejected')->default(false);
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('reviews');
    }
};
