<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $databaseName = Utils::getFleetbaseDatabaseName();

        Schema::connection(config('storefront.connection.db'))->create('catalogs', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->default(Str::uuid()->toString());
            $table->string('public_id')->unique()->nullable();
            $table->foreignUuid('store_uuid')->nullable()->references('uuid')->on('stores')->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->foreignUuid('company_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->foreignUuid('created_by_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.users'))->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('catalogs');
    }
};
