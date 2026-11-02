<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The Sign in with Apple refresh token a customer's app was given at sign-in, one per
     * customer per app (client_id is the app's bundle ID). Kept only so the token can be
     * revoked with Apple when the customer deletes their account. The token is encrypted.
     */
    public function up(): void
    {
        $schema = Schema::connection(config('storefront.connection.db'));

        if (!$schema->hasTable('apple_sign_in_tokens')) {
            $schema->create('apple_sign_in_tokens', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->unique();
                $table->uuid('company_uuid')->nullable()->index();
                $table->uuid('customer_uuid')->index();
                $table->uuid('user_uuid')->nullable()->index();
                $table->string('apple_user_id')->nullable();
                $table->string('client_id');
                $table->text('refresh_token');
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['customer_uuid', 'client_id'], 'apple_sign_in_tokens_customer_client_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection(config('storefront.connection.db'))->dropIfExists('apple_sign_in_tokens');
    }
};
