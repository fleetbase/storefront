<?php

use Fleetbase\Storefront\Console\Commands\NotifyStorefrontOrderNearby;
use Fleetbase\Storefront\Http\Controllers\AddonCategoryController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

test('addon category create and update contain persistence failures as API responses', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('product_addons');
    $schema->dropIfExists('categories');
    $controller = new AddonCategoryController();
    $create     = $controller->createRecord(Request::create('/addon-categories', 'POST', [
        'addonCategory' => ['name' => 'Unavailable category'],
    ]));
    $update = $controller->updateRecord(Request::create('/addon-categories/missing', 'PATCH', [
        'addonCategory' => ['name' => 'Unavailable category'],
    ]), 'missing');

    expect($create->getStatusCode())->toBe(400)
        ->and($create->getData(true))->toHaveKey('error')
        ->and($update->getStatusCode())->toBe(400)
        ->and($update->getData(true))->toHaveKey('error');
});

test('addon category controller persists category details and addon changes', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('product_addons');
    $schema->dropIfExists('categories');
    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->string('for')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('product_addons', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('description')->nullable();
        $table->text('translations')->nullable();
        $table->integer('price')->default(0);
        $table->integer('sale_price')->default(0);
        $table->boolean('is_on_sale')->nullable();
        $table->string('slug')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    session(['user' => 'user_uuid', 'company' => 'company_uuid']);

    $controller = new AddonCategoryController();
    $create     = Request::create('/addon-categories', 'POST', [
        'addonCategory' => [
            'name'   => 'Packaging',
            'addons' => [['name' => 'Gift wrap', 'price' => '$5.00']],
        ],
    ]);
    $created = $controller->createRecord($create);
    $record  = $connection->table('categories')->first();

    expect($created)->toBeInstanceOf(Illuminate\Http\Resources\Json\JsonResource::class)
        ->and($record->name)->toBe('Packaging')
        ->and($connection->table('product_addons')->value('name'))->toBe('Gift wrap');

    $connection->table('categories')->where('id', $record->id)->update(['public_id' => 'category_packaging']);

    $update = Request::create('/addon-categories/category_packaging', 'PATCH', [
        'addonCategory' => [
            'name'   => 'Premium packaging',
            'addons' => [['name' => 'Ribbon', 'price' => 250]],
        ],
    ]);
    $updated        = $controller->updateRecord($update, 'category_packaging');
    $internalCreate = Request::create('/int/v1/storefront/addon-categories', 'POST', [
        'addonCategory' => ['name' => 'Internal packaging', 'addons' => []],
    ]);
    $internalCreate->setRouteResolver(fn () => new class {
        public array $action = [];

        public function uri(): string
        {
            return 'int/v1/storefront/addon-categories';
        }
    });
    $internalCreated = $controller->createRecord($internalCreate);
    $internalUpdate  = Request::create('/int/v1/storefront/addon-categories/category_packaging', 'PATCH', [
        'addonCategory' => ['name' => 'Internal premium packaging', 'addons' => []],
    ]);
    $internalUpdate->setRouteResolver(fn () => new class {
        public array $action = [];

        public function uri(): string
        {
            return 'int/v1/storefront/addon-categories/{id}';
        }
    });
    $internalUpdated = $controller->updateRecord($internalUpdate, 'category_packaging');

    expect($updated)->toBeInstanceOf(Illuminate\Http\Resources\Json\JsonResource::class)
        ->and($connection->table('categories')->where('public_id', 'category_packaging')->value('name'))
        ->toBe('Internal premium packaging')
        ->and($connection->table('product_addons')->where('name', 'Ribbon')->value('price'))->toBe(250)
        ->and($internalCreated)->toBeInstanceOf(Illuminate\Http\Resources\Json\JsonResource::class)
        ->and($internalUpdated)->toBeInstanceOf(Illuminate\Http\Resources\Json\JsonResource::class)
        ->and($connection->table('categories')->where('public_id', 'category_packaging')->value('name'))
        ->toBe('Internal premium packaging');
});

test('nearby order command scopes its candidates to dispatched enroute storefront orders', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('status')->nullable();
        $table->string('type')->nullable();
        $table->boolean('dispatched')->default(false);
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('orders')->insert([
        ['status' => 'created', 'type' => 'storefront', 'dispatched' => false],
        ['status' => 'driver_enroute', 'type' => 'other', 'dispatched' => true],
    ]);

    $orders = (new NotifyStorefrontOrderNearby())->getActiveStorefrontOrders();

    expect($orders)->toBeEmpty();
});
