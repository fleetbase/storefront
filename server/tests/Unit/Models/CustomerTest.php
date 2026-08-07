<?php

use Fleetbase\Storefront\Models\Customer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;

test('customers enforce their type scope and expose storefront review relationships', function () {
    $schema = Capsule::schema('mysql');
    foreach (['contacts', 'reviews', 'files', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('contacts', function (Blueprint $table) {
        $table->increments('id');
        $table->string('_key')->nullable();
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('internal_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->string('photo_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->string('slug')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('reviews', function (Blueprint $table) {
        $table->increments('id');
        $table->string('customer_uuid');
        $table->string('subject_type')->nullable();
        $table->softDeletes();
    });
    $schema->create('files', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uploader_uuid');
        $table->string('type')->nullable();
        $table->softDeletes();
    });
    $schema->create('orders', function (Blueprint $table) {
        $table->increments('id');
        $table->string('customer_uuid');
        $table->text('meta')->nullable();
        $table->softDeletes();
    });

    $connection = Capsule::connection('mysql');
    $connection->table('contacts')->insert([
        ['uuid' => 'customer_uuid', 'public_id' => 'contact_customer', 'name' => 'Ada Buyer', 'type' => 'customer'],
        ['uuid' => 'vendor_uuid', 'public_id' => 'contact_vendor', 'name' => 'Vendor', 'type' => 'vendor'],
    ]);
    $connection->table('reviews')->insert([
        ['customer_uuid' => 'customer_uuid', 'subject_type' => 'Fleetbase\Storefront\Models\Product'],
        ['customer_uuid' => 'customer_uuid', 'subject_type' => 'Fleetbase\Storefront\Models\Store'],
    ]);
    $connection->table('files')->insert([
        ['uploader_uuid' => 'customer_uuid', 'type' => 'storefront_review_upload'],
        ['uploader_uuid' => 'customer_uuid', 'type' => 'avatar'],
    ]);
    $connection->table('orders')->insert([
        ['customer_uuid' => 'customer_uuid', 'meta' => json_encode(['storefront_id' => 'store_public'])],
        ['customer_uuid' => 'customer_uuid', 'meta' => json_encode(['storefront_id' => 'other_store'])],
        ['customer_uuid' => 'other_customer', 'meta' => json_encode(['storefront_id' => 'store_public'])],
    ]);

    $customer = Customer::where('public_id', 'contact_customer')->firstOrFail();

    expect(Customer::query()->count())->toBe(1)
        ->and($customer->reviews())->toBeInstanceOf(HasMany::class)
        ->and($customer->productReviews())->toBeInstanceOf(HasMany::class)
        ->and($customer->storeReviews())->toBeInstanceOf(HasMany::class)
        ->and($customer->reviewUploads())->toBeInstanceOf(HasMany::class)
        ->and($customer->reviews_count)->toBe(2)
        ->and($customer->productReviews()->count())->toBe(1)
        ->and($customer->storeReviews()->count())->toBe(1)
        ->and($customer->reviewUploads()->count())->toBe(1)
        ->and($customer->countStorefrontOrdersFrom('store_public'))->toBe(1)
        ->and(Customer::findFromCustomerId('customer_customer')?->uuid)->toBe('customer_uuid')
        ->and(Customer::findFromCustomerId('contact_customer')?->uuid)->toBe('customer_uuid')
        ->and(Customer::findFromCustomerId('customer_missing'))->toBeNull();
});

test('new customers are classified as customers before persistence', function () {
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Customer::setEventDispatcher(new Dispatcher(app()));
    Customer::clearBootedModels();

    $customer = new Customer();
    $customer->forceFill([
        'uuid'      => 'created_customer_uuid',
        'public_id' => 'contact_created',
        'name'      => 'Created Customer',
    ]);

    $fireCreating = new ReflectionMethod($customer, 'fireModelEvent');
    $fireCreating->invoke($customer, 'creating', false);

    expect($customer->type)->toBe('customer');

    Customer::unsetEventDispatcher();
    Customer::clearBootedModels();
});
