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

        Schema::connection(config('storefront.connection.db'))->create('catalog_subjects', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique()->default(Str::uuid()->toString());
            $table->foreignUuid('catalog_uuid')
                ->references('uuid')
                ->on('catalogs')
                ->onUpdate('CASCADE')
                ->onDelete('CASCADE');
            $table->string('subject_type');
            $table->uuid('subject_uuid');
            $table->foreignUuid('company_uuid')
                ->nullable()
                ->references('uuid')
                ->on(new Expression($databaseName . '.companies'))
                ->onUpdate('CASCADE')
                ->onDelete('SET NULL');

            $table->foreignUuid('created_by_uuid')
                ->nullable()
                ->references('uuid')
                ->on(new Expression($databaseName . '.users'))
                ->onUpdate('CASCADE')
                ->onDelete('SET NULL');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['subject_type', 'subject_uuid'], 'catalog_subject_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('catalog_subjects');
    }
};
