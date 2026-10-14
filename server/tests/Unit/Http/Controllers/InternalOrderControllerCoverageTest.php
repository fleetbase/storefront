<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Controllers\OrderController as InternalOrderController;
use Fleetbase\Storefront\Support\OrderEditException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/*
 * Laravel's report() helper hands an exception to the bound exception handler. The test app
 * has no foundation helpers, so the console order controller gets the same helper here.
 */
if (!function_exists('Fleetbase\Storefront\Http\Controllers\report')) {
    eval('namespace Fleetbase\Storefront\Http\Controllers; function report($exception) { app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($exception); }');
}

/** An order the action endpoints act on in memory, recording what they did to it. */
class InternalOrderCoverageOrder extends Order
{
    public array $calls = [];

    public function setStatus(?string $status, $andSave = true)
    {
        $this->status  = $status;
        $this->calls[] = 'set:' . $status;

        return $this;
    }

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->calls[] = 'activity:' . $activity->code;

        return 'tracking_status_uuid';
    }

    public function getLastLocation()
    {
        return null;
    }

    public function updateStatus($code = null)
    {
        $this->status  = $code;
        $this->calls[] = 'update_status:' . $code;

        return $this;
    }

    public function dispatchWithActivity(): Order
    {
        $this->calls[] = 'dispatch';

        return $this;
    }

    public function firstDispatchWithActivity(): Order
    {
        $this->calls[] = 'first_dispatch';

        return $this;
    }
}

/** The internal order controller with the in-memory order and a plain order response. */
class InternalOrderCoverageController extends InternalOrderController
{
    public ?Order $order = null;
    public int $patches  = 0;

    protected function findOrderForAction($uuid, array $relations = []): ?Order
    {
        return $this->order;
    }

    protected function patchOrderConfig(Order $order)
    {
        $this->patches++;

        return null;
    }

    protected function createAcceptedActivity($orderConfig)
    {
        return new Activity(['code' => 'accepted']);
    }

    protected function orderResponse(Order $order): array
    {
        return ['status' => $order->status, 'order' => $order->public_id];
    }
}

class InternalOrderCoverageCustomer extends Model
{
    public array $notifications = [];

    public function notify($notification): void
    {
        $this->notifications[] = $notification;
    }
}

function internalOrderCoverageOrder(array $meta = [], string $status = 'created'): InternalOrderCoverageOrder
{
    $order = new InternalOrderCoverageOrder();
    $order->forceFill([
        'uuid'          => 'order_uuid',
        'public_id'     => 'order_public',
        'company_uuid'  => 'company_uuid',
        'customer_uuid' => 'customer_uuid',
        'status'        => $status,
        'meta'          => $meta,
    ]);
    $order->setRelation('payload', null);
    $order->setRelation('transaction', null);
    $order->setRelation('customer', new InternalOrderCoverageCustomer());

    return $order;
}

function internalOrderCoverageDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

/**
 * Create tables as nullable text columns; a column spelled `int:name` is an integer, so the
 * amounts and flags read back as numbers.
 */
