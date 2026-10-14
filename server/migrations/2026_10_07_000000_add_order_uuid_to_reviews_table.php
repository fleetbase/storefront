<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Link a review to the completed order it was written for.
     *
     * Reviews from customers are only accepted for a completed order that contains the
     * store or product, and an order can be reviewed once per subject.
     */
    public function up(): void
    {
        $connection = config('storefront.connection.db');

        if (Schema::connection($connection)->hasColumn('reviews', 'order_uuid')) {
            return;
        }

        Schema::connection($connection)->table('reviews', function (Blueprint $table) {
            $table->char('order_uuid', 36)->nullable()->after('customer_uuid');
            $table->index(['customer_uuid', 'subject_uuid', 'order_uuid'], 'reviews_customer_subject_order_index');
        });
    }

    public function down(): void
    {
        $connection = config('storefront.connection.db');

        if (!Schema::connection($connection)->hasColumn('reviews', 'order_uuid')) {
            return;
        }

        Schema::connection($connection)->table('reviews', function (Blueprint $table) {
            $table->dropIndex('reviews_customer_subject_order_index');
            $table->dropColumn('order_uuid');
        });
    }
};
