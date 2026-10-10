<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A cart's status: `open` (being shopped), `checked_out` (an order was created from
     * it) or `cleared` (the customer emptied or replaced it). Closed carts keep their
     * items, so there is a record of what every cart held; the device continues with a
     * new open cart. Carts already linked to a checkout are checked out.
     */
    public function up(): void
    {
        $connection = config('storefront.connection.db');

        if (!Schema::connection($connection)->hasColumn('carts', 'status')) {
            Schema::connection($connection)->table('carts', function (Blueprint $table) {
                $table->string('status', 20)->default('open')->after('checkout_uuid')->index('carts_status_index');
            });
        }

        DB::connection($connection)->table('carts')->whereNotNull('checkout_uuid')->update(['status' => 'checked_out']);
    }

    public function down(): void
    {
        $connection = config('storefront.connection.db');

        if (!Schema::connection($connection)->hasColumn('carts', 'status')) {
            return;
        }

        Schema::connection($connection)->table('carts', function (Blueprint $table) {
            $table->dropIndex('carts_status_index');
            $table->dropColumn('status');
        });
    }
};