function internalOrderCoverageTables(array $tables): void
{
    $schema = internalOrderCoverageDb()->getSchemaBuilder();
    foreach ($tables as $table => $columns) {
        $schema->dropIfExists($table);
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (str_starts_with($column, 'int:')) {
                    $blueprint->integer(substr($column, 4))->nullable();
                    continue;
                }
                $blueprint->text($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
}

/** The tables a cancellation with a refund or store credit writes to. */
function internalOrderCoverageCancelSchema(): void
{
    internalOrderCoverageTables([
        'stores'                => ['uuid', 'public_id', 'company_uuid', 'name', 'currency', 'timezone', 'options'],
        'checkouts'             => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'gateway_uuid', 'stripe_payment_intent_id', 'options', 'token'],
        'gateways'              => ['uuid', 'public_id', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'code', 'type', 'int:sandbox', 'config', 'meta'],
        'transactions'          => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'gateway', 'gateway_uuid', 'gateway_transaction_id', 'int:amount', 'currency', 'description', 'type', 'direction', 'status', 'settlement_status', 'settled_at', 'int:settled_amount', 'settled_currency', 'meta'],
        'promotions'            => ['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'owner_uuid', 'owner_type', 'name', 'description', 'status', 'trigger', 'type', 'value', 'currency', 'int:usage_limit', 'int:usage_limit_per_customer', 'int:stackable', 'int:is_public', 'starts_at', 'ends_at', 'meta'],
        'promotion_codes'       => ['uuid', 'public_id', 'company_uuid', 'promotion_uuid', 'code', 'status', 'int:usage_limit', 'customer_uuid', 'expires_at', 'meta'],
        'promotion_redemptions' => ['uuid', 'company_uuid', 'promotion_uuid', 'promotion_code_uuid', 'customer_uuid', 'checkout_uuid', 'order_uuid', 'int:amount', 'currency', 'status', 'redeemed_at'],
    ]);
    internalOrderCoverageDb()->table('stores')->insert(['uuid' => 'store_uuid', 'public_id' => 'store_public', 'company_uuid' => 'company_uuid', 'name' => 'Corner Deli', 'currency' => 'USD']);
    internalOrderCoverageDb()->table('promotion_redemptions')->insert([
        ['uuid' => 'redemption_redeemed', 'promotion_uuid' => 'promo_uuid', 'order_uuid' => 'order_uuid', 'amount' => 500, 'status' => 'redeemed'],
        ['uuid' => 'redemption_other', 'promotion_uuid' => 'promo_uuid', 'order_uuid' => 'other_order_uuid', 'amount' => 500, 'status' => 'redeemed'],
    ]);
}

beforeEach(function () {
    $this->internalOrderCoverageDispatcher = Model::getEventDispatcher();
    Model::unsetEventDispatcher();
    session(['company' => 'company_uuid', 'user' => null]);
});

afterEach(function () {
    if ($this->internalOrderCoverageDispatcher) {
        Model::setEventDispatcher($this->internalOrderCoverageDispatcher);
    }
});

test('an order edit that cannot be made carries its status and context, 422 unless it says otherwise', function () {
    $stage    = new OrderEditException('The driver has the order.', 422, ['violations' => ['items' => 'locked']]);
    $moved    = new OrderEditException('The new total is 1200, not 1000.', 409, ['proposed' => ['total' => 1200]]);
    $fallback = new OrderEditException('Settlement failed.', 0);
    $default  = new OrderEditException('Nothing changed.');

    expect($stage->status())->toBe(422)
        ->and($stage->context)->toBe(['violations' => ['items' => 'locked']])
        ->and($stage->getMessage())->toBe('The driver has the order.')
        ->and($moved->status())->toBe(409)
        ->and($moved->getCode())->toBe(409)
        ->and($fallback->status())->toBe(422)
        ->and($default->status())->toBe(422)
        ->and($default->context)->toBe([])
        ->and($default)->toBeInstanceOf(RuntimeException::class);
});

test('accepting a booking confirms it without the order-accepted notice; an order gets the notice', function () {
    internalOrderCoverageCancelSchema();
    $controller        = new InternalOrderCoverageController();
    $booking           = internalOrderCoverageOrder(['is_booking' => true]);
    $controller->order = $booking;

    $accepted = $controller->acceptOrder(Request::create('/orders/accept', 'POST', ['order' => 'order_uuid']));

    expect($accepted)->toBe(['status' => 'accepted', 'order' => 'order_public'])
        ->and($booking->calls)->toBe(['set:accepted', 'activity:accepted'])
        ->and($booking->customer->notifications)->toBe([]);

    $order             = internalOrderCoverageOrder(['storefront_id' => 'store_public']);
    $controller->order = $order;
    $controller->acceptOrder(Request::create('/orders/accept', 'POST', ['order' => 'order_uuid']));

    expect($order->customer->notifications)->toHaveCount(1)
        ->and($order->customer->notifications[0])->toBeInstanceOf(Fleetbase\Storefront\Notifications\StorefrontOrderAccepted::class);
});

test('marking a booking at the store ready starts it; a booking away is dispatched without preparing', function () {
    $controller        = new InternalOrderCoverageController();
    $atStore           = internalOrderCoverageOrder(['is_booking' => true, 'is_pickup' => true], 'confirmed');
    $controller->order = $atStore;

    $started = $controller->markOrderAsReady(Request::create('/orders/ready', 'POST', ['order' => 'order_uuid']));

    expect($started)->toBe(['status' => 'in_progress', 'order' => 'order_public'])
        ->and($atStore->calls)->toBe(['update_status:in_progress'])
        ->and($controller->patches)->toBe(1);

    $away              = internalOrderCoverageOrder(['is_booking' => true], 'confirmed');
    $controller->order = $away;
    $dispatched        = $controller->markOrderAsReady(Request::create('/orders/ready', 'POST', ['order' => 'order_uuid']));

    expect($dispatched['status'])->toBe('confirmed')
        ->and($away->calls)->toBe(['dispatch']);
});

test('canceling refuses a refund method, amount or partial refund the payment does not allow, and keeps the order open', function () {
    internalOrderCoverageCancelSchema();
    $controller = new InternalOrderCoverageController();
    $paid       = ['total' => 5000, 'currency' => 'USD', 'gateway' => 'stripe', 'storefront_id' => 'store_public'];

    $controller->order = internalOrderCoverageOrder($paid, 'preparing');
    $method            = $controller->rejectOrder(Request::create('/orders/reject', 'POST', ['order' => 'order_uuid', 'settlement' => 'cash_back']));
    $nothing           = $controller->rejectOrder(Request::create('/orders/reject', 'POST', ['order' => 'order_uuid', 'amount' => '0']));
    $tooMuch           = $controller->rejectOrder(Request::create('/orders/reject', 'POST', ['order' => 'order_uuid', 'amount' => 5001]));

    expect($method->getData(true))->toBe(['error' => 'This payment cannot be given back that way.'])
        ->and($nothing->getData(true))->toBe(['error' => 'The amount given back must be more than nothing and no more than what was paid.'])
        ->and($tooMuch->getData(true))->toBe(['error' => 'The amount given back must be more than nothing and no more than what was paid.'])
        ->and($controller->order->status)->toBe('preparing');

    // QPay refunds whole payments only.
    $controller->order = internalOrderCoverageOrder([...$paid, 'gateway' => 'qpay'], 'preparing');
    $partial           = $controller->rejectOrder(Request::create('/orders/reject', 'POST', ['order' => 'order_uuid', 'settlement' => 'refund', 'amount' => 1000]));

    expect($partial->getData(true))->toBe(['error' => 'This payment can only be refunded in full; give store credit for part of it.'])
        ->and($controller->order->status)->toBe('preparing');
});

test('a refund the gateway cannot make leaves the order as it was', function () {
    internalOrderCoverageCancelSchema();
    $controller        = new InternalOrderCoverageController();
    $controller->order = internalOrderCoverageOrder(['total' => 5000, 'currency' => 'USD', 'gateway' => 'stripe'], 'preparing');

    $refused = $controller->rejectOrder(Request::create('/orders/reject', 'POST', ['order' => 'order_uuid', 'reason' => 'Kitchen closed']));

    expect($refused->getStatusCode())->toBe(400)
        ->and($refused->getData(true))->toBe(['error' => 'This order has no Stripe payment to refund.'])
        ->and($controller->order->status)->toBe('preparing')
        ->and($controller->order->getMeta('cancel_reason'))->toBeNull()
        ->and(internalOrderCoverageDb()->table('promotion_redemptions')->where('uuid', 'redemption_redeemed')->value('status'))->toBe('redeemed')
        ->and(internalOrderCoverageDb()->table('transactions')->count())->toBe(0);
});

test('canceling with store credit issues a single-use code, releases the promotion uses and cancels the order', function () {
    internalOrderCoverageCancelSchema();
    $controller        = new InternalOrderCoverageController();
    $order             = internalOrderCoverageOrder(['total' => 5000, 'currency' => 'USD', 'gateway' => 'qpay', 'storefront_id' => 'store_public'], 'preparing');
    $controller->order = $order;

    $canceled = $controller->rejectOrder(Request::create('/orders/reject', 'POST', [
        'order'      => 'order_uuid',
        'reason'     => '  Out of stock  ',
        'settlement' => 'store_credit',
        'amount'     => 2000,
    ]));
    $refund    = $canceled['refund'];
    $promotion = internalOrderCoverageDb()->table('promotions')->first();
    $code      = internalOrderCoverageDb()->table('promotion_codes')->first();
    $credit    = internalOrderCoverageDb()->table('transactions')->first();

    expect($canceled['status'])->toBe('canceled')
        ->and($order->calls)->toBe(['update_status:canceled'])
        ->and($order->getMeta('cancel_reason'))->toBe('Out of stock')
        ->and($refund)->toMatchArray(['type' => 'store_credit', 'amount' => 2000, 'gateway' => 'store_credit', 'reason' => 'Out of stock'])
        ->and($refund['reference'])->toStartWith('CREDIT-')
        ->and($code->code)->toBe($refund['reference'])
        ->and($code->customer_uuid)->toBe('customer_uuid')
        ->and($code->promotion_uuid)->toBe($promotion->uuid)
        ->and((int) $promotion->value)->toBe(2000)
        ->and($promotion->owner_uuid)->toBe('store_uuid')
        ->and($promotion->name)->toBe('Store credit for order_public')
        ->and((int) $credit->amount)->toBe(2000)
        ->and($credit->gateway)->toBe('store_credit')
        ->and($order->getMeta('payment_adjustments'))->toHaveCount(1)
        // the order's promotion uses are given back; another order's are not
        ->and(internalOrderCoverageDb()->table('promotion_redemptions')->where('uuid', 'redemption_redeemed')->value('status'))->toBe('released')
        ->and(internalOrderCoverageDb()->table('promotion_redemptions')->where('uuid', 'redemption_other')->value('status'))->toBe('redeemed')
        ->and($controller->patches)->toBe(1);
});

/**
 * Everything the order panel endpoints read or write for a persisted storefront order: the
 * order with its payload, items, places and customer, its payment, tracking, chat and proof.
 */
function internalOrderCoverageOrderSchema(): void
{
    internalOrderCoverageCancelSchema();
    internalOrderCoverageTables([
        'orders'              => ['uuid', 'public_id', 'internal_id', '_key', 'company_uuid', 'customer_uuid', 'customer_type', 'payload_uuid', 'transaction_uuid', 'order_config_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'purchase_rate_uuid', 'facilitator_uuid', 'facilitator_type', 'route_uuid', 'created_by_uuid', 'updated_by_uuid', 'status', 'type', 'int:adhoc', 'int:dispatched', 'int:started', 'int:pod_required', 'pod_method', 'scheduled_at', 'dispatched_at', 'started_at', 'notes', 'options', 'meta', 'int:distance', 'int:time', 'int:orchestrator_priority'],
        'payloads'            => ['uuid', 'public_id', '_key', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'payment_method', 'int:cod_amount', 'cod_currency', 'cod_payment_method', 'type', 'meta'],
        'places'              => ['uuid', 'public_id', '_key', 'company_uuid', 'owner_uuid', 'owner_type', 'name', 'street1', 'street2', 'city', 'country', 'phone', 'location', 'meta'],
        'waypoints'           => ['uuid', 'public_id', '_key', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'order', 'type'],
        'entities'            => ['uuid', 'public_id', '_key', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'name', 'description', 'type', 'currency', 'price', 'meta'],
        'contacts'            => ['uuid', 'public_id', '_key', 'company_uuid', 'user_uuid', 'type', 'name', 'email', 'phone', 'meta'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', 'email', 'type', 'status'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'current_job_uuid'],
        'order_configs'       => ['uuid', 'public_id', '_key', 'company_uuid', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'tags', 'version', 'int:core_service', 'status', 'type'],
        'tracking_numbers'    => ['uuid', 'public_id', '_key', 'company_uuid', 'tracking_number', 'region', 'location', 'status_uuid', 'owner_uuid', 'owner_type', 'qr_code', 'barcode'],
        'tracking_statuses'   => ['uuid', 'public_id', '_key', 'company_uuid', 'tracking_number_uuid', 'proof_uuid', 'status', 'details', 'location', 'code', 'complete'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'service_quote_uuid', 'payload_uuid', 'customer_uuid', 'customer_type', 'status', 'meta'],
        'service_quotes'      => ['uuid', 'public_id', 'company_uuid', 'service_rate_uuid', 'payload_uuid', 'integrated_vendor_uuid', 'int:amount', 'currency', 'meta', 'expired_at'],
        'service_quote_items' => ['uuid', 'public_id', 'service_quote_uuid', 'int:amount', 'currency', 'details', 'code'],
        'comments'            => ['uuid', 'public_id', 'company_uuid', 'author_uuid', 'parent_comment_uuid', 'subject_uuid', 'subject_type', 'content', 'tags', 'meta'],
        'files'               => ['uuid', 'public_id', 'company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'disk', 'path', 'bucket', 'folder', 'original_filename', 'type', 'content_type', 'int:file_size', 'caption', 'meta'],
        'store_locations'     => ['uuid', 'public_id', 'company_uuid', 'store_uuid', 'place_uuid', 'name'],
        'store_hours'         => ['uuid', 'store_location_uuid', 'day_of_week', 'start', 'end'],
        'chat_channels'       => ['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'name', 'slug', 'meta'],
        'chat_participants'   => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'chat_channel_uuid', 'meta'],
        'chat_messages'       => ['uuid', 'public_id', 'company_uuid', 'sender_uuid', 'chat_channel_uuid', 'content', 'meta'],
        'chat_receipts'       => ['uuid', 'public_id', 'company_uuid', 'participant_uuid', 'chat_message_uuid', 'read_at'],
        'chat_attachments'    => ['uuid', 'public_id', 'company_uuid', 'chat_channel_uuid', 'chat_message_uuid', 'sender_uuid', 'file_uuid'],
        'companies'           => ['uuid', 'public_id', 'name', 'country', 'timezone'],
        'custom_field_values' => ['uuid', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'],
        'proofs'              => ['uuid', 'public_id', '_key', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'file_uuid', 'remarks', 'raw_data', 'data'],
    ]);

    $db = internalOrderCoverageDb();
    $db->table('contacts')->insert(['uuid' => 'customer_uuid', 'public_id' => 'contact_ada', 'company_uuid' => 'company_uuid', 'user_uuid' => 'customer_user_uuid', 'type' => 'customer', 'name' => 'Ada Buyer', 'created_at' => '2026-01-05 10:00:00']);
    $db->table('places')->insert([
        ['uuid' => 'store_place_uuid', 'public_id' => 'place_store', 'company_uuid' => 'company_uuid', 'name' => 'Corner Deli', 'street1' => '1 Market St'],
        ['uuid' => 'home_place_uuid', 'public_id' => 'place_home', 'company_uuid' => 'company_uuid', 'name' => 'Home', 'street1' => '9 Elm Rd'],
    ]);
    $db->table('payloads')->insert(['uuid' => 'payload_uuid', 'public_id' => 'payload_one', 'company_uuid' => 'company_uuid', 'pickup_uuid' => 'store_place_uuid', 'dropoff_uuid' => 'home_place_uuid']);
    $db->table('entities')->insert([
        ['uuid' => 'entity_soup_uuid', 'public_id' => 'entity_soup', 'company_uuid' => 'company_uuid', 'payload_uuid' => 'payload_uuid', 'name' => 'Tomato soup', 'meta' => json_encode(['product_id' => 'product_soup', 'quantity' => 2, 'subtotal' => 1800])],
        ['uuid' => 'entity_bread_uuid', 'public_id' => 'entity_bread', 'company_uuid' => 'company_uuid', 'payload_uuid' => 'payload_uuid', 'name' => 'Sourdough', 'meta' => json_encode(['product_id' => 'product_bread', 'quantity' => 1, 'subtotal' => 700, 'unavailable' => true])],
    ]);
    $db->table('store_locations')->insert(['uuid' => 'location_uuid', 'public_id' => 'store_location_main', 'company_uuid' => 'company_uuid', 'store_uuid' => 'store_uuid', 'place_uuid' => 'store_place_uuid', 'name' => 'Main', 'created_at' => '2026-01-01 00:00:00']);
}

function internalOrderCoverageSeedOrder(array $attributes = [], array $meta = []): void
{
    internalOrderCoverageDb()->table('orders')->insert(array_merge([
        'uuid'          => 'order_uuid',
        'public_id'     => 'order_public',
        'company_uuid'  => 'company_uuid',
        'customer_uuid' => 'customer_uuid',
        'customer_type' => Fleetbase\FleetOps\Models\Contact::class,
        'payload_uuid'  => 'payload_uuid',
        'status'        => 'preparing',
        'type'          => 'storefront',
        'adhoc'         => 0,
        'dispatched'    => 0,
        'started'       => 0,
        'pod_required'  => 0,
        'meta'          => json_encode(array_merge(['storefront_id' => 'store_public', 'storefront' => 'Corner Deli', 'total' => 5000, 'subtotal' => 4500, 'delivery_fee' => 500, 'currency' => 'USD', 'gateway' => 'stripe'], $meta)),
        'created_at'    => now()->toDateTimeString(),
        'updated_at'    => now()->toDateTimeString(),
    ], $attributes));
}

/** A Stripe API that answers from canned responses; records what was asked. */
function internalOrderCoverageStripe(array $routes): object
{
    $client = new class($routes) implements Stripe\HttpClient\ClientInterface {
        public array $requests = [];

        public function __construct(private array $routes)
        {
        }

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = [$method, $absUrl, $params];
            foreach ($this->routes as $path => $response) {
                if (str_contains($absUrl, $path)) {
                    return [json_encode($response[1]), $response[0], []];
                }
            }

            return [json_encode(['error' => ['message' => 'Unexpected request ' . $absUrl]]), 500, []];
        }
    };
    Stripe\ApiRequestor::setHttpClient($client);

    return $client;
}

/** Records reported exceptions and notifications, the way the framework would route them. */
function internalOrderCoverageFakes(): object
{
    $fakes = new class {
        public array $reported = [];
        public array $sent     = [];
        public bool $failSends = false;
    };
    app()->instance(Illuminate\Contracts\Debug\ExceptionHandler::class, new class($fakes) implements Illuminate\Contracts\Debug\ExceptionHandler {
        public function __construct(private object $fakes)
        {
        }

        public function report(Throwable $e)
        {
            $this->fakes->reported[] = $e;
        }

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e)
        {
        }
    });
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new class($fakes) implements Illuminate\Contracts\Notifications\Dispatcher {
        public function __construct(private object $fakes)
        {
        }

        public function send($notifiables, $notification)
        {
            $this->sendNow($notifiables, $notification);
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            if ($this->fakes->failSends) {
                throw new RuntimeException('Push provider is down.');
            }
            $this->fakes->sent[] = [$notifiables, $notification];
        }
    });
    Illuminate\Support\Facades\Notification::clearResolvedInstance(Illuminate\Contracts\Notifications\Dispatcher::class);

    return $fakes;
}

afterEach(function () {
    app()->offsetUnset(Illuminate\Contracts\Debug\ExceptionHandler::class);
    app()->offsetUnset(Illuminate\Contracts\Notifications\Dispatcher::class);
    Illuminate\Support\Facades\Notification::clearResolvedInstance(Illuminate\Contracts\Notifications\Dispatcher::class);
    Stripe\ApiRequestor::setHttpClient(new Stripe\HttpClient\CurlClient());
    if (array_key_exists('internalOrderCoverageFilesystem', $GLOBALS)) {
        [$previous] = $GLOBALS['internalOrderCoverageFilesystem'];
        $previous ? app()->instance('filesystem', $previous) : app()->offsetUnset('filesystem');
        Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');
        unset($GLOBALS['internalOrderCoverageFilesystem']);
    }
});

test('the order panel endpoints answer not found for an unknown order', function () {
    internalOrderCoverageOrderSchema();
    $controller = new InternalOrderController();
    $request    = Request::create('/orders/missing', 'POST');

    foreach ([
        $controller->previewEdit('order_missing', $request),
        $controller->edit('order_missing', $request),
        $controller->overview('order_missing'),
        $controller->issueBalanceLink('order_missing'),
        $controller->checkBalancePayment('order_missing'),
        $controller->flow('order_missing'),
        $controller->activity('order_missing'),
        $controller->updateEntity('order_missing', 'entity_soup', $request),
        $controller->updateActivity('order_missing', $request),
    ] as $response) {
        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe(['error' => 'Order not found']);
    }
});

test('previewing an edit prices it without changing the order', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder(['notes' => 'Ring twice']);

    $preview = (new InternalOrderController())->previewEdit('order_public', Request::create('/orders/order_public/edit/preview', 'POST', ['notes' => 'Leave at the door']))->getData(true);

    expect($preview['changes'])->toBe(['notes'])
        ->and($preview['summary'])->toBe('Notes updated')
        ->and($preview['difference'])->toBe(0)
        ->and($preview['stage'])->toBe('open')
        ->and($preview['locked'])->toBe([])
        ->and($preview['violations'])->toBe([])
        ->and($preview['proposed']['total'])->toBe(5000)
        ->and(internalOrderCoverageDb()->table('orders')->value('notes'))->toBe('Ring twice');
});

test('an edit the order does not allow answers with the editor status and context', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder();
    internalOrderCoverageDb()->table('entities')->delete();

    $refused = (new InternalOrderController())->edit('order_public', Request::create('/orders/order_public/edit', 'POST', ['notes' => 'Leave at the door']));

    expect($refused->getStatusCode())->toBe(422)
        ->and($refused->getData(true))->toBe([
            'errors'     => ['An order needs at least one item; cancel it instead.'],
            'violations' => ['items' => 'An order needs at least one item; cancel it instead.'],
        ]);

    internalOrderCoverageSeedOrder(['uuid' => 'other_order_uuid', 'public_id' => 'order_other', 'payload_uuid' => 'other_payload_uuid', 'notes' => 'Ring twice']);
    internalOrderCoverageDb()->table('payloads')->insert(['uuid' => 'other_payload_uuid', 'public_id' => 'payload_other', 'company_uuid' => 'company_uuid']);
    internalOrderCoverageDb()->table('entities')->insert(['uuid' => 'other_entity_uuid', 'public_id' => 'entity_other', 'payload_uuid' => 'other_payload_uuid', 'name' => 'Tea', 'meta' => json_encode(['product_id' => 'product_tea', 'quantity' => 1, 'subtotal' => 300])]);
    $moved = (new InternalOrderController())->edit('order_other', Request::create('/orders/order_other/edit', 'POST', ['notes' => 'Leave at the door', 'confirm_total' => 4000]));

    expect($moved->getStatusCode())->toBe(409)
        ->and($moved->getData(true)['errors'])->toBe(['The new total is 5000, not 4000. Review the change and confirm again.'])
        ->and($moved->getData(true)['proposed']['total'])->toBe(5000);
});

