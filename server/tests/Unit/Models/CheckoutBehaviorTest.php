<?php

use Fleetbase\Storefront\Models\Checkout;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

test('checkout creation assigns an opaque checkout token', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('checkouts');
    $schema->create('checkouts', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('cart_uuid')->nullable();
        $table->string('token')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Illuminate\Database\Eloquent\Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Illuminate\Database\Eloquent\Model::clearBootedModels();

    try {
        $checkout = new Checkout();
        $checkout->save();
    } finally {
        Illuminate\Database\Eloquent\Model::unsetEventDispatcher();
    }

    expect($checkout->token)->toStartWith('checkout_')
        ->and(strlen($checkout->token))->toBe(41);
});

test('checkout exposes its accounting and storefront relationship contracts', function () {
    $checkout = new Checkout();

    expect($checkout->company())->toBeInstanceOf(BelongsTo::class)
        ->and((new Checkout())->order())->toBeInstanceOf(BelongsTo::class)
        ->and((new Checkout())->owner())->toBeInstanceOf(MorphTo::class)
        ->and((new Checkout())->serviceQuote())->toBeInstanceOf(BelongsTo::class)
        ->and((new Checkout())->store())->toBeInstanceOf(BelongsTo::class)
        ->and((new Checkout())->network())->toBeInstanceOf(BelongsTo::class)
        ->and((new Checkout())->gateway())->toBeInstanceOf(BelongsTo::class)
        ->and((new Checkout())->cart())->toBeInstanceOf(BelongsTo::class);
});

test('checkout finalization links the originating cart to the checkout exactly once', function () {
    $schema = Illuminate\Database\Capsule\Manager::schema('mysql');
    $schema->dropIfExists('carts');
    $schema->create('carts', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('checkout_uuid')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->dropIfExists('checkouts');
    $schema->create('checkouts', function (Illuminate\Database\Schema\Blueprint $table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('cart_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Illuminate\Database\Capsule\Manager::connection('mysql')->table('carts')->insert([
        'uuid' => 'cart_uuid',
    ]);
    Illuminate\Database\Capsule\Manager::connection('mysql')->table('checkouts')->insert([
        'uuid'      => 'checkout_uuid',
        'cart_uuid' => 'cart_uuid',
    ]);

    $checkout = Checkout::query()->findOrFail('checkout_uuid');

    $checkout->checkedout();
    $unloaded = new Checkout();
    $unloaded->forceFill([
        'uuid'      => 'checkout_uuid',
        'cart_uuid' => 'cart_uuid',
    ]);
    $unloaded->checkedout();
    (new Checkout())->checkedout();

    expect(
        Illuminate\Database\Capsule\Manager::connection('mysql')
            ->table('carts')
            ->where('uuid', 'cart_uuid')
            ->value('checkout_uuid')
    )->toBe('checkout_uuid');
});
