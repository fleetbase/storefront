<?php

use Fleetbase\Storefront\Http\Controllers\v1\ReviewController;
use Fleetbase\Storefront\Http\Requests\CreateReviewRequest;
use Fleetbase\Storefront\Models\Review;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

const REVIEWS_DISABLED_CUSTOMER_UUID = '3c9e2f1a-8b4d-4e6f-a1b2-c3d4e5f6a7b8';

function reviewsDisabledDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

function reviewsDisabledSchema(): void
{
    $schema = reviewsDisabledDb()->getSchemaBuilder();
    foreach (['reviews', 'stores', 'networks', 'network_stores', 'products', 'contacts', 'personal_access_tokens', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('reviews', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
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
    foreach (['stores', 'networks'] as $name) {
        $schema->create($name, function ($table) {
            $table->increments('id');
            foreach ([
                'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid', 'key',
                'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter', 'email',
                'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options',
            ] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
    }
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('network_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->string('status')->default('active');
        $table->string('category_uuid')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('store_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('status')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
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

    $db = reviewsDisabledDb();
    $db->table('stores')->insert([
        ['uuid' => 'store_closed_uuid', 'public_id' => 'store_closedrev', 'company_uuid' => 'company_uuid', 'key' => 'store_closed_key', 'name' => 'Quiet', 'options' => json_encode(['reviews_enabled' => false])],
        ['uuid' => 'store_open_uuid', 'public_id' => 'store_openrevs', 'company_uuid' => 'company_uuid', 'key' => 'store_open_key', 'name' => 'Chatty', 'options' => json_encode([])],
    ]);
    $db->table('networks')->insert([
        ['uuid' => 'network_default_uuid', 'public_id' => 'network_default', 'company_uuid' => 'company_uuid', 'key' => 'network_default_key', 'name' => 'Market', 'options' => json_encode([])],
        ['uuid' => 'network_on_uuid', 'public_id' => 'network_on', 'company_uuid' => 'company_uuid', 'key' => 'network_on_key', 'name' => 'Mall', 'options' => json_encode(['reviews_enabled' => true])],
    ]);
    $db->table('network_stores')->insert([
        ['network_uuid' => 'network_default_uuid', 'store_uuid' => 'store_open_uuid'],
        ['network_uuid' => 'network_on_uuid', 'store_uuid' => 'store_open_uuid'],
    ]);
    $db->table('reviews')->insert([
        ['uuid' => 'review_closed_1', 'public_id' => 'review_closed_1', 'subject_uuid' => 'store_closed_uuid', 'rating' => 5, 'content' => 'Lovely'],
        ['uuid' => 'review_closed_2', 'public_id' => 'review_closed_2', 'subject_uuid' => 'store_closed_uuid', 'rating' => 2, 'content' => 'Meh'],
        ['uuid' => 'review_open_1', 'public_id' => 'review_open_1', 'subject_uuid' => 'store_open_uuid', 'rating' => 4, 'content' => 'Good'],
    ]);
    $db->table('contacts')->insert(['uuid' => REVIEWS_DISABLED_CUSTOMER_UUID, 'public_id' => 'contact_reviewer', 'user_uuid' => 'user_uuid', 'type' => 'customer']);
    $db->table('personal_access_tokens')->insert([
        'name'       => REVIEWS_DISABLED_CUSTOMER_UUID,
        'token'      => hash('sha256', 'reviews-disabled-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Point the storefront session at a store or network app, and bind a request for it.
 */
function reviewsDisabledContext(string $key, ?string $store, ?string $network, array $input = [], bool $signedIn = false): Request
{
    session(['company' => 'company_uuid', 'storefront_key' => $key, 'storefront_store' => $store, 'storefront_network' => $network]);
    $request = Request::create('/storefront/v1/reviews', 'GET', $input);
    if ($signedIn) {
        $request->headers->set('Customer-Token', 'reviews-disabled-secret');
    }
    $request->setLaravelSession(new Illuminate\Session\Store('reviews-disabled', new Illuminate\Session\ArraySessionHandler(120)));
    app()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    reviewsDisabledSchema();
});

afterEach(function () {
    session(['company' => null, 'storefront_key' => null, 'storefront_store' => null, 'storefront_network' => null]);
});

test('a store that switched reviews off lists none, counts none and reports why it cannot be reviewed', function () {
    $controller = new ReviewController();

    $listing     = $controller->query(reviewsDisabledContext('store_closed_key', 'store_closed_uuid', null));
    $counts      = $controller->count(reviewsDisabledContext('store_closed_key', 'store_closed_uuid', null));
    $eligibility = $controller->eligibility(reviewsDisabledContext('store_closed_key', 'store_closed_uuid', null, ['subject' => 'store_closedrev'], true));

    expect($listing->resource)->toBeEmpty()
        ->and($counts->getData(true))->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0])
        ->and($eligibility->getData(true))->toBe([
            'can_review' => false,
            'reason'     => 'reviews_disabled',
            'message'    => 'Reviews are turned off.',
            'order'      => null,
            'review'     => null,
        ]);
});

test('a store that switched reviews off refuses new reviews from signed in customers', function () {
    reviewsDisabledContext('store_closed_key', 'store_closed_uuid', null, [], true);

    $response = (new ReviewController())->create(CreateReviewRequest::create('/storefront/v1/reviews', 'POST', [
        'subject' => 'store_closedrev',
        'rating'  => 5,
        'content' => 'Trying anyway',
    ]));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe(['error' => 'Reviews are turned off.', 'reason' => 'reviews_disabled'])
        ->and(Review::query()->count())->toBe(3);
});

test('a store keeps reviews unless it switches them off', function () {
    $counts = (new ReviewController())->count(reviewsDisabledContext('store_open_key', 'store_open_uuid', null));

    expect($counts->getData(true))->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0]);
});

test('a network only shows reviews once it switches them on', function () {
    $controller = new ReviewController();

    $defaultListing = $controller->query(reviewsDisabledContext('network_default_key', null, 'network_default_uuid'));
    $defaultCounts  = $controller->count(reviewsDisabledContext('network_default_key', null, 'network_default_uuid', ['store' => 'store_openrevs']));
    $enabledCounts  = $controller->count(reviewsDisabledContext('network_on_key', null, 'network_on_uuid', ['store' => 'store_openrevs']));

    expect($defaultListing->resource)->toBeEmpty()
        ->and($defaultCounts->getData(true))->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0])
        // The member store's review shows in the network that switched reviews on.
        ->and($enabledCounts->getData(true))->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0]);
});