test('an edit changes the order and answers with the order, its settlement and what changed', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder(['notes' => 'Ring twice']);
    $fakes = internalOrderCoverageFakes();

    $edited = (new InternalOrderController())->edit('order_public', Request::create('/orders/order_public/edit', 'POST', ['notes' => ' Leave at the door ', 'notify_customer' => true]));
    $row    = internalOrderCoverageDb()->table('orders')->where('uuid', 'order_uuid')->first();
    $meta   = json_decode($row->meta, true);

    expect($edited['status'])->toBe('preparing')
        ->and($edited['order'])->toBeInstanceOf(Fleetbase\Storefront\Http\Resources\Order::class)
        ->and($edited['order']->resource->public_id)->toBe('order_public')
        ->and($edited['settlement'])->toBeNull()
        ->and($edited['changes'])->toBe(['notes'])
        ->and($edited['summary'])->toBe('Notes updated')
        ->and($row->notes)->toBe('Leave at the door')
        ->and($meta['edits'][0]['changes'])->toBe(['notes'])
        ->and($meta['payment']['state'])->toBe('paid')
        ->and($fakes->sent)->toHaveCount(1)
        ->and($fakes->sent[0][1])->toBeInstanceOf(Fleetbase\Storefront\Notifications\StorefrontOrderUpdated::class);
});

