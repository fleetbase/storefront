<?php

use Fleetbase\Storefront\Http\Controllers\OrderController as InternalOrderController;
use Fleetbase\Storefront\Http\Controllers\v1\OrderController;
use Fleetbase\Storefront\Http\Controllers\v1\ReviewController;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Support\QPay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ReviewContextControllerStub extends ReviewController
{
    public function subjectBelongs($subject): bool
    {
        return $this->subjectBelongsToContext($subject);
    }
}

class ReceiptQPayStub extends QPay
{
    public object $response;
    public array $posted = [];
    public object $payment;
    public bool $sandboxed     = false;
    public bool $authenticated = false;

    public function post(string $path, array $params = [], array $options = [])
    {
        $this->posted = compact('path', 'params', 'options');

        return $this->response;
    }

    public function getPayment(string $invoiceId)
    {
        $this->posted['invoice_id'] = $invoiceId;

        return $this->payment;
    }

    public function useSandbox()
    {
        $this->sandboxed = true;

        return $this;
    }

    public function setAuthToken(?string $accessToken = null): QPay
    {
        $this->authenticated = true;

        return $this;
    }
}

class CustomerOrderControllerStub extends OrderController
{
    public ReceiptQPayStub $qpay;
    public bool $failReceipt = false;

    protected function createQpay(?string $username, ?string $password, ?string $callbackUrl): QPay
    {
        return $this->qpay;
    }

    protected function createEbarimtReceipt(QPay $qpay, $payment, string $receiverType, ?string $receiver = null)
    {
        if ($this->failReceipt) {
            return response()->apiError('Receipt provider failed.');
        }

        return parent::createEbarimtReceipt($qpay, $payment, $receiverType, $receiver);
    }
}

class CustomerPickupControllerStub extends OrderController
{
    protected function patchOrderConfig(Fleetbase\FleetOps\Models\Order $order)
    {
        return null;
    }

    protected function updateOrderStatus(Fleetbase\FleetOps\Models\Order $order, string $status)
    {
        $order->status = $status;

        return $order;
    }
}

class CustomerOrderControllerProbe extends OrderController
{
    public function patch(Fleetbase\FleetOps\Models\Order $order)
    {
        return $this->patchOrderConfig($order);
    }

    public function updateStatus(Fleetbase\FleetOps\Models\Order $order, string $status)
    {
        return $this->updateOrderStatus($order, $status);
    }

    public function qpay(?string $username, ?string $password, ?string $callbackUrl): QPay
    {
        return $this->createQpay($username, $password, $callbackUrl);
    }
}

class OrderActionStub extends Fleetbase\FleetOps\Models\Order
{
    public array $calls     = [];
    public bool $pickup     = false;
    public bool $failStatus = false;

    public function isMeta($key): bool
    {
        return $key === 'is_pickup' && $this->pickup;
    }

    public function firstDispatchWithActivity(): Fleetbase\FleetOps\Models\Order
    {
        $this->calls[] = 'first_dispatch';

        return $this;
    }

    public function setStatus(?string $status, $andSave = true)
    {
        if ($this->failStatus) {
            throw new RuntimeException('status failure');
        }

        $this->status  = $status;
        $this->calls[] = 'set:' . $status;

        return $this;
    }

    public function insertActivity(Fleetbase\FleetOps\Flow\Activity $activity, $location = [], $proof = null): string
    {
        $this->calls[] = 'activity:' . $activity->code;

        return 'tracking_status_uuid';
    }

    public function getLastLocation()
    {
        return ['lat' => 47.9, 'lng' => 106.9];
    }

    public function updateStatus($code = null)
    {
        $this->status  = $code;
        $this->calls[] = 'update_status:' . $code;

        return $this;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->forceFill($attributes);
        $this->calls[] = 'update';

        return true;
    }

    public function assignDriver($driver, $silent = false)
    {
        $this->calls[] = 'driver:' . $driver;

        return $this;
    }

    public function dispatchWithActivity(): Fleetbase\FleetOps\Models\Order
    {
        $this->calls[] = 'dispatch';

        return $this;
    }

    public function save(array $options = [])
    {
        $this->calls[] = 'save';

        return true;
    }
}

class InternalOrderActionControllerStub extends InternalOrderController
{
    public ?Fleetbase\FleetOps\Models\Order $order = null;
    public bool $notificationFails                 = false;
    public int $patches                            = 0;

    protected function findOrderRecord($id): Fleetbase\FleetOps\Models\Order
    {
        if (!$this->order) {
            throw new Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        return $this->order;
    }

    protected function findOrderForAction($uuid, array $relations = []): ?Fleetbase\FleetOps\Models\Order
    {
        return $this->order;
    }

    protected function patchOrderConfig(Fleetbase\FleetOps\Models\Order $order)
    {
        $this->patches++;

        return new class {
            public function getActivityByCode(string $code): Fleetbase\FleetOps\Flow\Activity
            {
                return new Fleetbase\FleetOps\Flow\Activity(['code' => $code]);
            }
        };
    }

    protected function createAcceptedActivity($orderConfig)
    {
        return new Fleetbase\FleetOps\Flow\Activity(['code' => 'preparing']);
    }

    protected function notifyOrderAccepted(Fleetbase\FleetOps\Models\Order $order): void
    {
        if ($this->notificationFails) {
            throw new RuntimeException('notification failure');
        }

        $order->calls[] = 'notified';
    }

    protected function orderResponse(Fleetbase\FleetOps\Models\Order $order): array
    {
        return ['status' => $order->status, 'order' => $order->public_id];
    }
}

class InternalOrderControllerProbe extends InternalOrderController
{
    public function patch(Fleetbase\FleetOps\Models\Order $order)
    {
        return $this->patchOrderConfig($order);
    }

