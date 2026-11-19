<?php

use Fleetbase\Storefront\Push\NotificationChannelMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Each store's and network's APNs, FCM and Sign in with Apple notification channels become
     * one push app owned by it, and the devices registered from it are linked to that push
     * app. Runs after core-api's push apps migrations. The channels are kept, so running it
     * again only picks up channels it hasn't moved yet.
     */
    public function up(): void
    {
        if (!Schema::hasTable('push_apps') || !Schema::hasColumn('user_devices', 'push_app_uuid') || !Schema::connection(config('storefront.connection.db'))->hasTable('notification_channels')) {
            return;
        }

        app(NotificationChannelMigrator::class)->migrate();
    }

    public function down(): void
    {
        if (!Schema::hasTable('push_apps')) {
            return;
        }

        app(NotificationChannelMigrator::class)->rollback();
    }
};