/** A file store that serves every disk from one CDN host. */
function internalOrderCoverageFiles(): void
{
    $GLOBALS['internalOrderCoverageFilesystem'] = [app()->bound('filesystem') ? app('filesystem') : null];
    $adapter                                    = new League\Flysystem\Local\LocalFilesystemAdapter(sys_get_temp_dir());
    $disk                                       = new Illuminate\Filesystem\FilesystemAdapter(new League\Flysystem\Filesystem($adapter), $adapter, ['url' => 'https://files.test']);
    app()->instance('filesystem', new class($disk) {
        public function __construct(private Illuminate\Filesystem\FilesystemAdapter $disk)
        {
        }

        public function disk($name = null)
        {
            return $this->disk;
        }
    });
    Illuminate\Support\Facades\Storage::clearResolvedInstance('filesystem');
}

test('the order overview gathers payment, chat, source, payment reference and delivery proof', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageFiles();
    $db = internalOrderCoverageDb();
    internalOrderCoverageSeedOrder(
        ['status' => 'completed', 'transaction_uuid' => 'transaction_uuid', 'tracking_number_uuid' => 'tracking_uuid', 'distance' => 3200, 'time' => 900],
        ['placed_via' => 'console']
    );
    $db->table('transactions')->insert(['uuid' => 'transaction_uuid', 'public_id' => 'transaction_capture', 'gateway' => 'stripe', 'gateway_transaction_id' => 'pi_capture', 'amount' => 5000, 'currency' => 'USD', 'settled_at' => '2026-10-14 09:30:00', 'created_at' => '2026-10-14 09:29:00']);
    $db->table('checkouts')->insert(['uuid' => 'checkout_uuid', 'public_id' => 'chkt_one', 'order_uuid' => 'order_uuid', 'options' => json_encode(['qpay_invoice_id' => 'invoice_77'])]);
    $db->table('tracking_statuses')->insert(['uuid' => 'status_done', 'tracking_number_uuid' => 'tracking_uuid', 'status' => 'Order completed', 'code' => 'COMPLETED', 'created_at' => '2026-10-14 10:05:00']);
    $db->table('chat_channels')->insert(['uuid' => 'channel_customer', 'public_id' => 'chat_customer', 'company_uuid' => 'company_uuid', 'meta' => json_encode(['storefront_order_uuid' => 'order_uuid', 'kind' => 'customer'])]);
    $db->table('chat_participants')->insert(['uuid' => 'participant_customer', 'chat_channel_uuid' => 'channel_customer', 'user_uuid' => 'customer_user_uuid']);
    $db->table('chat_messages')->insert(['uuid' => 'message_one', 'chat_channel_uuid' => 'channel_customer', 'sender_uuid' => 'participant_customer', 'content' => 'Is it on the way?', 'created_at' => '2026-10-14 10:00:00']);
    $db->table('files')->insert([
        ['uuid' => 'file_photo', 'disk' => 'public', 'path' => 'proofs/door.jpg', 'content_type' => 'image/jpeg'],
        ['uuid' => 'file_signature', 'disk' => 'public', 'path' => 'proofs/signature.png', 'content_type' => 'image/png'],
    ]);
    $db->table('proofs')->insert([
        ['uuid' => 'proof_photo_uuid', 'public_id' => 'proof_photo', 'order_uuid' => 'order_uuid', 'subject_uuid' => 'order_uuid', 'file_uuid' => 'file_photo', 'remarks' => 'Left at the door', 'raw_data' => null, 'data' => null, 'created_at' => '2026-10-14 10:04:00'],
        ['uuid' => 'proof_signature_uuid', 'public_id' => 'proof_signature', 'order_uuid' => null, 'subject_uuid' => 'order_uuid', 'file_uuid' => 'file_signature', 'remarks' => 'Customer signature', 'raw_data' => null, 'data' => null, 'created_at' => '2026-10-14 10:03:00'],
        ['uuid' => 'proof_scan_uuid', 'public_id' => 'proof_scan', 'order_uuid' => 'order_uuid', 'subject_uuid' => 'order_uuid', 'file_uuid' => null, 'remarks' => null, 'raw_data' => 'QR-123', 'data' => null, 'created_at' => '2026-10-14 10:02:00'],
        ['uuid' => 'proof_typed_uuid', 'public_id' => null, 'order_uuid' => 'order_uuid', 'subject_uuid' => 'entity_soup_uuid', 'file_uuid' => null, 'remarks' => '', 'raw_data' => '', 'data' => json_encode(['type' => 'barcode']), 'created_at' => '2026-10-14 10:01:00'],
        ['uuid' => 'proof_elsewhere_uuid', 'public_id' => 'proof_elsewhere', 'order_uuid' => 'other_order_uuid', 'subject_uuid' => 'other_order_uuid', 'file_uuid' => null, 'remarks' => null, 'raw_data' => 'QR-999', 'data' => null, 'created_at' => '2026-10-14 10:06:00'],
    ]);

    $overview = (new InternalOrderController())->overview('order_public')->getData(true);

    expect($overview['payment']['state'])->toBe('paid')
        ->and($overview['edit']['stage'])->toBe('closed')
        ->and($overview['cancel']['refundable'])->toBe(5000)
        ->and($overview['chat']['unread'])->toBe(['customer' => 1, 'dispatch' => 0, 'group' => 0])
        ->and($overview['chat']['unread_total'])->toBe(1)
        ->and($overview['chat']['threads'])->toBe(['customer' => true, 'dispatch' => false, 'group' => false])
        ->and($overview['chat']['channels'])->toBe(['customer' => ['uuid' => 'channel_customer', 'public_id' => 'chat_customer'], 'dispatch' => null, 'group' => null])
        ->and($overview['source'])->toBe('console')
        ->and($overview['payment_reference']['transaction'])->toBe('transaction_capture')
        ->and($overview['payment_reference']['reference'])->toBe('pi_capture')
        ->and($overview['payment_reference']['invoice'])->toBe('invoice_77')
        ->and($overview['payment_reference']['paid_at'])->toStartWith('2026-10-14T09:30:00')
        ->and($overview['delivery']['distance_m'])->toBe(3200)
        ->and($overview['delivery']['duration_s'])->toBe(900)
        ->and($overview['delivery']['delivered_at'])->toStartWith('2026-10-14T10:05:00')
        // proof taken on the order itself; proof on an item counts towards the items only
        ->and($overview['delivery']['proofs'])->toBe(3)
        ->and(array_column($overview['delivery']['proof_items'], 'id'))->toBe(['proof_photo', 'proof_signature', 'proof_scan', 'proof_typed_uuid'])
        ->and($overview['delivery']['proof_items'][0])->toMatchArray(['type' => 'photo', 'url' => 'https://files.test/proofs/door.jpg', 'content_type' => 'image/jpeg', 'remarks' => 'Left at the door', 'code' => null])
        ->and($overview['delivery']['proof_items'][0]['at'])->toStartWith('2026-10-14T10:04:00')
        ->and($overview['delivery']['proof_items'][1])->toMatchArray(['type' => 'signature', 'url' => 'https://files.test/proofs/signature.png', 'content_type' => 'image/png'])
        ->and($overview['delivery']['proof_items'][2])->toMatchArray(['type' => 'scan', 'url' => null, 'remarks' => null, 'code' => 'QR-123'])
        ->and($overview['delivery']['proof_items'][3])->toMatchArray(['type' => 'barcode', 'remarks' => null, 'code' => null]);
});

