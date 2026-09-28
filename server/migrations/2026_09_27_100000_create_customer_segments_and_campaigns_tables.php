<?php

use Fleetbase\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Customer segments (rule based customer audiences) and campaigns (notifications sent to
     * an audience now or at a scheduled time, optionally announcing a promotion).
     */
    public function up(): void
    {
        $databaseName = Utils::getFleetbaseDatabaseName();
        $schema       = Schema::connection(config('storefront.connection.db'));

        $schema->create('customer_segments', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id')->unique()->nullable();
            $table->foreignUuid('company_uuid')->nullable()->index()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreignUuid('created_by_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.users'))->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->uuid('owner_uuid')->nullable()->index();
            $table->string('owner_type')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('rules')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('campaigns', function (Blueprint $table) use ($databaseName) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('public_id')->unique()->nullable();
            $table->foreignUuid('company_uuid')->nullable()->index()->references('uuid')->on(new Expression($databaseName . '.companies'))->onUpdate('CASCADE')->onDelete('CASCADE');
            $table->foreignUuid('created_by_uuid')->nullable()->references('uuid')->on(new Expression($databaseName . '.users'))->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->uuid('owner_uuid')->nullable()->index();
            $table->string('owner_type')->nullable();
            $table->foreignUuid('segment_uuid')->nullable()->references('uuid')->on('customer_segments')->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->foreignUuid('promotion_uuid')->nullable()->references('uuid')->on('promotions')->onUpdate('CASCADE')->onDelete('SET NULL');
            $table->json('recipients')->nullable();
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->json('channels')->nullable();
            $table->string('title');
            $table->text('body');
            $table->uuid('image_uuid')->nullable();
            $table->json('action')->nullable();
            $table->timestamp('send_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('stats')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('storefront.connection.db'));
        $schema->dropIfExists('campaigns');
        $schema->dropIfExists('customer_segments');
    }
};
