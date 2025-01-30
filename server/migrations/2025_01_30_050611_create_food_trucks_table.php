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
        // If your main Fleetbase DB name is needed for references:
        $databaseName = Utils::getFleetbaseDatabaseName();

        Schema::connection(config('storefront.connection.db'))->create('food_trucks', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->default(Str::uuid()->toString());

            // The vehicle UUID from Fleetbase "vehicles" table
            $table->foreignUuid('vehicle_uuid')
                ->nullable()
                ->references('uuid')
                ->on(new Expression($databaseName . '.vehicles'))
                ->onUpdate('CASCADE')
                ->onDelete('SET NULL');

            // The store that "owns" this food truck
            $table->foreignUuid('store_uuid')
                ->nullable()
                ->references('uuid')
                ->on('stores')
                ->onUpdate('CASCADE')
                ->onDelete('SET NULL');

            // The company UUID from Fleetbase "companies" table
            $table->foreignUuid('company_uuid')
                ->nullable()
                ->references('uuid')
                ->on(new Expression($databaseName . '.companies'))
                ->onUpdate('CASCADE')
                ->onDelete('SET NULL');

            // The user who created this record
            $table->foreignUuid('created_by_uuid')
                ->nullable()
                ->references('uuid')
                ->on(new Expression($databaseName . '.users'))
                ->onUpdate('CASCADE')
                ->onDelete('SET NULL');

            // Add any additional fields you might need:
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('inactive');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('food_trucks');
    }
};