test('an order placed in the app without a payment record or proof storage still has an overview', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder([], ['gateway' => 'cash']);
    internalOrderCoverageDb()->getSchemaBuilder()->drop('proofs');

    $overview = (new InternalOrderController())->overview('order_public')->getData(true);

    expect($overview['source'])->toBe('app')
        ->and($overview['payment']['state'])->toBe('to_collect')
        ->and($overview['payment_reference'])->toBeNull()
        ->and($overview['edit']['stage'])->toBe('open')
        ->and($overview['chat']['threads'])->toBe(['customer' => false, 'dispatch' => false, 'group' => false])
        ->and($overview['delivery'])->toBe(['distance_m' => null, 'duration_s' => null, 'delivered_at' => null, 'proofs' => 0, 'proof_items' => []]);
});

/** A paid Stripe order with a checkout through the store's Stripe gateway. */
function internalOrderCoverageStripeOrder(array $meta = []): void
{
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder([], $meta);
    internalOrderCoverageDb()->table('gateways')->insert(['uuid' => 'gateway_stripe_uuid', 'public_id' => 'gateway_stripe', 'code' => 'stripe', 'type' => 'stripe', 'sandbox' => 0, 'config' => json_encode(['secret_key' => 'sk_test_storefront'])]);
    internalOrderCoverageDb()->table('checkouts')->insert(['uuid' => 'checkout_uuid', 'public_id' => 'chkt_one', 'order_uuid' => 'order_uuid', 'gateway_uuid' => 'gateway_stripe_uuid']);
}

