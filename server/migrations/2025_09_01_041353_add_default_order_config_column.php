<?php

use Fleetbase\Storefront\Support\Storefront;
use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $databaseName   = Utils::getFleetbaseDatabaseName();
        $sfConnection   = config('storefront.connection.db');

        Schema::connection($sfConnection)->table('stores', function (Blueprint $table) use ($databaseName) {
            // nullable because we’ll backfill after
            $table->foreignUuid('order_config_uuid')
                ->nullable()
                ->after('backdrop_uuid')
                ->constrained(new Expression($databaseName . '.order_configs'), 'uuid')
                ->nullOnDelete();
        });

        Schema::connection($sfConnection)->table('networks', function (Blueprint $table) use ($databaseName) {
            $table->foreignUuid('order_config_uuid')
                ->nullable()
                ->after('backdrop_uuid')
                ->constrained(new Expression($databaseName . '.order_configs'), 'uuid')
                ->nullOnDelete();
        });

        DB::connection($sfConnection)->transaction(function () use ($sfConnection) {
            // Pull valid company UUIDs from core.companies
            $validCompanyUuids = DB::connection(config('database.default'))
            ->table('companies')
            ->pluck('uuid')
            ->all();

            // STORES: distinct companies missing an order_config_uuid
            $storeCompanyIds = DB::connection($sfConnection)
                ->table('stores')
                ->whereNull('order_config_uuid')
                ->whereNotNull('company_uuid')
                ->distinct()
                ->pluck('company_uuid')
                ->filter(fn ($uuid) => in_array($uuid, $validCompanyUuids, true))
                ->values();

            foreach ($storeCompanyIds as $companyUuid) {
                // Resolve company-scoped default config
                $config = Storefront::getOrderConfig($companyUuid);
                if ($config) {
                    DB::connection($sfConnection)
                        ->table('stores')
                        ->where('company_uuid', $companyUuid)
                        ->whereNull('order_config_uuid')
                        ->update(['order_config_uuid' => $config->uuid]);
                }
            }

            // NETWORKS: distinct companies missing an order_config_uuid
            $networkCompanyIds = DB::connection($sfConnection)
                ->table('networks')
                ->whereNull('order_config_uuid')
                ->whereNotNull('company_uuid')
                ->distinct()
                ->pluck('company_uuid')
                ->filter(fn ($uuid) => in_array($uuid, $validCompanyUuids, true))
                ->values();

            foreach ($networkCompanyIds as $companyUuid) {
                $config = Storefront::getOrderConfig($companyUuid);
                if ($config) {
                    DB::connection($sfConnection)
                        ->table('networks')
                        ->where('company_uuid', $companyUuid)
                        ->whereNull('order_config_uuid')
                        ->update(['order_config_uuid' => $config->uuid]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $sfConnection = config('storefront.connection.db');

        // Drop FK and column for stores
        Schema::connection($sfConnection)->table('stores', function (Blueprint $table) {
            // Drop the foreign key constraint first, then the column
            // Default FK name pattern: {table}_{column}_foreign
            $table->dropForeign(['order_config_uuid']);
            $table->dropColumn('order_config_uuid');
        });

        // Drop FK and column for networks
        Schema::connection($sfConnection)->table('networks', function (Blueprint $table) {
            $table->dropForeign(['order_config_uuid']);
            $table->dropColumn('order_config_uuid');
        });
    }
};
