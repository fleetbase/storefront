<?php

use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\PromotionCode;
use Fleetbase\Storefront\Promotions\PromotionContext;
use Fleetbase\Storefront\Promotions\PromotionLine;
use Illuminate\Database\Eloquent\Model;

if (!function_exists('promotionDb')) {
    function promotionDb()
    {
        return Model::getConnectionResolver()->connection('mysql');
    }

    /**
     * Create the tables the promotion engine reads, dropping previous copies.
     */
    function createPromotionSchema(): void
    {
        $schema = promotionDb()->getSchemaBuilder();
        foreach (['promotion_redemptions', 'promotion_codes', 'promotions', 'products', 'stores', 'networks', 'network_stores', 'orders'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('promotions', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('created_by_uuid')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->string('trigger')->default('automatic');
            $table->string('type')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->integer('max_discount_amount')->nullable();
            $table->string('currency')->nullable();
            $table->integer('min_subtotal')->nullable();
            $table->integer('min_items')->nullable();
            $table->text('applies_to')->nullable();
            $table->text('bogo_config')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_limit_per_customer')->nullable();
            $table->integer('budget_amount')->nullable();
            $table->boolean('stackable')->default(false);
            $table->integer('priority')->default(0);
            $table->boolean('is_public')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('schedule')->nullable();
            $table->string('timezone')->nullable();
            $table->string('image_uuid')->nullable();
            $table->text('translations')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('promotion_codes', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('promotion_uuid')->nullable();
            $table->string('code');
            $table->string('status')->default('active');
            $table->integer('usage_limit')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('promotion_redemptions', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('company_uuid')->nullable();
            $table->string('promotion_uuid')->nullable();
            $table->string('promotion_code_uuid')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('checkout_uuid')->nullable();
            $table->string('order_uuid')->nullable();
            $table->integer('amount')->default(0);
            $table->string('currency')->nullable();
            $table->string('status')->default('reserved');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('products', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('store_uuid')->nullable();
            $table->string('category_uuid')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        foreach (['stores', 'networks'] as $name) {
            $schema->create($name, function ($table) {
                $table->increments('id');
                $table->string('uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('company_uuid')->nullable();
                $table->string('key')->nullable();
                $table->string('name')->nullable();
                $table->text('options')->nullable();
                $table->timestamps();
                $table->timestamp('deleted_at')->nullable();
            });
        }
        $schema->create('network_stores', function ($table) {
            $table->increments('id');
            $table->string('network_uuid')->nullable();
            $table->string('store_uuid')->nullable();
            $table->string('category_uuid')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('orders', function ($table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('public_id')->nullable();
            $table->string('customer_uuid')->nullable();
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    /**
     * Persist a promotion. Model events do not run in the test harness, so identifiers are explicit.
     */
    function makePromotion(array $attributes = []): Promotion
    {
        static $sequence = 0;
        $sequence++;

        $promotion = new Promotion();
        $promotion->forceFill(array_merge([
            'uuid'         => 'promo_uuid_' . $sequence,
            'public_id'    => 'promotion_' . $sequence,
            'company_uuid' => 'company_uuid',
            'owner_uuid'   => 'store_a_uuid',
            'owner_type'   => Fleetbase\Storefront\Models\Store::class,
            'name'         => 'Promotion ' . $sequence,
            'status'       => Promotion::STATUS_ACTIVE,
            'trigger'      => Promotion::TRIGGER_AUTOMATIC,
            'type'         => Promotion::TYPE_PERCENTAGE,
            'value'        => 10,
        ], $attributes));
        $promotion->save();

        return $promotion;
    }

    function makePromotionCode(Promotion $promotion, string $code, array $attributes = []): PromotionCode
    {
        $model = new PromotionCode();
        $model->forceFill(array_merge([
            'uuid'           => 'code_uuid_' . strtolower($code),
            'public_id'      => 'promo_code_' . strtolower($code),
            'company_uuid'   => 'company_uuid',
            'promotion_uuid' => $promotion->uuid,
            'code'           => $code,
            'status'         => PromotionCode::STATUS_ACTIVE,
        ], $attributes));
        $model->save();

        return $model;
    }

    function seedPromotionStores(): void
    {
        promotionDb()->table('stores')->insert([
            ['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_uuid', 'key' => 'store_key_a', 'name' => 'Store A'],
            ['uuid' => 'store_b_uuid', 'public_id' => 'store_b', 'company_uuid' => 'company_uuid', 'key' => 'store_key_b', 'name' => 'Store B'],
        ]);
        promotionDb()->table('networks')->insert(['uuid' => 'network_uuid', 'public_id' => 'network_m', 'company_uuid' => 'company_uuid', 'key' => 'network_key', 'name' => 'Market']);
        promotionDb()->table('network_stores')->insert([
            ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_a_uuid'],
            ['network_uuid' => 'network_uuid', 'store_uuid' => 'store_b_uuid'],
        ]);
    }

    /**
     * A context with two store A lines and one store B line (subtotal 10000).
     */
    function promotionContext(array $overrides = []): PromotionContext
    {
        $storefront = $overrides['storefront'] ?? tap(new Fleetbase\Storefront\Models\Store())->forceFill(['uuid' => 'store_a_uuid', 'public_id' => 'store_a', 'company_uuid' => 'company_uuid']);

        return new PromotionContext(
            $storefront,
            $overrides['lines'] ?? [
                new PromotionLine('line_1', 6000, 2, 'product_1', 'product_1_uuid', 'category_food', 'store_a', 'store_a_uuid'),
                new PromotionLine('line_2', 1000, 1, 'product_2', 'product_2_uuid', 'category_drinks', 'store_a', 'store_a_uuid'),
                new PromotionLine('line_3', 3000, 3, 'product_3', 'product_3_uuid', 'category_food', 'store_b', 'store_b_uuid'),
            ],
            $overrides['currency'] ?? 'USD',
            $overrides['customer'] ?? null,
            $overrides['is_pickup'] ?? false,
            $overrides['delivery_fee'] ?? 500,
            $overrides['now'] ?? null,
        );
    }
}