    public function acceptedActivity($orderConfig = null)
    {
        return $this->createAcceptedActivity($orderConfig);
    }

    public function notifyAccepted(Fleetbase\FleetOps\Models\Order $order): void
    {
        $this->notifyOrderAccepted($order);
    }

    public function responseFor(Fleetbase\FleetOps\Models\Order $order): array
    {
        return $this->orderResponse($order);
    }
}

class NotifiableOrderCustomerStub extends Model
{
    public bool $notified = false;

    public function notify($notification): void
    {
        $this->notified = $notification instanceof Fleetbase\Storefront\Notifications\StorefrontOrderAccepted;
    }
}

class DriverAssignmentStub extends Model
{
    public bool $unassigned = false;

    public function unassignCurrentOrder(): void
    {
        $this->unassigned = true;
    }
}

function createReviewControllerSchema(): void
{
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('reviews');
    $schema->dropIfExists('products');
    $schema->create('reviews', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->integer('rating')->nullable();
        $table->text('content')->nullable();
        $table->boolean('rejected')->default(false);
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
}

test('review sort aliases map to stable API sort fields and directions', function (string $sort, ?array $expected) {
    $request = Request::create('/reviews');

    (new ReviewController())->applySort($request, $sort);

    if ($expected === null) {
        expect($request->has('sort'))->toBeFalse();
    } else {
        expect($request->only(['sort', 'sort_direction']))->toBe($expected);
    }
})->with([
    'highest' => ['highest rated', ['sort' => 'rating', 'sort_direction' => 'desc']],
    'lowest'  => ['lowest', ['sort' => 'rating', 'sort_direction' => 'asc']],
    'newest'  => ['newest first', ['sort' => 'created_at', 'sort_direction' => 'desc']],
    'oldest'  => ['oldest', ['sort' => 'created_at', 'sort_direction' => 'asc']],
    'unknown' => ['featured', null],
]);

test('review listing and rating counts are empty without storefront context', function () {
    session([
        'storefront_store'   => null,
        'storefront_network' => null,
    ]);
    $controller = new ReviewController();

    $reviews            = $controller->query(Request::create('/reviews'));
    $counts             = $controller->count(Request::create('/reviews/count'));
    $unsupportedSubject = (new ReviewContextControllerStub())->subjectBelongs(new stdClass());

    expect($reviews->resource)->toBeEmpty()
        ->and($counts->getStatusCode())->toBe(200)
        ->and($counts->getData(true))->toBe([])
        ->and($unsupportedSubject)->toBeFalse();
});

test('review rating counts are scoped to the active storefront store', function () {
    createReviewControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('reviews')->insert([
        ['subject_uuid' => 'store_uuid', 'rating' => 1],
        ['subject_uuid' => 'store_uuid', 'rating' => 5],
        ['subject_uuid' => 'store_uuid', 'rating' => 5],
        ['subject_uuid' => 'other_store', 'rating' => 5],
    ]);
    session([
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);

    $response = (new ReviewController())->count(Request::create('/reviews/count'));

    expect($response->getData(true))->toBe([
        1 => 1,
        2 => 0,
        3 => 0,
        4 => 0,
        5 => 2,
    ]);
});

test('review listing applies storefront ownership sorting limits and offsets', function () {
    createReviewControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('reviews')->insert([
        [
            'uuid'         => 'review_one_uuid',
            'public_id'    => 'review_one',
            'subject_uuid' => 'store_uuid',
            'rating'       => 1,
            'content'      => 'First',
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => '2026-01-01 00:00:00',
        ],
        [
            'uuid'         => 'review_two_uuid',
            'public_id'    => 'review_two',
            'subject_uuid' => 'store_uuid',
            'rating'       => 5,
            'content'      => 'Second',
            'created_at'   => '2026-01-02 00:00:00',
            'updated_at'   => '2026-01-02 00:00:00',
        ],
        [
            'uuid'         => 'review_other_uuid',
            'public_id'    => 'review_other',
            'subject_uuid' => 'other_store',
            'rating'       => 4,
            'content'      => 'Other store',
            'created_at'   => '2026-01-03 00:00:00',
            'updated_at'   => '2026-01-03 00:00:00',
        ],
    ]);
    session([
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $request = Request::create('/reviews?limit=1&offset=1&sort=highest', 'GET', [
        'limit'  => 1,
        'offset' => 1,
        'sort'   => 'highest',
    ]);
    $request->setLaravelSession(new Illuminate\Session\Store(
        'review-listing-test',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    app()->instance('request', $request);

    $resource = (new ReviewController())->query($request);

    expect($request->input('sort'))->toBe('rating')
        ->and($request->input('sort_direction'))->toBe('desc')
        ->and($resource->resource)->toHaveCount(1)
        ->and($resource->resource->first()->uuid)->toBe('review_two_uuid');
});

test('network review listing and counts validate membership and apply pagination', function () {
    createReviewControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['network_stores', 'networks', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('networks', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('network_stores', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('network_uuid');
        $table->string('store_uuid');
        $table->string('category_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('stores')->insert([
        ['uuid' => 'store_uuid', 'public_id' => 'store_abcdefgh', 'company_uuid' => 'invited_company_uuid'],
        ['uuid' => 'foreign_store_uuid', 'public_id' => 'store_foreign', 'company_uuid' => 'company_uuid'],
    ]);
    $connection->table('networks')->insert([
        'uuid'      => 'network_uuid',
        'public_id' => 'network_abcdefgh',
    ]);
    $connection->table('network_stores')->insert([
        'uuid'         => 'network_store_uuid',
        'network_uuid' => 'network_uuid',
        'store_uuid'   => 'store_uuid',
    ]);
    $connection->table('reviews')->insert([
        [
            'uuid'         => 'network_review_one',
            'public_id'    => 'review_network_one',
            'subject_uuid' => 'store_uuid',
            'rating'       => 1,
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => '2026-01-01 00:00:00',
        ],
        [
            'uuid'         => 'network_review_two',
            'public_id'    => 'review_network_two',
            'subject_uuid' => 'store_uuid',
            'rating'       => 5,
            'created_at'   => '2026-01-02 00:00:00',
            'updated_at'   => '2026-01-02 00:00:00',
        ],
        [
            'uuid'         => 'foreign_network_review',
            'public_id'    => 'review_network_foreign',
            'subject_uuid' => 'foreign_store_uuid',
            'rating'       => 3,
            'created_at'   => '2026-01-03 00:00:00',
            'updated_at'   => '2026-01-03 00:00:00',
        ],
        [
            'uuid'         => 'network_product_review',
            'public_id'    => 'review_network_product',
            'subject_uuid' => 'member_product_uuid',
            'rating'       => 4,
            'created_at'   => '2026-01-04 00:00:00',
            'updated_at'   => '2026-01-04 00:00:00',
        ],
        [
            'uuid'         => 'foreign_product_review',
            'public_id'    => 'review_foreign_product',
            'subject_uuid' => 'foreign_product_uuid',
            'rating'       => 2,
            'created_at'   => '2026-01-05 00:00:00',
            'updated_at'   => '2026-01-05 00:00:00',
        ],
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'member_product_uuid', 'public_id' => 'product_member', 'store_uuid' => 'store_uuid'],
        ['uuid' => 'foreign_product_uuid', 'public_id' => 'product_foreign', 'store_uuid' => 'foreign_store_uuid'],
    ]);
    session([
        'company'            => 'company_uuid',
        'storefront_store'   => null,
        'storefront_network' => 'network_uuid',
    ]);
    $controller = new ReviewController();
    $missing    = $controller->query(Request::create('/reviews?store=store_missing', 'GET', [
        'store' => 'store_missing',
    ]));
    $missingCount = $controller->count(Request::create('/reviews/count', 'GET', [
        'store' => 'store_missing',
    ]));
    $request = Request::create('/reviews?store=store_abcdefgh&limit=1&offset=1', 'GET', [
        'store'  => 'store_abcdefgh',
        'limit'  => 1,
        'offset' => 1,
    ]);
    $request->setLaravelSession(new Illuminate\Session\Store(
        'network-review-listing-test',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    app()->instance('request', $request);
    $reviews = $controller->query($request);
    $counts  = $controller->count(Request::create('/reviews/count?store=store_abcdefgh', 'GET', [
        'store' => 'store_abcdefgh',
    ]));
    $found          = $controller->find('review_network_two');
    $foreign        = $controller->find('review_network_foreign');
    $product        = $controller->find('review_network_product');
    $foreignProduct = $controller->find('review_foreign_product');
    $memberProduct  = Product::where('uuid', 'member_product_uuid')->firstOrFail();
    $outsideProduct = Product::where('uuid', 'foreign_product_uuid')->firstOrFail();
    $contextProbe   = new ReviewContextControllerStub();

    expect($missing->getStatusCode())->toBe(400)
        ->and($missing->getData(true))->toBe(['error' => 'Cannot find reviews for store'])
        ->and($missingCount->getStatusCode())->toBe(400)
        ->and($missingCount->getData(true))->toBe(['error' => 'Cannot count reviews for store'])
        ->and($reviews->resource)->toHaveCount(1)
        ->and($reviews->resource->first()->uuid)->toBe('network_review_two')
        ->and($found->resource->uuid)->toBe('network_review_two')
        ->and($foreign->getStatusCode())->toBe(400)
        ->and($product->resource->uuid)->toBe('network_product_review')
        ->and($foreignProduct->getStatusCode())->toBe(400)
        ->and($contextProbe->subjectBelongs($memberProduct))->toBeTrue()
        ->and($contextProbe->subjectBelongs($outsideProduct))->toBeFalse()
        ->and($counts->getData(true))->toBe([
            1 => 1,
            2 => 0,
            3 => 0,
            4 => 0,
            5 => 1,
        ]);
});

test('review find and delete return not-found contracts for unknown public ids', function () {
    createReviewControllerSchema();
    session(['company' => null]);
    $controller = new ReviewController();

    $find   = $controller->find('missing_review');
    $delete = $controller->delete('missing_review');

    expect($find->getStatusCode())->toBe(400)
        ->and($find->getData(true))->toBe(['error' => 'Review resource not found.'])
        ->and($delete->getStatusCode())->toBe(400)
        ->and($delete->getData(true))->toBe(['error' => 'Review resource not found.']);
});

test('review find is storefront scoped and unauthenticated customers cannot delete reviews', function () {
    createReviewControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $connection->table('products')->insert([
        ['uuid' => 'product_uuid', 'public_id' => 'product_abcdefgh', 'store_uuid' => 'store_uuid'],
        ['uuid' => 'foreign_product_uuid', 'public_id' => 'product_foreign', 'store_uuid' => 'other_store_uuid'],
    ]);
    $connection->table('reviews')->insert([
        ['uuid' => 'review_uuid', 'public_id' => 'review_abcdefgh', 'subject_uuid' => 'store_uuid', 'rating' => 5, 'content' => 'Excellent', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'foreign_review_uuid', 'public_id' => 'review_foreign', 'subject_uuid' => 'other_store_uuid', 'rating' => 1, 'content' => 'Foreign', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'product_review_uuid', 'public_id' => 'review_product', 'subject_uuid' => 'product_uuid', 'rating' => 4, 'content' => 'Great product', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'foreign_product_review_uuid', 'public_id' => 'review_foreign_product', 'subject_uuid' => 'foreign_product_uuid', 'rating' => 2, 'content' => 'Foreign product', 'created_at' => now(), 'updated_at' => now()],
    ]);
    session(['storefront_store' => 'store_uuid', 'storefront_network' => null]);
    $controller = new ReviewController();

    $found          = $controller->find('review_abcdefgh');
    $foreign        = $controller->find('review_foreign');
    $product        = $controller->find('review_product');
    $foreignProduct = $controller->find('review_foreign_product');
    $deleted        = $controller->delete('review_abcdefgh');

    expect($found->resource->uuid)->toBe('review_uuid')
        ->and($foreign->getStatusCode())->toBe(400)
        ->and($foreign->getData(true))->toBe(['error' => 'Review resource not found.'])
        ->and($product->resource->uuid)->toBe('product_review_uuid')
        ->and($foreignProduct->getStatusCode())->toBe(400)
        ->and($deleted->getStatusCode())->toBe(403)
        ->and($deleted->getData(true))->toBe(['error' => 'Not authorized to delete review'])
        ->and($connection->table('reviews')->where('uuid', 'review_uuid')->value('deleted_at'))->toBeNull();
});

test('review creation enforces customer authentication and subject validity', function () {
    createReviewControllerSchema();
    session(['storefront_key' => null]);
    $unauthenticatedRequest = Request::create('/reviews', 'POST', [
        'subject' => 'store_abcdefgh',
        'rating'  => 5,
        'content' => 'Excellent',
    ]);
    app()->instance('request', $unauthenticatedRequest);
    $controller = new ReviewController();

    $unauthorized = $controller->create(
        Fleetbase\Storefront\Http\Requests\CreateReviewRequest::create('/reviews', 'POST', [
            'subject' => 'store_abcdefgh',
            'rating'  => 5,
        ])
    );

    expect($unauthorized->getData(true))->toBe(['error' => 'Not authorized to create reviews']);
});

test('authenticated review creation persists customer and store subject contracts', function () {
    createReviewControllerSchema();
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['personal_access_tokens', 'files', 'contacts', 'stores'] as $table) {
        $schema->dropIfExists($table);
    }
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
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('stores', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('backdrop_uuid')->nullable();
        $table->string('logo_uuid')->nullable();
        $table->string('order_config_uuid')->nullable();
        $table->string('key')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('translations')->nullable();
        $table->string('website')->nullable();
        $table->string('facebook')->nullable();
        $table->string('instagram')->nullable();
        $table->string('twitter')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->text('tags')->nullable();
        $table->string('currency')->nullable();
        $table->string('timezone')->nullable();
        $table->string('pod_method')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('uploader_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->string('original_filename')->nullable();
        $table->string('extension')->nullable();
        $table->string('content_type')->nullable();
        $table->string('path')->nullable();
        $table->string('bucket')->nullable();
        $table->string('type')->nullable();
        $table->integer('file_size')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $customerUuid = '11111111-1111-4111-8111-111111111111';
    $connection->table('contacts')->insert([
        'uuid'      => $customerUuid,
        'public_id' => 'contact_abcdefgh',
        'user_uuid' => 'user_uuid',
        'type'      => 'customer',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'name'       => $customerUuid,
        'token'      => hash('sha256', 'review-customer-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $connection->table('stores')->insert([
        'uuid'         => 'store_uuid',
        'public_id'    => 'store_abcdefgh',
        'company_uuid' => 'company_uuid',
        'key'          => 'store_key',
        'name'         => 'Review store',
    ]);
    $connection->table('products')->insert([
        ['uuid' => 'product_uuid', 'public_id' => 'product_abcdefgh', 'store_uuid' => 'store_uuid'],
        ['uuid' => 'foreign_product_uuid', 'public_id' => 'product_foreign', 'store_uuid' => 'foreign_store_uuid'],
    ]);
    $boundRequest = Request::create('/reviews');
    $boundRequest->headers->set('Customer-Token', 'review-customer-secret');
    $boundRequest->setLaravelSession(new Illuminate\Session\Store(
        'review-customer-test',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    app()->instance('request', $boundRequest);
    session([
        'company'            => 'company_uuid',
        'storefront_key'     => null,
        'storefront_store'   => 'store_uuid',
        'storefront_network' => null,
    ]);
    $controller = new ReviewController();

    $invalid = $controller->create(
        Fleetbase\Storefront\Http\Requests\CreateReviewRequest::create('/reviews', 'POST', [
            'subject' => 'store_missing',
            'rating'  => 2,
        ])
    );
    $created = $controller->create(
        Fleetbase\Storefront\Http\Requests\CreateReviewRequest::create('/reviews', 'POST', [
            'subject' => 'store_abcdefgh',
            'rating'  => 5,
            'content' => 'Excellent service',
        ])
    );
    $invalidProduct = $controller->create(
        Fleetbase\Storefront\Http\Requests\CreateReviewRequest::create('/reviews', 'POST', [
            'subject' => 'product_foreign',
            'rating'  => 1,
        ])
    );
    $createdProduct = $controller->create(
        Fleetbase\Storefront\Http\Requests\CreateReviewRequest::create('/reviews', 'POST', [
            'subject' => 'product_abcdefgh',
            'rating'  => 4,
            'content' => 'Excellent product',
        ])
    );
    $review = $connection->table('reviews')->first();
    $connection->table('reviews')->where('id', $review->id)->update([
        'uuid'      => 'owned_review_uuid',
        'public_id' => 'review_owned',
    ]);
    Illuminate\Support\Facades\Storage::swap(new class {
        public function disk(string $disk): self
        {
            return $this;
        }

        public function put(string $path, string $contents, string $visibility): bool
        {
            return true;
        }
    });
    session(['storefront_key' => 'store_key']);
    $withPhoto = $controller->create(
        Fleetbase\Storefront\Http\Requests\CreateReviewRequest::create('/reviews', 'POST', [
            'subject' => 'store_abcdefgh',
            'rating'  => 4,
            'content' => 'Photo review',
            'disk'    => 'local',
            'bucket'  => 'review-bucket',
            'files'   => [
                [
                    'data' => base64_encode('image-bytes'),
                    'type' => 'image/png',
                ],
            ],
        ])
    );
    $photo = $connection->table('files')->first();
    session(['storefront_store' => 'store_uuid', 'storefront_network' => null]);
    $deleted = $controller->delete('review_owned');

    expect($invalid->getData(true))->toBe(['error' => 'Invalid subject for review'])
        ->and($invalidProduct->getData(true))->toBe(['error' => 'Invalid subject for review'])
        ->and($created->resource->uuid)->toBe($review->uuid)
        ->and($createdProduct->resource->subject_uuid)->toBe('product_uuid')
        ->and($review->created_by_uuid)->toBe('user_uuid')
        ->and($review->customer_uuid)->toBe($customerUuid)
        ->and($review->subject_uuid)->toBe('store_uuid')
        ->and($review->rating)->toBe(5)
        ->and($review->content)->toBe('Excellent service')
        ->and($withPhoto->resource->files)->toHaveCount(1)
        ->and($photo->subject_uuid)->toBe($withPhoto->resource->uuid)
        ->and($photo->content_type)->toBe('image/png')
        ->and($photo->bucket)->toBe('review-bucket')
        ->and($photo->file_size)->toBe(strlen('image-bytes'))
        ->and($photo->type)->toBe('storefront_review_upload')
        ->and($deleted->resource->uuid)->toBe('owned_review_uuid')
        ->and($connection->table('reviews')->where('uuid', 'owned_review_uuid')->value('deleted_at'))->not->toBeNull();
});

test('customer order actions require a customer token before order lookup', function () {
    $boundRequest = Request::create('/orders');
    $boundRequest->setLaravelSession(new Illuminate\Session\Store(
        'customer-order-action-test',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    app()->instance('request', $boundRequest);
    $controller = new OrderController();

    $pickup  = $controller->completeOrderPickup(Request::create('/orders/pickup', 'POST'));
    $receipt = $controller->getReceipt(Request::create('/orders/receipt'));

    expect($pickup->getStatusCode())->toBe(400)
        ->and($pickup->getData(true))->toBe(['error' => 'Customer is not authenticated.'])
        ->and($receipt->getStatusCode())->toBe(400)
        ->and($receipt->getData(true))->toBe(['error' => 'Customer is not authenticated.']);
});

test('internal order actions return explicit errors for unknown orders', function () {
    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('orders');
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $controller = new InternalOrderController();

    $find      = $controller->findRecord(Request::create('/orders/missing'), 'missing_order');
    $accept    = $controller->acceptOrder(Request::create('/orders/accept', 'POST', ['order' => 'missing_uuid']));
    $ready     = $controller->markOrderAsReady(Request::create('/orders/ready', 'POST', ['order' => 'missing_uuid']));
    $preparing = $controller->markOrderAsPreparing(Request::create('/orders/preparing', 'POST', ['order' => 'missing_uuid']));
    $completed = $controller->markOrderAsCompleted(Request::create('/orders/completed', 'POST', ['order' => 'missing_uuid']));
    $unassign  = $controller->unassignDriver(Request::create('/orders/unassign', 'POST', ['order' => 'missing_uuid']));
    $reject    = $controller->rejectOrder(Request::create('/orders/reject', 'POST', ['order' => 'missing_uuid']));

    expect($find->getStatusCode())->toBe(404)
        ->and($find->getData(true))->toBe(['error' => 'Order not found'])
        ->and($accept->getData(true))->toBe(['error' => 'No order to accept!'])
        ->and($ready->getData(true))->toBe(['error' => 'No order to update!'])
        ->and($preparing->getData(true))->toBe(['error' => 'No order to update!'])
        ->and($completed->getData(true))->toBe(['error' => 'No order to update!'])
        ->and($unassign->getData(true))->toBe(['error' => 'No order to update!'])
        ->and($reject->getData(true))->toBe(['error' => 'No order to cancel!']);
});

test('internal order controller delegates query config activity notification and response contracts', function () {
    $controller = new InternalOrderControllerProbe();
    $query      = (new Fleetbase\FleetOps\Models\Order())->newQuery();
    $controller->onQueryRecord($query);
    expect(array_keys($query->getEagerLoads()))->toBe([
        'customer',
        'transaction',
        'payload',
        'driverAssigned',
        'orderConfig',
        'trackingNumber',
        'trackingStatuses',
    ]);

    $schema = Model::getConnectionResolver()->connection('mysql')->getSchemaBuilder();
    $schema->dropIfExists('order_configs');
    $schema->create('order_configs', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('name')->nullable();
        $table->string('namespace')->nullable();
        $table->string('key')->nullable();
        $table->string('status')->nullable();
        $table->string('version')->nullable();
        $table->text('activities')->nullable();
        $table->timestamp('deleted_at')->nullable();
        $table->timestamps();
    });
    $schema->dropIfExists('stores');
    $schema->create('stores', function ($table) {
        $table->increments('id');
        foreach ([
            'uuid', 'public_id', 'company_uuid', 'backdrop_uuid', 'logo_uuid', 'order_config_uuid',
            'name', 'description', 'translations', 'website', 'facebook', 'instagram', 'twitter',
            'email', 'phone', 'tags', 'currency', 'timezone', 'pod_method', 'options',
        ] as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamp('deleted_at')->nullable();
    });
    Model::getConnectionResolver()->connection('mysql')->table('stores')->insert([
        'uuid'      => 'store_uuid',
        'public_id' => 'store_public',
        'name'      => 'Runtime Store',
    ]);
    $config = Fleetbase\FleetOps\Models\OrderConfig::forceCreate([
        'uuid'       => 'order_config_uuid',
        'name'       => 'Storefront delivery',
        'namespace'  => 'fleetbase:order-config:storefront-delivery',
        'activities' => '[]',
    ]);
    $order = new OrderActionStub();
    $order->forceFill([
        'order_config_uuid' => $config->uuid,
        'status'            => 'preparing',
        'public_id'         => 'order_public',
        'meta'              => ['storefront_id' => 'store_public'],
    ]);
    $customer = new NotifiableOrderCustomerStub();
    $order->setRelation('customer', $customer);

    $patched  = $controller->patch($order);
    $activity = $controller->acceptedActivity();
    $controller->notifyAccepted($order);
    $response = $controller->responseFor($order);

    expect($patched->uuid)->toBe($config->uuid)
        ->and($activity->code)->toBe('accepted')
        ->and($customer->notified)->toBeTrue()
        ->and($response['status'])->toBe('preparing')
        ->and($response['order'])->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Order::class);
});

test('authenticated customer order endpoints distinguish missing and unauthorized orders', function () {
    $connection = Model::getConnectionResolver()->connection('mysql');
    $schema     = $connection->getSchemaBuilder();
    foreach (['personal_access_tokens', 'contacts', 'entities', 'places', 'waypoints', 'payloads', 'checkouts', 'gateways', 'orders'] as $table) {
        $schema->dropIfExists($table);
    }
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
    $schema->create('contacts', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('public_id')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('orders', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('customer_uuid')->nullable();
        $table->string('customer_type')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->text('meta')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('payloads', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('payment_method')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('entities', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('places', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('waypoints', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('payload_uuid')->nullable();
        $table->string('place_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('checkouts', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->text('options')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('gateways', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('code')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->boolean('sandbox')->default(false);
        $table->text('config')->nullable();
        $table->string('callback_url')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $connection->table('contacts')->insert([
        'uuid'      => '11111111-1111-4111-8111-111111111111',
        'public_id' => 'contact_customer',
        'type'      => 'customer',
    ]);
    $connection->table('personal_access_tokens')->insert([
        'name'       => '11111111-1111-4111-8111-111111111111',
        'token'      => hash('sha256', 'customer-secret'),
        'abilities'  => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $boundRequest = Request::create('/orders');
    $boundRequest->headers->set('Customer-Token', 'customer-secret');
    $boundRequest->setLaravelSession(new Illuminate\Session\Store(
        'authenticated-customer-order-test',
        new Illuminate\Session\ArraySessionHandler(120)
    ));
    app()->instance('request', $boundRequest);
    $controller = new OrderController();

    $missingPickup = $controller->completeOrderPickup(Request::create('/orders/pickup', 'POST', [
        'order' => 'order_missing',
    ]));
    $missingReceipt = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_missing',
    ]));
    $connection->table('orders')->insert([
        'uuid'          => 'order_uuid',
        'public_id'     => 'order_other_customer',
        'customer_uuid' => '22222222-2222-4222-8222-222222222222',
    ]);
    $unauthorizedPickup = $controller->completeOrderPickup(Request::create('/orders/pickup', 'POST', [
        'order' => 'order_other_customer',
    ]));
    $unauthorizedReceipt = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_other_customer',
    ]));
    $connection->table('payloads')->insert([
        ['uuid' => 'payload_cash', 'payment_method' => 'cash'],
        ['uuid' => 'payload_qpay', 'payment_method' => 'qpay'],
    ]);
    $connection->table('orders')->insert([
        [
            'uuid'          => 'order_cash_uuid',
            'public_id'     => 'order_cash',
            'customer_uuid' => '11111111-1111-4111-8111-111111111111',
            'payload_uuid'  => 'payload_cash',
            'meta'          => null,
        ],
        [
            'uuid'          => 'order_qpay_uuid',
            'public_id'     => 'order_qpay',
            'customer_uuid' => '11111111-1111-4111-8111-111111111111',
            'payload_uuid'  => 'payload_qpay',
            'meta'          => json_encode(['checkout_id' => 'checkout_qpay']),
        ],
    ]);
    $cashReceipt = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_cash',
    ]));
    $qpayCompanyReceipt = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order'                 => 'order_qpay',
        'ebarimt_receiver_type' => 'company',
    ]));
    $qpayMissingCheckout = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_qpay',
    ]));
    $connection->table('orders')->insert([
        'uuid'          => 'order_qpay_without_checkout_uuid',
        'public_id'     => 'order_qpay_without_checkout',
        'customer_uuid' => '11111111-1111-4111-8111-111111111111',
        'payload_uuid'  => 'payload_qpay',
        'meta'          => null,
    ]);
    $qpayWithoutCheckoutMeta = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_qpay_without_checkout',
    ]));
    $connection->table('checkouts')->insert([
        'uuid'      => 'checkout_qpay_uuid',
        'public_id' => 'checkout_qpay',
        'options'   => json_encode(['qpay_invoice_id' => 'invoice_qpay']),
    ]);
    $qpayMissingGateway = $controller->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_qpay',
    ]));
    $connection->table('gateways')->insert([
        'uuid'         => 'gateway_uuid',
        'code'         => 'qpay',
        'owner_uuid'   => session('storefront_store') ?? session('storefront_network'),
        'sandbox'      => true,
        'callback_url' => 'https://example.test/qpay',
        'config'       => json_encode(['username' => 'merchant', 'password' => 'secret']),
    ]);
    $qpay                       = new ReceiptQPayStub('username', 'password', 'https://example.test/callback');
    $qpay->payment              = (object) ['payment_id' => 'payment_qpay'];
    $qpay->response             = (object) ['ebarimt_qr_data' => 'receipt-qr', 'lottery' => 'lottery-code'];
    $successfulController       = new CustomerOrderControllerStub();
    $successfulController->qpay = $qpay;
    $qpayReceipt                = $successfulController->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_qpay',
    ]));
    $successfulController->failReceipt = true;
    $failedQpayReceipt                 = $successfulController->getReceipt(Request::create('/orders/receipt', 'POST', [
        'order' => 'order_qpay',
    ]));
    $pickupController = new CustomerPickupControllerStub();
    $pickupResponse   = $pickupController->completeOrderPickup(Request::create('/orders/pickup', 'POST', [
        'order' => 'order_cash',
    ]));
    $probeOrder = new OrderActionStub();
    $probeOrder->forceFill(['order_config_uuid' => 'order_config_uuid']);
    $probe = new CustomerOrderControllerProbe();
    $probe->patch($probeOrder);
    $probe->updateStatus($probeOrder, 'completed');
    $qpayFactoryResult = $probe->qpay('merchant', 'secret', 'https://example.test/qpay');

    expect($missingPickup->getData(true))->toBe(['error' => 'No order found.'])
        ->and($missingReceipt->getData(true))->toBe(['error' => 'No order found.'])
        ->and($unauthorizedPickup->getData(true))->toBe([
            'error' => 'Not authorized to pickup this order for completion.',
        ])->and($unauthorizedReceipt->getData(true))->toBe([
            'error' => 'Not authorized to get receipt for this order.',
        ])->and($cashReceipt->getData(true))->toBe([
            'message'        => 'No receipt available for this payment method.',
            'payment_method' => 'cash',
        ])->and($qpayCompanyReceipt->getData(true))->toBe([
            'error' => 'Company registration number is required.',
        ])->and($qpayMissingCheckout->getData(true))->toBe([
            'error' => 'No checkout found for this order.',
        ])->and($qpayWithoutCheckoutMeta->getData(true))->toBe([
            'error' => 'No checkout found for this order.',
        ])->and($qpayMissingGateway->getData(true))->toBe([
            'error' => 'QPay is not configured.',
        ])->and($qpayReceipt->getData(true))->toBe([
            'ebarimt_qr_data' => 'receipt-qr',
            'lottery'         => 'lottery-code',
        ])->and($qpay->posted['path'])->toBe('ebarimt_v3/create')
        ->and($qpay->sandboxed)->toBeTrue()
        ->and($qpay->authenticated)->toBeTrue()
        ->and($failedQpayReceipt->getData(true))->toBe(['error' => 'Receipt provider failed.'])
        ->and($pickupResponse->getData(true))->toBe(['status' => 'completed', 'order' => 'order_cash'])
        ->and($probeOrder->calls)->toContain('update_status:completed')
        ->and($qpayFactoryResult)->toBeInstanceOf(QPay::class);
});