test('a payment link is issued for the balance due, and refused when nothing is due', function () {
    internalOrderCoverageStripeOrder();
    $stripe = internalOrderCoverageStripe([]);

    $nothing = (new InternalOrderController())->issueBalanceLink('order_public');

    expect($nothing->getStatusCode())->toBe(400)
        ->and($nothing->getData(true))->toBe(['error' => 'Nothing is due on this order.'])
        ->and($stripe->requests)->toBe([]);

    internalOrderCoverageStripeOrder(['balance_due' => 700]);
    $stripe = internalOrderCoverageStripe([
        '/v1/prices'        => [200, ['id' => 'price_balance', 'object' => 'price']],
        '/v1/payment_links' => [200, ['id' => 'plink_balance', 'object' => 'payment_link', 'url' => 'https://buy.stripe.test/balance']],
    ]);

    $issued = (new InternalOrderController())->issueBalanceLink('order_public')->getData(true);
    $meta   = json_decode(internalOrderCoverageDb()->table('orders')->value('meta'), true);

    expect($issued['payment'])->toMatchArray(['state' => 'balance_due', 'balance_due' => 700, 'payment_link' => 'https://buy.stripe.test/balance'])
        ->and($meta['balance_payment'])->toMatchArray(['gateway' => 'stripe', 'reference' => 'plink_balance', 'amount' => 700])
        ->and($stripe->requests[0][2])->toMatchArray(['currency' => 'usd', 'unit_amount' => 700]);
});

