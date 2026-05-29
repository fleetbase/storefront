<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order as FleetOpsOrder;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Http\Resources\Index\Order as StorefrontOrderIndexResource;

test('storefront order index resource includes checkout meta and storefront display fields', function () {
    $order = new FleetOpsOrder();
    $order->forceFill([
        'public_id'   => 'order_123',
        'internal_id' => '1001',
        'status'      => 'created',
        'meta'        => [
            'subtotal'     => 42.25,
            'delivery_fee' => 7.75,
            'total'        => 50,
            'currency'     => 'USD',
            'is_pickup'    => false,
            'unrelated'    => 'hidden',
        ],
    ]);
    $customer = new Contact();
    $customer->forceFill(['name' => 'Ada Lovelace']);

    $transaction = new Transaction();
    $transaction->forceFill(['amount' => 50]);

    $order->setRelation('customer', $customer);
    $order->setRelation('transaction', $transaction);

    $data = (new StorefrontOrderIndexResource($order))->toArray(request());

    expect($data)->toBeArray()
        ->and($data['customer_name'])->toBe('Ada Lovelace')
        ->and($data['transaction_amount'])->toBe($order->transaction_amount)
        ->and($data['meta'])->toMatchArray([
            '_index_resource' => true,
            'subtotal'        => 42.25,
            'delivery_fee'    => 7.75,
            'total'           => 50,
            'currency'        => 'USD',
            'is_pickup'       => false,
        ])
        ->and($data['meta'])->not->toHaveKey('unrelated');
});
