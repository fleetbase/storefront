<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * App Review access: one row per store or network that lets Apple and Google reviewers
     * sign in to its app with a fixed fictional phone number and a code the owner generates,
     * instead of an SMS they cannot receive.
     *
     * The code is stored hashed only (`code`); the plain code is shown once when generated.
     * It is kept out of the store and network `options`, which the public API returns.
     */
    public function up(): void
    {
        $databaseName = Utils::getFleetbaseDatabaseName();
        $schema       = Schema::connection(config('storefront.connection.db'));

        if ($schema->hasTable('app_review_accesses')) {
            return;
        }

        $schema->create('app_review_accesses', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignUuid('company_uuid')->nullable()->index()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->uuid('owner_uuid')->unique();
            $table->string('owner_type')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('code')->nullable();
            $table->timestamp('code_generated_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->foreignUuid('updated_by_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.users'))->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('app_review_accesses');
    }
};
