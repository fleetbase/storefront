<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A membership's status in a network: `active` (the store sells through the network)
     * or `suspended` (hidden from the network's app and carts without being removed, so
     * its category and history are kept and it can be reinstated).
     */
    public function up(): void
    {
        $connection = config('storefront.connection.db');

        if (!Schema::connection($connection)->hasColumn('network_stores', 'status')) {
            Schema::connection($connection)->table('network_stores', function (Blueprint $table) {
                $table->string('status', 20)->default('active')->after('category_uuid')->index('network_stores_status_index');
            });
        }
    }

    public function down(): void
    {
        $connection = config('storefront.connection.db');

        if (!Schema::connection($connection)->hasColumn('network_stores', 'status')) {
            return;
        }

        Schema::connection($connection)->table('network_stores', function (Blueprint $table) {
            $table->dropIndex('network_stores_status_index');
            $table->dropColumn('status');
        });
    }
};
