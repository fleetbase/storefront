<?php

use Illuminate\Database\Migrations\Migration;
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
        Schema::connection(config('storefront.connection.db'))->create('product_variants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('public_id', 191)->nullable()->index();
            $table->char('uuid', 36)->nullable()->unique();
            $table->string('product_uuid', 191)->nullable()->index('product_variants_product_uuid_foreign');
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->json('translations')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('is_multiselect')->default(false);
            $table->boolean('is_required')->default(false);
            $table->mediumInteger('min')->default(0);
            $table->mediumInteger('max')->default(1);
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
        Schema::connection(config('storefront.connection.db'))->dropIfExists('product_variants');
    }
};
