<?php

require_once __DIR__ . '/../../Support/PromotionTestHelpers.php';

use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Promotion;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Promotions\PromotionCalculator;
use Fleetbase\Storefront\Promotions\PromotionLine;

function calculatorPromotion(array $attributes): Promotion
{
    $promotion = new Promotion();
    $promotion->forceFill(array_merge([
        'owner_uuid' => 'network_uuid',
        'owner_type' => Network::class,
        'type'       => Promotion::TYPE_PERCENTAGE,
        'value'      => 10,
    ], $attributes));

    return $promotion;
}

function calculateDiscount(array $attributes, array $context = [], ?array $remaining = null, ?int $delivery = null): array|string
{
    $context = promotionContext($context);
    $full    = [];
    foreach ($context->lines as $line) {
        $full[$line->id] = $line->subtotal;
    }

    return (new PromotionCalculator())->calculate(calculatorPromotion($attributes), $context, $remaining ?? $full, $delivery ?? $context->deliveryFee);
}

test('allocation splits an amount proportionally, exactly and within each line', function () {
    expect(PromotionCalculator::allocate(100, ['a' => 100, 'b' => 100, 'c' => 100]))->toBe(['a' => 34, 'b' => 33, 'c' => 33])
        ->and(PromotionCalculator::allocate(1000, ['a' => 300, 'b' => 200]))->toBe(['a' => 300, 'b' => 200])
        ->and(PromotionCalculator::allocate(0, ['a' => 300]))->toBe(['a' => 0])
        ->and(PromotionCalculator::allocate(50, ['a' => 0, 'b' => 100]))->toBe(['a' => 0, 'b' => 50])
        ->and(PromotionCalculator::allocate(10, []))->toBe([]);
});

test('percentage and fixed amount promotions discount every eligible line', function () {
    expect(calculateDiscount(['value' => 10]))->toBe(['lines' => ['line_1' => 600, 'line_2' => 100, 'line_3' => 300], 'delivery' => 0])
        ->and(calculateDiscount(['value' => 250]))->toBe(['lines' => ['line_1' => 6000, 'line_2' => 1000, 'line_3' => 3000], 'delivery' => 0])
        ->and(calculateDiscount(['type' => Promotion::TYPE_FIXED_AMOUNT, 'value' => 1000]))->toBe(['lines' => ['line_1' => 600, 'line_2' => 100, 'line_3' => 300], 'delivery' => 0])
        ->and(calculateDiscount(['type' => Promotion::TYPE_FIXED_AMOUNT, 'value' => 99999]))->toBe(['lines' => ['line_1' => 6000, 'line_2' => 1000, 'line_3' => 3000], 'delivery' => 0]);
});

test('stacked promotions only discount what previous promotions left', function () {
    expect(calculateDiscount(['value' => 50], [], ['line_1' => 1000, 'line_2' => 0, 'line_3' => 3000]))
        ->toBe(['lines' => ['line_1' => 500, 'line_3' => 1500], 'delivery' => 0]);
});

test('free delivery promotions cover the remaining delivery fee and never apply to pickups', function () {
    expect(calculateDiscount(['type' => Promotion::TYPE_FREE_DELIVERY]))->toBe(['lines' => [], 'delivery' => 500])
        ->and(calculateDiscount(['type' => Promotion::TYPE_FREE_DELIVERY], [], null, 200))->toBe(['lines' => [], 'delivery' => 200])
        ->and(calculateDiscount(['type' => Promotion::TYPE_FREE_DELIVERY], ['is_pickup' => true, 'delivery_fee' => 0]))->toBe(PromotionCalculator::REASON_PICKUP_ORDER)
        ->and(calculateDiscount(['type' => Promotion::TYPE_FREE_DELIVERY], [], null, 0))->toBe(PromotionCalculator::REASON_NO_DISCOUNT);
});

