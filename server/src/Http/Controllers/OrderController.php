<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as FleetbaseOrderController;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Http\Resources\Index\Order as StorefrontOrderIndexResource;
use Fleetbase\Storefront\Http\Resources\Order as StorefrontOrderResource;
use Fleetbase\Storefront\Notifications\StorefrontOrderAccepted;
use Fleetbase\Storefront\Promotions\PromotionRedemptions;
use Fleetbase\Storefront\Support\OrderActivityFlow;
use Fleetbase\Storefront\Support\OrderEditException;
use Fleetbase\Storefront\Support\OrderEditor;
use Fleetbase\Storefront\Support\OrderInsights;
use Fleetbase\Storefront\Support\OrderPayments;
use Fleetbase\Storefront\Support\OrderSettlement;
use Fleetbase\Storefront\Support\OrderTimeline;
use Fleetbase\Storefront\Support\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends FleetbaseOrderController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'order';

    /**
     * Storefront order lists need checkout totals and customer context.
     *
     * @var string
     */
    public $indexResource = StorefrontOrderIndexResource::class;

    /**
     * The filter to use.
     *
     * @var \Fleetbase\Http\Filter\Filter
     */
    public $filter = \Fleetbase\Storefront\Http\Filter\OrderFilter::class;

    public function onQueryRecord(Builder $query): void
    {
        $query->with(['customer', 'transaction', 'payload', 'driverAssigned', 'orderConfig', 'trackingNumber', 'trackingStatuses']);
    }

    public function findRecord(Request $request, $id)
    {
        try {
            $order = $this->findOrderRecord($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Order not found', 404);
        }

        return [
            'order' => new StorefrontOrderResource($order),
        ];
    }

    protected function findOrderRecord($id): Order
    {
        return Order::findRecordOrFail($id, $this->detailRelations());
    }

    protected function findOrderForAction($uuid, array $relations = []): ?Order
    {
        return Order::where('uuid', $uuid)->whereNull('deleted_at')->with($relations)->first();
    }

    protected function patchOrderConfig(Order $order)
    {
        return Storefront::patchOrderConfig($order);
    }

    protected function createAcceptedActivity($orderConfig)
    {
        return Storefront::createAcceptedActivity($orderConfig);
    }

    protected function notifyOrderAccepted(Order $order): void
    {
        // A booking's confirmation is announced as its activity ("Booking confirmed").
        if (Storefront::isBookingOrder($order)) {
            return;
        }

        $order->customer->notify(new StorefrontOrderAccepted($order));
    }

    protected function detailRelations(): array
    {
        return [
            'customer',
            'transaction',
            'payload',
            'payload.pickup',
            'payload.dropoff',
            'payload.return',
            'payload.waypoints',
            'payload.entities',
            'driverAssigned',
            'orderConfig',
            'trackingNumber',
            'trackingStatuses',
            'purchaseRate',
            'purchaseRate.serviceQuote',
            'purchaseRate.serviceQuote.items',
            'comments',
            'files',
        ];
    }

    protected function orderResponse(Order $order): array
    {
        return [
            'status' => $order->status,
            'order'  => new StorefrontOrderResource($order->fresh($this->detailRelations())),
        ];
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @return \Illuminate\Http\Response
     */
    public function acceptOrder(Request $request)
    {
        $order = $this->findOrderForAction($request->order, ['customer']);

        if (!$order) {
            return response()->json([
                'error' => 'No order to accept!',
            ], 400);
        }

        // Patch order config
        $orderConfig = $this->patchOrderConfig($order);
        $activity    = $this->createAcceptedActivity($orderConfig);

        // Dispatch already if order is a pickup
        if ($order->isMeta('is_pickup')) {
            $order->firstDispatchWithActivity();
        }

        // Set order as accepted
        try {
            $order->setStatus($activity->code);
            $order->insertActivity($activity, $order->getLastLocation());
        } catch (\Exception $e) {
            Log::debug('[Storefront] was unable to accept an order.', ['order' => $order, 'activity' => $activity]);

            return response()->error('Unable to accept order.');
        }

        // Notify customer order was accepted
        try {
            $this->notifyOrderAccepted($order);
        } catch (\Exception $e) {
        }

        return $this->orderResponse($order);
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsReady(Request $request)
    {
        $adhoc  = $request->boolean('adhoc');
        $driver = $request->input('driver');
        /** @var Order $order */
        $order = $this->findOrderForAction($request->order, ['customer']);

        if (!$order) {
            return response()->error('No order to update!');
        }

        // Patch order config
        $this->patchOrderConfig($order);

        // A booking at the store starts when the customer comes in; nobody is dispatched.
        if (Storefront::isBookingOrder($order) && $order->isMeta('is_pickup')) {
            $order->updateStatus('in_progress');

            return $this->orderResponse($order);
        }

        if ($order->isMeta('is_pickup')) {
            $order->updateStatus('pickup_ready');

            return $this->orderResponse($order);
        }

        // toggle order to adhoc
        if ($order->adhoc === false && $adhoc === true) {
            $order->update(['adhoc' => true]);
        }

        // if driver then assign driver
        if ($driver) {
            $order->assignDriver($driver);
        }

        // Dispatch the order and move Storefront delivery orders into preparation. A booking
        // is dispatched to its provider; there is nothing to prepare.
        $order->dispatchWithActivity();
        if (!Storefront::isBookingOrder($order)) {
            $order->updateStatus('preparing');
        }

        return $this->orderResponse($order);
    }

    /**
     * Accept an order by incrementing status to preparing.
     *
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsPreparing(Request $request)
    {
        /** @var Order $order */
        $order = $this->findOrderForAction($request->order, ['customer']);

        if (!$order) {
            return response()->error('No order to update!');
        }

        // Patch order config
        $orderConfig = $this->patchOrderConfig($order);

        // Get preparing activity
        $activity = $orderConfig->getActivityByCode('preparing');

        try {
            $order->setStatus($activity->code);
            $order->insertActivity($activity, $order->getLastLocation());
        } catch (\Exception $e) {
            Log::debug('[Storefront] was unable to trigger order preparing.', ['order' => $order, 'activity' => $activity]);

            return response()->error('Unable to trigger order preparing.');
        }

        return $this->orderResponse($order);
    }

    /**
     * Accept an order by incrementing status to completed.
     *
     * @return \Illuminate\Http\Response
     */
    public function markOrderAsCompleted(Request $request)
    {
        /** @var Order */
        $order = $this->findOrderForAction($request->order, ['customer']);

        if (!$order) {
            return response()->json([
                'error' => 'No order to update!',
            ], 400);
        }

        // Patch order config
        $this->patchOrderConfig($order);

        $order->updateStatus($order->isMeta('is_pickup') ? 'picked_up' : 'completed');
        OrderPayments::settleCollected($order);

        return $this->orderResponse($order);
    }

    public function unassignDriver(Request $request)
    {
        /** @var Order */
        $order = $this->findOrderForAction($request->order, ['driverAssigned']);

        if (!$order) {
            return response()->json([
                'error' => 'No order to update!',
            ], 400);
        }

        if ($order->driverAssigned) {
            $order->driverAssigned->unassignCurrentOrder();
        }

        $order->forceFill([
            'driver_assigned_uuid'  => null,
            'vehicle_assigned_uuid' => null,
        ])->save();

        return $this->orderResponse($order);
    }

    /**
     * Cancel an order: say why, release its promotion uses and give the money back (a refund to the
     * gateway, or store credit where the gateway cannot refund it). The refund happens first, so a
     * refund the gateway refuses leaves the order as it was for staff to choose store credit.
     *
     * @return \Illuminate\Http\Response
     */
    public function rejectOrder(Request $request)
    {
        $order = $this->findOrderForAction($request->order, ['customer', 'payload', 'transaction']);

        if (!$order) {
            return response()->json([
                'error' => 'No order to cancel!',
            ], 400);
        }

        if (OrderInsights::isFinished($order)) {
            return response()->error('This order is already ' . str_replace('_', ' ', $order->status) . '.');
        }

        $reason = trim((string) $request->input('reason')) ?: null;
        $refund = null;

        if ($request->boolean('refund', true)) {
            $payment   = OrderPayments::summary($order);
            $remaining = $payment['amount_paid'] - $payment['amount_refunded'] - $payment['amount_credited'];
            if ($remaining > 0) {
                $method = $request->input('settlement') ?: OrderSettlement::plan($order, -$remaining)['action'];
                try {
                    $refund = OrderSettlement::refund($order, $remaining, $method, $reason ?? 'Order canceled');
                } catch (\Throwable $e) {
                    return response()->error($e->getMessage());
                }
            }
        }

        if ($reason) {
            $order->updateMeta('cancel_reason', $reason);
        }

        $this->releasePromotions($order);

        // Patch order config
        $this->patchOrderConfig($order);

        $order->updateStatus('canceled');

        return [...$this->orderResponse($order), 'refund' => $refund];
    }

    /**
     * A canceled order gives its promotion uses back.
     */
    protected function releasePromotions(Order $order): int
    {
        return PromotionRedemptions::releaseForOrder($order);
    }

    /**
     * Price an edit without making it: new totals, the difference and how it would be settled,
     * and what the order's stage does not allow.
     */
    public function previewEdit(string $id, Request $request)
    {
        try {
            $order = Order::findRecordOrFail($id, ['customer', 'transaction', 'payload.entities', 'payload.dropoff', 'payload.pickup']);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Order not found', 404);
        }

        return response()->json(OrderEditor::preview($order, $request->all()));
    }

    /**
     * Change a placed order (items, drop-off, contact, time, notes), settle the difference and
     * tell the driver and the customer.
     */
    public function edit(string $id, Request $request)
    {
        try {
            $order = Order::findRecordOrFail($id, ['customer', 'transaction', 'payload.entities', 'payload.dropoff', 'payload.pickup', 'driverAssigned']);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Order not found', 404);
        }

        try {
            $result = OrderEditor::apply($order, $request->all());
        } catch (OrderEditException $e) {
            return response()->json(['errors' => [$e->getMessage()], ...$e->context], $e->status());
        }

        return [
            ...$this->orderResponse($result['order']),
            'settlement' => $result['settlement'],
            'changes'    => $result['changes'],
            'summary'    => $result['summary'],
        ];
    }

    /**
     * The order's steps on its flow: done, current and expected next.
     */
    public function flow(string $id)
    {
        try {
            $order = Order::findRecordOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Order not found', 404);
        }

        return response()->json(OrderActivityFlow::forOrder($order));
    }

    /**
     * Everything that happened to the order, newest first: its steps, notes, payment changes,
     * edits and chat messages.
     */
    public function activity(string $id)
    {
        try {
            $order = Order::findRecordOrFail($id, ['trackingStatuses', 'comments.author']);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Order not found', 404);
        }

        return response()->json(['activity' => OrderTimeline::forOrder($order)]);
    }

    /**
     * Mark one item of the order packed or unavailable, or note something about it.
     */
    public function updateEntity(string $id, string $entityId, Request $request)
    {
        try {
            $order = Order::findRecordOrFail($id, ['payload.entities']);
        } catch (ModelNotFoundException $exception) {
            return response()->error('Order not found', 404);
        }

        $entity = collect(data_get($order, 'payload.entities', []))->first(fn ($entity) => in_array($entityId, [$entity->public_id, $entity->uuid], true));
        if (!$entity) {
            return response()->error('Item not found on this order', 404);
        }

        if ($request->has('packed')) {
            $entity->setMeta('packed_at', $request->boolean('packed') ? now()->toIso8601String() : null);
        }
        if ($request->has('unavailable')) {
            $entity->setMeta('unavailable', $request->boolean('unavailable'));
        }
        if ($request->has('note')) {
            $entity->setMeta('note', trim((string) $request->input('note')) ?: null);
        }
        $entity->save();

        return $this->orderResponse($order);
    }
}