test('QPay receipt creation sends citizen and company contracts and surfaces provider errors', function () {
    $controller = new OrderController();
    $method     = new ReflectionMethod($controller, 'createEbarimtReceipt');
    $qpay       = new ReceiptQPayStub('username', 'password', 'https://example.test/callback');

    $qpay->response = (object) ['ebarimt_qr_data' => 'qr-data'];
    $citizen        = $method->invoke(
        $controller,
        $qpay,
        (object) ['payment_id' => 'payment_citizen'],
        'CITIZEN',
        null
    );
    $citizenRequest = $qpay->posted;

    $qpay->response = (object) ['ebarimt_qr_data' => 'company-qr'];
    $company        = $method->invoke(
        $controller,
        $qpay,
        ['payment_id' => 'payment_company'],
        'COMPANY',
        '1234567'
    );
    $companyRequest = $qpay->posted;

    $qpay->response = (object) ['error' => 'Provider rejected receipt'];
    $error          = $method->invoke(
        $controller,
        $qpay,
        (object) ['payment_id' => 'payment_error'],
        'CITIZEN',
        null
    );

    expect($citizen->ebarimt_qr_data)->toBe('qr-data')
        ->and($citizenRequest['path'])->toBe('ebarimt_v3/create')
        ->and($citizenRequest['params'])->toBe([
            'payment_id'            => 'payment_citizen',
            'ebarimt_receiver_type' => 'CITIZEN',
        ])->and($company->ebarimt_qr_data)->toBe('company-qr')
        ->and($companyRequest['params'])->toBe([
            'payment_id'            => 'payment_company',
            'ebarimt_receiver_type' => 'COMPANY',
            'ebarimt_receiver'      => '1234567',
        ])->and($error->getData(true))->toBe([
            'error' => 'Provider rejected receipt',
        ]);
});

