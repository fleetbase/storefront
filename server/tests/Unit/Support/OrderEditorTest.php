<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\Storefront\Support\OrderEditor;

class StorefrontOrderEditorProbe extends OrderEditor
{
    public static function draftDropoff(Order $order, array $input): ?Place
    {
        return static::resolveDropoff($order, $input, false, false);
    }
}

test('pricing an edit to a typed address builds the place without saving it', function () {
    $order = new Order();
    $order->forceFill(['uuid' => 'order-uuid', 'company_uuid' => 'company-uuid']);

    $place = StorefrontOrderEditorProbe::draftDropoff($order, [
        'street1'  => '8 Marina View',
        'street2'  => '#18-04',
        'phone'    => '+65 5550 1102',
        'location' => ['type' => 'Point', 'coordinates' => [103.8522, 1.2789]],
    ]);

    expect($place)->toBeInstanceOf(Place::class)
        ->and($place->exists)->toBeFalse()
        ->and($place->uuid)->toBeNull()
        ->and($place->street1)->toBe('8 Marina View')
        ->and($place->street2)->toBe('#18-04')
        ->and(round($place->location->getLat(), 4))->toBe(1.2789)
        ->and(round($place->location->getLng(), 4))->toBe(103.8522);
});

test('an edit to an address with neither a street nor a pin has no drop-off', function () {
    $order = new Order();
    $order->forceFill(['uuid' => 'order-uuid', 'company_uuid' => 'company-uuid']);

    expect(StorefrontOrderEditorProbe::draftDropoff($order, ['street2' => '#18-04']))->toBeNull();
});
