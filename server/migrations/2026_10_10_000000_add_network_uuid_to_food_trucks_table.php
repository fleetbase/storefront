<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A truck can be owned by a network instead of a store, so a marketplace can run
     * trucks alongside its member stores.
     */
    public function up(): void
    {
        $connection = config('storefront.connection.db');

        if (Schema::connection($connection)->hasColumn('food_trucks', 'network_uuid')) {
            return;
        }

        Schema::connection($connection)->table('food_trucks', function (Blueprint $table) {
            $table->char('network_uuid', 36)->nullable()->after('store_uuid')->index('food_trucks_network_uuid_index');
        });
    }

    public function down(): void
    {
        $connection = config('storefront.connection.db');

        if (!Schema::connection($connection)->hasColumn('food_trucks', 'network_uuid')) {
            return;
        }

        Schema::connection($connection)->table('food_trucks', function (Blueprint $table) {
            $table->dropIndex('food_trucks_network_uuid_index');
            $table->dropColumn('network_uuid');
        });
    }
};