test('checking the balance payment records a paid balance, reports nothing paid, and surfaces gateway errors', function () {
    internalOrderCoverageStripeOrder();
    $unpaid = (new InternalOrderController())->checkBalancePayment('order_public')->getData(true);

    expect($unpaid['paid'])->toBeFalse()
        ->and($unpaid['charge'])->toBeNull()
        ->and($unpaid['payment']['state'])->toBe('paid');

    $issued = ['balance_due' => 700, 'balance_payment' => ['gateway' => 'stripe', 'reference' => 'plink_balance', 'amount' => 700], 'balance_payment_link' => 'https://buy.stripe.test/balance'];
    internalOrderCoverageStripeOrder($issued);
    internalOrderCoverageStripe(['/v1/checkout/sessions' => [401, ['error' => ['message' => 'Invalid API Key provided']]]]);

    $failed = (new InternalOrderController())->checkBalancePayment('order_public');

    expect($failed->getStatusCode())->toBe(400)
        ->and($failed->getData(true))->toBe(['error' => 'Invalid API Key provided']);

    internalOrderCoverageStripeOrder($issued);
    $fakes = internalOrderCoverageFakes();
    internalOrderCoverageStripe([
        '/v1/checkout/sessions'           => [200, ['object' => 'list', 'data' => [['id' => 'cs_paid', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 700, 'payment_intent' => 'pi_balance']]]],
        '/v1/payment_links/plink_balance' => [200, ['id' => 'plink_balance', 'object' => 'payment_link', 'active' => false]],
    ]);

    $paid = (new InternalOrderController())->checkBalancePayment('order_public')->getData(true);

    expect($paid['paid'])->toBeTrue()
        ->and($paid['charge'])->toMatchArray(['type' => 'charge', 'amount' => 700, 'gateway' => 'stripe', 'reference' => 'pi_balance'])
        ->and($paid['payment'])->toMatchArray(['state' => 'paid', 'balance_due' => 0, 'payment_link' => null, 'amount_paid' => 5700])
        ->and((int) internalOrderCoverageDb()->table('transactions')->value('amount'))->toBe(700)
        ->and($fakes->sent)->toHaveCount(1)
        ->and($fakes->sent[0][1])->toBeInstanceOf(Fleetbase\Storefront\Notifications\StorefrontOrderBalancePaid::class);
});

test('the order flow shows the steps of its order config', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder(['order_config_uuid' => 'config_uuid', 'tracking_number_uuid' => 'tracking_uuid']);
    internalOrderCoverageDb()->table('order_configs')->insert([
        'uuid'      => 'config_uuid',
        'public_id' => 'order_config_storefront',
        'key'       => 'storefront',
        'name'      => 'Storefront delivery',
        'flow'      => json_encode([
            'created'   => ['code' => 'created', 'status' => 'Order created', 'activities' => ['preparing']],
            'preparing' => ['code' => 'preparing', 'status' => 'Preparing', 'activities' => ['completed']],
            'completed' => ['code' => 'completed', 'status' => 'Order completed', 'complete' => true],
        ]),
    ]);
    internalOrderCoverageDb()->table('tracking_statuses')->insert(['uuid' => 'status_created', 'tracking_number_uuid' => 'tracking_uuid', 'status' => 'Order created', 'code' => 'CREATED', 'created_at' => '2026-10-14 09:00:00']);

    $flow = (new InternalOrderController())->flow('order_public')->getData(true);

    expect($flow['order'])->toBe('order_public')
        ->and($flow['order_config'])->toBe(['id' => 'order_config_storefront', 'key' => 'storefront', 'name' => 'Storefront delivery'])
        ->and($flow['status'])->toBe('preparing')
        ->and(array_map(fn ($step) => $step['code'] . ':' . $step['state'], $flow['steps']))->toBe(['created:done', 'preparing:current', 'completed:upcoming']);
});

