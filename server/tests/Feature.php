<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order as FleetOpsOrder;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Http\Controllers\AnalyticsController;
use Fleetbase\Storefront\Http\Resources\Index\Order as StorefrontOrderIndexResource;
use Fleetbase\Storefront\Http\Resources\Order as StorefrontOrderResource;

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

test('analytics top products extracts items from wrapped and raw cart state snapshots', function () {
    $controller = new AnalyticsController();
    $items      = [
        [
            'product_id' => 'product_1',
            'store_id'   => 'store_1',
            'quantity'   => 2,
            'subtotal'   => 50,
        ],
    ];

    expect($controller->cartStateItems(['items' => $items]))->toBe($items)
        ->and($controller->cartStateItems($items))->toBe($items)
        ->and($controller->cartStateItems((object) ['items' => $items]))->toBe($items)
        ->and($controller->cartStateItems(['subtotal' => 50]))->toBe([]);
});

test('storefront order detail resource includes checkout totals and transaction context', function () {
    $order = new FleetOpsOrder();
    $order->forceFill([
        'uuid'        => 'order_uuid',
        'public_id'   => 'order_123',
        'internal_id' => '1001',
        'status'      => 'created',
        'meta'        => [
            'storefront' => [
                'id'         => 'store_uuid',
                'public_id'  => 'store_123',
                'name'       => 'Tasty Store',
                'logo_url'   => 'https://example.com/store.png',
                'is_store'   => true,
                'is_network' => false,
                'extra'      => 'hidden',
            ],
            'subtotal'   => 42.25,
            'total'      => 50,
            'currency'   => 'USD',
            'gateway'    => 'cash',
            'is_pickup'  => true,
            'unrelated'  => 'hidden',
        ],
    ]);

    $customer = new Contact();
    $customer->forceFill(['uuid' => 'contact_uuid', 'name' => 'Ada Lovelace']);

    $transaction = new Transaction();
    $transaction->forceFill([
        'uuid'              => 'transaction_uuid',
        'amount'            => 50,
        'currency'          => 'USD',
        'status'            => 'success',
        'settlement_status' => 'paid',
    ]);

    $payload = new Payload();
    $payload->forceFill(['uuid' => 'payload_uuid']);
    $payload->setRelation('entities', collect());

    $order->setRelation('customer', $customer);
    $order->setRelation('transaction', $transaction);
    $order->setRelation('payload', $payload);
    $order->setRelation('trackingStatuses', collect());
    $order->setRelation('comments', collect());
    $order->setRelation('files', collect());

    $data = (new StorefrontOrderResource($order))->toArray(request());

    expect($data)->toBeArray()
        ->and($data['customer_name'])->toBe('Ada Lovelace')
        ->and($data['transaction_amount'])->toBe($order->transaction_amount)
        ->and($data['transaction'])->toMatchArray([
            'id'                => 'transaction_uuid',
            'amount'            => 50,
            'currency'          => 'USD',
            'status'            => 'success',
            'settlement_status' => 'paid',
        ])
        ->and($data['meta'])->toMatchArray([
            'subtotal'   => 42.25,
            'total'      => 50,
            'currency'   => 'USD',
            'gateway'    => 'cash',
            'is_pickup'  => true,
            'storefront' => [
                'id'         => 'store_uuid',
                'public_id'  => 'store_123',
                'name'       => 'Tasty Store',
                'logo_url'   => 'https://example.com/store.png',
                'is_store'   => true,
                'is_network' => false,
            ],
        ])
        ->and($data['meta']['storefront'])->not->toHaveKey('extra');
});

test('testing seeder purges seeded ledger storefront sale journals before orders', function () {
    $seeder = file_get_contents(__DIR__ . '/../seeders/Testing/CheckoutOrdersSeeder.php');

    expect($seeder)
        ->toContain('$orderUuids       = $this->seededUuids(Order::class)')
        ->toContain('$this->purgeSeededLedgerJournals($orderUuids)')
        ->toContain("->table('ledger_journals')")
        ->toContain("->where('type', 'storefront_sale')")
        ->toContain("->where('meta->seed', static::SEED_NAME)")
        ->toContain("->whereIn('meta->order_uuid', \$orderUuids)");
});

test('storefront navigator search endpoint is registered and returns navigator routes', function () {
    $routes     = file_get_contents(__DIR__ . '/../src/routes.php');
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/SearchController.php');

    expect($routes)
        ->toContain("\$router->get('search', 'SearchController@search')");

    expect($controller)
        ->toContain("private const SEARCH_TYPES = ['products', 'catalogs', 'customers', 'orders', 'networks', 'stores', 'food-trucks', 'gateways', 'notification-channels']")
        ->toContain("return response()->json(['results' => []]);")
        ->toContain("'products'              => 'storefront see product'")
        ->toContain("'customers'             => 'storefront see customer'")
        ->toContain("'notification-channels' => 'storefront see notification-channel'")
        ->toContain("'route'       => 'console.storefront.products.index.index.edit'")
        ->toContain("'route'       => 'console.storefront.customers.index.view'")
        ->toContain("'route'       => 'console.storefront.orders.index.view'")
        ->toContain("'route'       => 'console.storefront.networks.index.network'")
        ->toContain("'route'       => 'console.storefront.settings.notifications'")
        ->toContain("'models'      => [\$product->public_id]")
        ->toContain("'models'      => [\$customer->public_id]")
        ->toContain("'models'      => [\$order->public_id]")
        ->toContain("'models'      => [\$network->public_id]")
        ->toContain("'queryParams' => ['query' => \$query]");
});