test('buy X get Y discounts the cheapest units of every complete group', function () {
    // Units: 2 x 3000 (line_1), 1 x 1000 (line_2), 3 x 1000 (line_3) = 6 units -> 3 free with buy 1 get 1.
    expect(calculateDiscount(['type' => Promotion::TYPE_BOGO, 'bogo_config' => ['buy_quantity' => 1, 'get_quantity' => 1]]))
        ->toBe(['lines' => ['line_2' => 1000, 'line_3' => 2000], 'delivery' => 0])
        // Buy 2 get 1 at 50% off: 6 units -> 2 discounted units.
        ->and(calculateDiscount(['type' => Promotion::TYPE_BOGO, 'bogo_config' => ['buy_quantity' => 2, 'get_quantity' => 1, 'discount_percent' => 50]]))
        ->toBe(['lines' => ['line_2' => 500, 'line_3' => 500], 'delivery' => 0])
        // Not enough units for a group.
        ->and(calculateDiscount(['type' => Promotion::TYPE_BOGO, 'bogo_config' => ['buy_quantity' => 5, 'get_quantity' => 2]]))
        ->toBe(PromotionCalculator::REASON_NO_DISCOUNT)
        // A free unit never exceeds what is left on its line.
        ->and(calculateDiscount(['type' => Promotion::TYPE_BOGO, 'bogo_config' => []], [], ['line_1' => 6000, 'line_2' => 200, 'line_3' => 3000]))
        ->toBe(['lines' => ['line_2' => 200, 'line_3' => 2000], 'delivery' => 0]);
});

test('store promotions only apply to their own store items', function () {
    expect(calculateDiscount(['owner_uuid' => 'store_b_uuid', 'owner_type' => Store::class]))->toBe(['lines' => ['line_3' => 300], 'delivery' => 0])
        ->and(calculateDiscount(['owner_uuid' => 'store_b_uuid', 'owner_type' => 'storefront:store']))->toBe(['lines' => ['line_3' => 300], 'delivery' => 0])
        ->and(calculateDiscount(['owner_uuid' => 'store_c_uuid', 'owner_type' => Store::class]))->toBe(PromotionCalculator::REASON_NO_ELIGIBLE_ITEMS);
});

test('applies_to targets and excludes products categories and stores', function ($appliesTo, $expectedLines) {
    $discount = calculateDiscount(['value' => 10, 'applies_to' => $appliesTo]);

    expect(is_array($discount) ? array_keys($discount['lines']) : $discount)->toBe($expectedLines);
})->with([
    'products by public id'  => [['products' => ['product_2']], ['line_2']],
    'products by uuid'       => [['products' => ['product_1_uuid']], ['line_1']],
    'categories'             => [['categories' => ['category_food']], ['line_1', 'line_3']],
    'products or categories' => [['products' => ['product_2'], 'categories' => ['category_food']], ['line_1', 'line_2', 'line_3']],
    'stores'                 => [['stores' => ['store_b']], ['line_3']],
    'excluded products'      => [['exclude_products' => ['product_1']], ['line_2', 'line_3']],
    'excluded categories'    => [['exclude_categories' => ['category_food']], ['line_2']],
    'nothing eligible'       => [['products' => ['unknown']], PromotionCalculator::REASON_NO_ELIGIBLE_ITEMS],
]);

test('minimum subtotal and item conditions use the eligible items only', function () {
    expect(calculateDiscount(['min_subtotal' => 10000]))->toBeArray()
        ->and(calculateDiscount(['min_subtotal' => 10001]))->toBe(PromotionCalculator::REASON_MIN_SUBTOTAL)
        ->and(calculateDiscount(['min_subtotal' => 5000, 'applies_to' => ['stores' => ['store_b']]]))->toBe(PromotionCalculator::REASON_MIN_SUBTOTAL)
        ->and(calculateDiscount(['min_items' => 6]))->toBeArray()
        ->and(calculateDiscount(['min_items' => 7]))->toBe(PromotionCalculator::REASON_MIN_ITEMS);
});

test('unknown promotion types and zero discounts do not apply', function () {
    expect(calculateDiscount(['type' => 'mystery']))->toBe(PromotionCalculator::REASON_NO_DISCOUNT)
        ->and(calculateDiscount(['value' => 0]))->toBe(PromotionCalculator::REASON_NO_DISCOUNT)
        ->and(calculateDiscount(['value' => -5]))->toBe(PromotionCalculator::REASON_NO_DISCOUNT);
});

test('promotion lines expose unit prices and match identifiers', function () {
    $line  = new PromotionLine('line', 900, 3, 'product', 'product_uuid', null, 'store', 'store_uuid');
    $empty = new PromotionLine('empty', 900, 0);

    expect($line->unitPrice())->toBe(300.0)
        ->and($empty->unitPrice())->toBe(0.0)
        ->and($line->matchesCategory(['anything']))->toBeFalse()
        ->and($line->matchesStore(['store_uuid']))->toBeTrue()
        ->and($line->matchesProduct([]))->toBeFalse();
});