test('the order activity lists its steps, notes, edits and payments newest first', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder(['tracking_number_uuid' => 'tracking_uuid'], [
        'edits'               => [['at' => '2026-10-14T09:20:00+00:00', 'summary' => 'Notes updated', 'by_name' => 'Sam', 'changes' => ['notes']]],
        'payment_adjustments' => [['type' => 'refund', 'amount' => 500, 'reason' => 'Late', 'gateway' => 'stripe', 'at' => '2026-10-14T09:30:00+00:00']],
    ]);
    $db = internalOrderCoverageDb();
    $db->table('users')->insert(['uuid' => 'staff_uuid', 'name' => 'Sam Staff']);
    $db->table('tracking_statuses')->insert(['uuid' => 'status_created', 'tracking_number_uuid' => 'tracking_uuid', 'status' => 'Order created', 'details' => 'New order', 'code' => 'CREATED', 'created_at' => '2026-10-14 09:00:00']);
    $db->table('comments')->insert(['uuid' => 'comment_uuid', 'public_id' => 'comment_call', 'subject_uuid' => 'order_uuid', 'author_uuid' => 'staff_uuid', 'content' => 'Customer called', 'created_at' => '2026-10-14 09:10:00']);

    $activity = (new InternalOrderController())->activity('order_public')->getData(true)['activity'];

    expect(array_column($activity, 'type'))->toBe(['payment', 'edit', 'note', 'status'])
        ->and($activity[0])->toMatchArray(['title' => 'refund', 'body' => 'Late'])
        ->and($activity[1])->toMatchArray(['body' => 'Notes updated', 'by' => 'Sam'])
        ->and($activity[2])->toMatchArray(['body' => 'Customer called', 'by' => 'Sam Staff'])
        ->and($activity[3])->toMatchArray(['title' => 'Order created', 'body' => 'New order']);
});

test('marking an item unavailable tells the customer once, and a note is kept on the item', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder();
    $fakes      = internalOrderCoverageFakes();
    $controller = new InternalOrderController();
    $entityMeta = fn (string $uuid) => json_decode(internalOrderCoverageDb()->table('entities')->where('uuid', $uuid)->value('meta'), true);

    $missing = $controller->updateEntity('order_public', 'entity_missing', Request::create('/x', 'PATCH', ['unavailable' => true]));
    expect($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Item not found on this order']);

    $marked = $controller->updateEntity('order_public', 'entity_soup', Request::create('/x', 'PATCH', ['unavailable' => true, 'note' => '  Out of tomatoes ']));
    expect($marked['customer_told'])->toBeTrue()
        ->and($marked['status'])->toBe('preparing')
        ->and($entityMeta('entity_soup_uuid'))->toMatchArray(['unavailable' => true, 'note' => 'Out of tomatoes'])
        ->and($fakes->sent)->toHaveCount(1)
        ->and($fakes->sent[0][1])->toBeInstanceOf(Fleetbase\Storefront\Notifications\StorefrontOrderItemUnavailable::class)
        ->and($fakes->sent[0][1]->subject)->toBe('Tomato soup is unavailable');

    // already unavailable (by uuid): nobody is told again; an empty note clears it
    $again = $controller->updateEntity('order_public', 'entity_bread_uuid', Request::create('/x', 'PATCH', ['unavailable' => true, 'note' => ' ']));
    expect($again['customer_told'])->toBeFalse()
        ->and($entityMeta('entity_bread_uuid'))->toMatchArray(['unavailable' => true, 'note' => null])
        ->and($fakes->sent)->toHaveCount(1);
});

test('an item is still marked unavailable when telling the customer fails, and the failure is reported', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageSeedOrder();
    $fakes            = internalOrderCoverageFakes();
    $fakes->failSends = true;

    $marked = (new InternalOrderController())->updateEntity('order_public', 'entity_soup', Request::create('/x', 'PATCH', ['unavailable' => '1']));

    expect($marked['customer_told'])->toBeFalse()
        ->and(json_decode(internalOrderCoverageDb()->table('entities')->where('uuid', 'entity_soup_uuid')->value('meta'), true)['unavailable'])->toBeTrue()
        ->and($fakes->reported)->toHaveCount(1)
        ->and($fakes->reported[0]->getMessage())->toBe('Push provider is down.');
});

test('moving an order to an activity uses the activity of its own order config and finishes a driverless order', function () {
    internalOrderCoverageOrderSchema();
    internalOrderCoverageDb()->getPdo()->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', (new Fleetbase\Expansions\Str())->humanize());
    }
    internalOrderCoverageSeedOrder(['order_config_uuid' => 'config_uuid', 'tracking_number_uuid' => 'tracking_uuid', 'status' => 'preparing']);
    internalOrderCoverageSeedOrder(['uuid' => 'foreign_order_uuid', 'public_id' => 'order_foreign', 'company_uuid' => 'other_company_uuid']);
    internalOrderCoverageDb()->table('tracking_numbers')->insert(['uuid' => 'tracking_uuid', 'public_id' => 'tracking_one', 'company_uuid' => 'company_uuid', 'tracking_number' => 'SF-1', 'owner_uuid' => 'order_uuid']);
    internalOrderCoverageDb()->table('order_configs')->insert([
        'uuid'      => 'config_uuid',
        'public_id' => 'order_config_storefront',
        'key'       => 'storefront',
        'name'      => 'Storefront delivery',
        'flow'      => json_encode([
            'created'   => ['key' => 'created', 'code' => 'created', 'status' => 'Order created', 'activities' => ['preparing']],
            'preparing' => ['key' => 'preparing', 'code' => 'preparing', 'status' => 'Preparing', 'activities' => ['completed']],
            'completed' => ['key' => 'completed', 'code' => 'completed', 'status' => 'Handed to the customer', 'details' => 'Order completed at the counter', 'complete' => true],
        ]),
    ]);

    $foreign = (new InternalOrderController())->updateActivity('order_foreign', Request::create('/x', 'PATCH', ['activity' => ['code' => 'completed']]));
    expect($foreign->getStatusCode())->toBe(404);

    (new InternalOrderController())->updateActivity('order_public', Request::create('/x', 'PATCH', ['activity' => ['code' => ' Completed ', 'status' => 'Typed by the console']]));
    $status = internalOrderCoverageDb()->table('tracking_statuses')->where('tracking_number_uuid', 'tracking_uuid')->first();

    expect(internalOrderCoverageDb()->table('orders')->where('uuid', 'order_uuid')->value('status'))->toBe('completed')
        // the activity is the config's, not what the console sent
        ->and($status->status)->toBe('Handed to the customer')
        ->and($status->details)->toBe('Order completed at the counter')
        ->and($status->code)->toBe('COMPLETED')
        ->and(internalOrderCoverageDb()->table('orders')->where('uuid', 'foreign_order_uuid')->value('status'))->toBe('preparing');
});
