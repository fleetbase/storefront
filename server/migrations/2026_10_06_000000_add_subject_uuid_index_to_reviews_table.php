<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Store ratings average reviews by subject_uuid. Without an index every
     * rating lookup scanned the whole reviews table.
     */
    public function up(): void
    {
        if ($this->indexExists('reviews', 'reviews_subject_uuid_index')) {
            return;
        }

        Schema::connection(config('storefront.connection.db'))->table('reviews', function (Blueprint $table) {
            $table->index('subject_uuid');
        });
    }

    public function down(): void
    {
        if (!$this->indexExists('reviews', 'reviews_subject_uuid_index')) {
            return;
        }

        Schema::connection(config('storefront.connection.db'))->table('reviews', function (Blueprint $table) {
            $table->dropIndex(['subject_uuid']);
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        try {
            $indexes = Schema::connection(config('storefront.connection.db'))
                ->getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes($table);

            return isset($indexes[$index]);
        } catch (Throwable $e) {
            return false;
        }
    }
};