test('QPay receipt helper rejects an order whose loaded payload is not QPay', function () {
    $controller = new OrderController();
    $method     = new ReflectionMethod($controller, 'getQpayEbarimtReceipt');
    $payload    = new Fleetbase\FleetOps\Models\Payload();
    $payload->forceFill(['payment_method' => 'cash']);
    $order = new Fleetbase\FleetOps\Models\Order();
    $order->setRelation('payload', $payload);

    $response = $method->invoke(
        $controller,
        Request::create('/orders/receipt', 'POST'),
        $order
    );

    expect($response->getData(true))->toBe([
        'error' => 'This order was not paid using QPay.',
    ]);
});

test('internal order acceptance handles pickup activity notification and status failures', function () {
    $controller        = new InternalOrderActionControllerStub();
    $order             = new OrderActionStub();
    $order->pickup     = true;
    $order->public_id  = 'order_public';
    $controller->order = $order;

    $found    = $controller->findRecord(Request::create('/orders/order_public'), 'order_public');
    $accepted = $controller->acceptOrder(Request::create('/orders/accept', 'POST', [
        'order' => 'order_uuid',
    ]));

    expect($found['order']->resource)->toBe($order)
        ->and($accepted)->toBe(['status' => 'preparing', 'order' => 'order_public'])
        ->and($order->calls)->toContain('first_dispatch', 'set:preparing', 'activity:preparing', 'notified')
        ->and($controller->patches)->toBe(1);

    $controller->notificationFails  = true;
    $order->calls                   = [];
    $acceptedWithoutNotification    = $controller->acceptOrder(Request::create('/orders/accept', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($acceptedWithoutNotification['status'])->toBe('preparing')
        ->and($order->calls)->not->toContain('notified');

    $order->failStatus = true;
    $failed            = $controller->acceptOrder(Request::create('/orders/accept', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($failed->getData(true))->toBe(['error' => 'Unable to accept order.']);
});

test('internal ready action handles pickup and dispatched delivery transitions', function () {
    $controller        = new InternalOrderActionControllerStub();
    $pickup            = new OrderActionStub();
    $pickup->pickup    = true;
    $pickup->public_id = 'pickup_order';
    $controller->order = $pickup;

    $pickupResponse = $controller->markOrderAsReady(Request::create('/orders/ready', 'POST', [
        'order' => 'pickup_uuid',
    ]));

    $delivery = new OrderActionStub();
    $delivery->forceFill([
        'public_id' => 'delivery_order',
        'adhoc'     => false,
    ]);
    $controller->order = $delivery;
    $deliveryResponse  = $controller->markOrderAsReady(Request::create('/orders/ready', 'POST', [
        'order'  => 'delivery_uuid',
        'adhoc'  => true,
        'driver' => 'driver_uuid',
    ]));

    expect($pickupResponse)->toBe(['status' => 'pickup_ready', 'order' => 'pickup_order'])
        ->and($pickup->calls)->toContain('update_status:pickup_ready')
        ->and($deliveryResponse)->toBe(['status' => 'preparing', 'order' => 'delivery_order'])
        ->and($delivery->adhoc)->toBeTrue()
        ->and($delivery->calls)->toContain('update', 'driver:driver_uuid', 'dispatch', 'update_status:preparing');
});

test('internal preparing completed rejected and driver-unassignment actions preserve transitions', function () {
    $controller        = new InternalOrderActionControllerStub();
    $order             = new OrderActionStub();
    $order->public_id  = 'order_public';
    $controller->order = $order;

    $preparing = $controller->markOrderAsPreparing(Request::create('/orders/preparing', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($preparing)->toBe(['status' => 'preparing', 'order' => 'order_public'])
        ->and($order->calls)->toContain('set:preparing', 'activity:preparing');

    $order->failStatus = true;
    $failedPreparing   = $controller->markOrderAsPreparing(Request::create('/orders/preparing', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($failedPreparing->getData(true))->toBe(['error' => 'Unable to trigger order preparing.']);

    $order->failStatus = false;
    $order->pickup     = true;
    $completedPickup   = $controller->markOrderAsCompleted(Request::create('/orders/completed', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($completedPickup['status'])->toBe('picked_up');

    $order->pickup     = false;
    $completedDelivery = $controller->markOrderAsCompleted(Request::create('/orders/completed', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($completedDelivery['status'])->toBe('completed');

    $rejected = $controller->rejectOrder(Request::create('/orders/reject', 'POST', [
        'order' => 'order_uuid',
    ]));
    expect($rejected['status'])->toBe('canceled');

    $driver = new DriverAssignmentStub();
    $order->setRelation('driverAssigned', $driver);
    $order->forceFill([
        'driver_assigned_uuid'  => 'driver_uuid',
        'vehicle_assigned_uuid' => 'vehicle_uuid',
    ]);
    $unassigned = $controller->unassignDriver(Request::create('/orders/unassign', 'POST', [
        'order' => 'order_uuid',
    ]));

    expect($driver->unassigned)->toBeTrue()
        ->and($order->driver_assigned_uuid)->toBeNull()
        ->and($order->vehicle_assigned_uuid)->toBeNull()
        ->and($unassigned['status'])->toBe('canceled');

    $order->setRelation('driverAssigned', null);
    expect($controller->unassignDriver(Request::create('/orders/unassign', 'POST', [
        'order' => 'order_uuid',
    ]))['status'])->toBe('canceled');
});
