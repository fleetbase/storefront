<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('storefront.connection.db'))->table('checkouts', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id', 191)
                ->nullable()
                ->unique()
                ->after('gateway_uuid');
        });
    }

    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->table('checkouts', function (Blueprint $table) {
            $table->dropUnique(['stripe_payment_intent_id']);
            $table->dropColumn('stripe_payment_intent_id');
        });
    }
};
