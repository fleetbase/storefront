<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Storefront\Http\Controllers\v1\ReviewController;
use Fleetbase\Storefront\Http\Requests\CreateReviewRequest;
use Fleetbase\Storefront\Http\Resources\Review as ReviewResource;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Review;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Support\ReviewEligibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

const ELIGIBILITY_CUSTOMER_UUID = '22222222-2222-4222-8222-222222222222';

function createReviewEligibilitySchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    foreach (['reviews', 'orders', 'entities', 'products', 'stores', 'contacts', 'personal_access_tokens'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('reviews', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('order_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->integer('rating')->nullable();
        $table->text('content')->nullable();
        $table->boolean('rejected')->default(false);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('internal_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('options')->nullable();
        $table->text('translations')->nullable();
        $table->text('tags')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('personal_access_tokens', function ($table) {
        $table->increments('id');
        $table->string('tokenable_type')->nullable();
        $table->integer('tokenable_id')->nullable();
        $table->string('name');
        $table->string('token');
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_abcdefgh', 'company_uuid' => 'company_uuid', 'name' => 'Bloom']);
    $connection->table('products')->insert(['uuid' => 'product_uuid', 'public_id' => 'product_abcdefgh', 'store_uuid' => 'store_uuid']);
    $connection->table('contacts')->insert(['uuid' => ELIGIBILITY_CUSTOMER_UUID, 'public_id' => 'contact_eligible', 'user_uuid' => 'user_uuid', 'type' => 'customer']);
    $connection->table('orders')->insert([
        // Completed orders for the store, newest last so ordering is exercised.
        ['uuid' => 'store_order_old', 'public_id' => 'order_old', 'customer_uuid' => ELIGIBILITY_CUSTOMER_UUID, 'status' => 'completed', 'payload_uuid' => 'payload_old', 'meta' => json_encode(['storefront_id' => 'store_abcdefgh']), 'created_at' => now()->subDays(5)],
        ['uuid' => 'store_order_new', 'public_id' => 'order_new', 'customer_uuid' => ELIGIBILITY_CUSTOMER_UUID, 'status' => 'completed', 'payload_uuid' => 'payload_new', 'meta' => json_encode(['storefront_id' => 'store_abcdefgh']), 'created_at' => now()->subDay()],
        // Not eligible: still in progress, another store, another customer.
        ['uuid' => 'store_order_active', 'public_id' => 'order_active', 'customer_uuid' => ELIGIBILITY_CUSTOMER_UUID, 'status' => 'dispatched', 'payload_uuid' => 'payload_active', 'meta' => json_encode(['storefront_id' => 'store_abcdefgh']), 'created_at' => now()],
        ['uuid' => 'other_store_order', 'public_id' => 'order_other_store', 'customer_uuid' => ELIGIBILITY_CUSTOMER_UUID, 'status' => 'completed', 'payload_uuid' => null, 'meta' => json_encode(['storefront_id' => 'store_other']), 'created_at' => now()],
        ['uuid' => 'other_customer_order', 'public_id' => 'order_other_customer', 'customer_uuid' => 'someone_else', 'status' => 'completed', 'payload_uuid' => null, 'meta' => json_encode(['storefront_id' => 'store_abcdefgh']), 'created_at' => now()],
    ]);
    // Only the newest store order contains the product.
    $connection->table('entities')->insert([
        ['uuid' => 'entity_new', 'payload_uuid' => 'payload_new', 'internal_id' => 'product_abcdefgh'],
        ['uuid' => 'entity_active', 'payload_uuid' => 'payload_active', 'internal_id' => 'product_abcdefgh'],
        ['uuid' => 'entity_orphan', 'payload_uuid' => null, 'internal_id' => 'product_abcdefgh'],
    ]);
}

function eligibilityCustomer(): Contact
{
    return Contact::where('uuid', ELIGIBILITY_CUSTOMER_UUID)->first();
}

function bindEligibilityRequest(?string $customerToken = null, array $query = []): Request
{
    $request = Request::create('/reviews/eligibility', 'GET', $query);
    if ($customerToken) {
        $request->headers->set('Customer-Token', $customerToken);
    }
    $request->setLaravelSession(new Illuminate\Session\Store('review-eligibility', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);
    session(['company' => 'company_uuid', 'storefront_key' => 'store_key', 'storefront_store' => 'store_uuid', 'storefront_network' => null]);

    return $request;
}

function issueEligibilityToken(): string
{
    Model::getConnectionResolver()->connection('mysql')->table('personal_access_tokens')->insert([
        'name'       => ELIGIBILITY_CUSTOMER_UUID,
        'token'      => hash('sha256', 'eligibility-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return 'eligibility-secret';
}

test('review eligibility requires a signed-in customer and a store or product subject', function () {
    createReviewEligibilitySchema();
    $store = Store::where('uuid', 'store_uuid')->first();

    $signedOut   = ReviewEligibility::check(null, $store);
    $unsupported = ReviewEligibility::check(eligibilityCustomer(), new stdClass());

    expect($signedOut->allowed)->toBeFalse()
        ->and($signedOut->toArray())->toBe([
            'can_review' => false,
            'reason'     => 'sign_in_required',
            'message'    => 'Sign in to write a review.',
            'order'      => null,
            'review'     => null,
        ])
        ->and($unsupported->reason)->toBe('unsupported_subject')
        ->and($unsupported->message())->toBe('Only stores and products can be reviewed.')
        ->and(ReviewEligibility::completedOrdersFor(eligibilityCustomer(), new stdClass()))->toBeNull();
});

test('store reviews use the newest completed order from that store that has not been reviewed', function () {
    createReviewEligibilitySchema();
    $store    = Store::where('uuid', 'store_uuid')->first();
    $customer = eligibilityCustomer();

    $first = ReviewEligibility::check($customer, $store);
    Review::create(['customer_uuid' => $customer->uuid, 'subject_uuid' => $store->uuid, 'order_uuid' => 'store_order_new', 'rating' => 5, 'content' => 'Great']);
    $second = ReviewEligibility::check($customer, $store);
    Review::create(['customer_uuid' => $customer->uuid, 'subject_uuid' => $store->uuid, 'order_uuid' => 'store_order_old', 'rating' => 4, 'content' => 'Good']);
    $done = ReviewEligibility::check($customer, $store);

    expect($first->allowed)->toBeTrue()
        ->and($first->reason)->toBeNull()
        ->and($first->message())->toBeNull()
        ->and($first->order->uuid)->toBe('store_order_new')
        ->and($second->order->uuid)->toBe('store_order_old')
        ->and($done->allowed)->toBeFalse()
        ->and($done->reason)->toBe('already_reviewed')
        ->and($done->order->public_id)->toBe('order_new')
        ->and($done->review->order_uuid)->toBe('store_order_new')
        ->and($done->toArray()['order'])->toBe('order_new');
});

test('product reviews need a completed order containing the product and can target one order', function () {
    createReviewEligibilitySchema();
    $product  = Product::where('uuid', 'product_uuid')->first();
    $store    = Store::where('uuid', 'store_uuid')->first();
    $customer = eligibilityCustomer();

    $product_ = ReviewEligibility::check($customer, $product);
    $byPublic = ReviewEligibility::check($customer, $store, 'order_old');
    $byUuid   = ReviewEligibility::check($customer, $store, 'store_order_old');
    $notMine  = ReviewEligibility::check($customer, $store, 'order_other_customer');
    $missing  = ReviewEligibility::check($customer, $product, 'order_old');

    expect($product_->allowed)->toBeTrue()
        ->and($product_->order->uuid)->toBe('store_order_new')
        ->and($byPublic->order->uuid)->toBe('store_order_old')
        ->and($byUuid->order->uuid)->toBe('store_order_old')
        ->and($notMine->reason)->toBe('no_completed_order')
        ->and($missing->allowed)->toBeFalse()
        ->and($missing->message())->toBe('You can review this after an order with it is completed.');
});

test('review eligibility endpoint validates the subject and reports the signed-in customer state', function () {
    createReviewEligibilitySchema();
    $controller = new ReviewController();

    bindEligibilityRequest();
    $missing = $controller->eligibility(request());
    bindEligibilityRequest(null, ['subject' => 'store_missing']);
    $invalid = $controller->eligibility(request());
    bindEligibilityRequest(null, ['subject' => 'store_abcdefgh']);
    $signedOut = $controller->eligibility(request());
    $token     = issueEligibilityToken();
    bindEligibilityRequest($token, ['subject' => 'store_abcdefgh']);
    $eligible = $controller->eligibility(request());
    bindEligibilityRequest($token, ['subject' => 'product_abcdefgh', 'order' => 'order_old']);
    $wrongOrder = $controller->eligibility(request());

    expect($missing->getData(true))->toBe(['error' => 'A subject is required.'])
        ->and($invalid->getData(true))->toBe(['error' => 'Invalid subject for review'])
        ->and($signedOut->getData(true)['reason'])->toBe('sign_in_required')
        ->and($eligible->getData(true))->toBe([
            'can_review' => true,
            'reason'     => null,
            'message'    => null,
            'order'      => 'order_new',
            'review'     => null,
        ])
        ->and($wrongOrder->getData(true)['reason'])->toBe('no_completed_order');
});

test('creating a review without a completed order is refused with the reason', function () {
    createReviewEligibilitySchema();
    Model::getConnectionResolver()->connection('mysql')->table('orders')->update(['status' => 'canceled']);
    bindEligibilityRequest(issueEligibilityToken());

    $response = (new ReviewController())->create(CreateReviewRequest::create('/reviews', 'POST', [
        'subject' => 'store_abcdefgh',
        'rating'  => 5,
        'content' => 'Never received it',
    ]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'error'  => 'You can review this after an order with it is completed.',
            'reason' => 'no_completed_order',
        ])
        ->and(Review::query()->count())->toBe(0);
});

test('review resources mark the viewer own verified product reviews and keep internal ids internal', function () {
    createReviewEligibilitySchema();
    $product = Product::where('uuid', 'product_uuid')->first();
    $mine    = new Review(['customer_uuid' => ELIGIBILITY_CUSTOMER_UUID, 'subject_uuid' => 'product_uuid', 'rating' => 4, 'content' => 'Nice']);
    $mine->setRelation('subject', $product)->setRelation('customer', null)->setRelation('photos', collect());
    $other = new Review(['customer_uuid' => null, 'order_uuid' => null, 'rating' => 2, 'content' => 'Meh']);
    $other->setRelation('subject', null)->setRelation('customer', null)->setRelation('photos', collect());

    bindEligibilityRequest(issueEligibilityToken());
    $publicMine  = (new ReviewResource($mine))->toArray(request());
    $publicOther = (new ReviewResource($other))->toArray(request());
    // The viewer is resolved once per request.
    Model::getConnectionResolver()->connection('mysql')->table('personal_access_tokens')->delete();
    $cached = ReviewResource::viewerCustomerUuid();

    expect($publicMine['subject_id'])->toBe('product_abcdefgh')
        ->and($publicMine['subject_type'])->toBe('product')
        ->and($publicMine['verified'])->toBeFalse()
        ->and($publicMine['is_mine'])->toBeTrue()
        ->and($publicOther['subject_id'])->toBeNull()
        ->and($publicOther['is_mine'])->toBeFalse()
        ->and($cached)->toBe(ELIGIBILITY_CUSTOMER_UUID);
});

test('reviews belong to the completed order they were written for', function () {
    $relation = (new Review())->order();

    expect($relation)->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($relation->getRelated())->toBeInstanceOf(Fleetbase\FleetOps\Models\Order::class)
        ->and($relation->getForeignKeyName())->toBe('order_uuid')
        ->and($relation->getOwnerKeyName())->toBe('uuid');
});
