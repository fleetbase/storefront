<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Events\OrderChanged;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\Utils as FleetOpsUtils;
use Fleetbase\Models\User;
use Fleetbase\Storefront\Http\Controllers\v1\ServiceQuoteController;
use Fleetbase\Storefront\Http\Requests\GetServiceQuoteFromCart;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\PromotionRedemption;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Storefront\Models\StoreLocation;
use Fleetbase\Storefront\Notifications\StorefrontOrderUpdated;
use Fleetbase\Storefront\Promotions\PromotionContext;
use Fleetbase\Storefront\Promotions\PromotionEngine;
use Fleetbase\Storefront\Promotions\PromotionLine;
use Fleetbase\Support\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes a placed storefront order: its items, drop-off, contact, time and notes. Prices the
 * change the way checkout priced the order (promotions re-evaluated without counting the order's
 * own use), settles the difference, and tells the driver and the customer.
 *
 * What can change depends on how far the order is:
 * - open (created to preparing): everything;
 * - ready: address, contact, notes; items can only be removed; the time can only move earlier;
 * - with the driver: address (the driver is rerouted), contact, notes; items and time are locked;
 * - finished or canceled: nothing.
 */
class OrderEditor
{
    public const STAGE_OPEN        = 'open';
    public const STAGE_READY       = 'ready';
    public const STAGE_WITH_DRIVER = 'with_driver';
    public const STAGE_CLOSED      = 'closed';

    public const OPEN_STATUSES  = ['created', 'pending', 'accepted', 'confirmed', 'preparing'];
    public const READY_STATUSES = ['ready', 'pickup_ready', 'ready_for_pickup'];

    public const FIELDS = ['items', 'dropoff', 'contact', 'schedule', 'notes'];

    protected const SESSION_KEYS = ['storefront_key', 'storefront_store', 'storefront_store_public_id', 'storefront_network', 'storefront_network_public_id', 'storefront_currency', 'customer_id'];

    public static function stage(Order $order): string
    {
        if (OrderInsights::isFinished($order)) {
            return static::STAGE_CLOSED;
        }

        $status = (string) $order->status;
        if (in_array($status, static::OPEN_STATUSES, true)) {
            return static::STAGE_OPEN;
        }
        if (in_array($status, static::READY_STATUSES, true)) {
            return static::STAGE_READY;
        }

        return static::STAGE_WITH_DRIVER;
    }

    /**
     * What cannot change, or only one way, at the order's stage: `{ field: { rule, reason } }` with
     * rule `locked`, `removal_only` or `earlier_only`.
     */
    public static function locks(Order $order): array
    {
        $stage = static::stage($order);

        return match ($stage) {
            static::STAGE_CLOSED      => collect(static::FIELDS)->mapWithKeys(fn ($field) => [$field => ['rule' => 'locked', 'reason' => 'The order is ' . str_replace('_', ' ', (string) $order->status) . '.']])->all(),
            static::STAGE_WITH_DRIVER => [
                'items'    => ['rule' => 'locked', 'reason' => 'The driver has the order.'],
                'schedule' => ['rule' => 'locked', 'reason' => 'The driver has the order.'],
            ],
            static::STAGE_READY => [
                'items'    => ['rule' => 'removal_only', 'reason' => 'The order is ready; items can only be removed.'],
                'schedule' => ['rule' => 'earlier_only', 'reason' => 'The order is ready; it can only be moved earlier.'],
            ],
            default => [],
        };
    }

    /**
     * Today's opening windows of the store location the order is made at (else the store's first
     * location), in the store's time zone, for the edit form's "When".
     *
     * @return array{timezone: string, windows: array<int, array{opens: string, closes: string, start: string, end: string}>}
     */
    public static function hoursToday(Order $order): array
    {
        $store    = Store::where('public_id', $order->getMeta('storefront_id'))->first();
        $timezone = BookingSlots::timezone($store);
        $today    = Carbon::now($timezone)->startOfDay();
        $weekday  = strtolower($today->englishDayOfWeek);

        $location = null;
        if ($store) {
            $pickupUuid = data_get($order, 'payload.pickup_uuid');
            $location   = ($pickupUuid ? StoreLocation::where('store_uuid', $store->uuid)->where('place_uuid', $pickupUuid)->with('hours')->first() : null)
                ?? StoreLocation::where('store_uuid', $store->uuid)->with('hours')->orderBy('created_at')->first();
        }

        $windows = collect($location?->hours ?? [])
            ->filter(fn ($hour) => strtolower((string) data_get($hour, 'day_of_week')) === $weekday)
            ->map(function ($hour) use ($today) {
                $open  = $today->copy()->setTimeFromTimeString((string) data_get($hour, 'start'));
                $close = $today->copy()->setTimeFromTimeString((string) data_get($hour, 'end'));

                // The wall-clock times as the store set them, and the moments they fall on.
                return $close->gt($open) ? ['opens' => $open->format('H:i'), 'closes' => $close->format('H:i'), 'start' => $open->toIso8601String(), 'end' => $close->toIso8601String()] : null;
            })
            ->filter()
            ->sortBy('start')
            ->values()
            ->all();

        return ['timezone' => $timezone, 'windows' => $windows];
    }

    /**
     * Price the edit without changing anything.
     */
    public static function preview(Order $order, array $input): array
    {
        $plan = static::plan($order, $input);

        return [
            'order'      => $plan['current'],
            'proposed'   => $plan['proposed'],
            'difference' => $plan['difference'],
            'settlement' => OrderSettlement::plan($order, $plan['difference']),
            'changes'    => $plan['changes'],
            'summary'    => $plan['summary'],
            'stage'      => static::stage($order),
            'locked'     => static::locks($order),
            'violations' => $plan['violations'],
            'warnings'   => $plan['warnings'],
        ];
    }

    /**
     * Apply the edit: settle the difference first (a gateway that refuses leaves the order as it
     * was), then change the order, then tell everyone.
     *
     * @throws OrderEditException
     */
    public static function apply(Order $order, array $input): array
    {
        $plan = static::plan($order, $input, true);

        if ($plan['violations']) {
            throw new OrderEditException(implode(' ', array_values($plan['violations'])), 422, ['violations' => $plan['violations']]);
        }
        if (!$plan['changes']) {
            throw new OrderEditException('Nothing changed.', 422);
        }

        $confirmed = data_get($input, 'confirm_total');
        if ($confirmed !== null && (int) $confirmed !== $plan['proposed']['total']) {
            throw new OrderEditException('The new total is ' . $plan['proposed']['total'] . ', not ' . (int) $confirmed . '. Review the change and confirm again.', 409, ['proposed' => $plan['proposed']]);
        }

        OrderPayments::rememberAmountPaid($order);
        $settlement = static::settle($order, $plan['difference'], data_get($input, 'settlement'), $plan['summary']);

        DB::transaction(function () use ($order, $plan, $input) {
            if (in_array('items', $plan['changes'], true)) {
                $before = static::quantitiesByProduct(static::currentLines($order));
                static::writeItems($order, $plan['lines']);
                $after  = static::quantitiesByProduct($plan['lines']);
                $deltas = [];
                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $productId) {
                    $deltas[$productId] = ($after[$productId] ?? 0) - ($before[$productId] ?? 0);
                }
                Inventory::applyEdit($order, $deltas);
            }
            if (in_array('dropoff', $plan['changes'], true)) {
                $order->payload->setDropoff($plan['dropoff'], ['save' => true]);
            }
            if (in_array('contact', $plan['changes'], true)) {
                static::writeContact($order, (array) data_get($input, 'contact', []));
            }
            if (in_array('schedule', $plan['changes'], true)) {
                if ($order->isMeta('is_booking')) {
                    static::moveBooking($order, $plan['scheduled_at']);
                } else {
                    $order->scheduled_at = $plan['scheduled_at'];
                }
            }
            if (in_array('notes', $plan['changes'], true)) {
                $order->notes = $plan['notes'];
            }

            $proposed = $plan['proposed'];
            $order->setMeta([
                'subtotal'     => $proposed['subtotal'],
                'delivery_fee' => $proposed['delivery_fee'],
                'discount'     => $proposed['discount'],
                'promotions'   => $proposed['promotions'],
                'total'        => $proposed['total'],
            ]);
            if (in_array('items', $plan['changes'], true) && (string) $order->status === 'preparing') {
                $order->setMeta('kitchen_changed_at', now()->toIso8601String());
            }

            $request = $order->getMeta('address_change_request');
            if (in_array('dropoff', $plan['changes'], true) && is_array($request) && ($request['status'] ?? null) === 'open') {
                $order->setMeta('address_change_request', [...$request, 'status' => 'resolved', 'closed_at' => now()->toIso8601String()]);
            }

            $edits   = $order->getMeta('edits', []);
            $edits   = is_array($edits) ? $edits : [];
            $edits[] = [
                'at'         => now()->toIso8601String(),
                'by'         => session('user'),
                'by_name'    => static::actorName(),
                'summary'    => $plan['summary'],
                'changes'    => $plan['changes'],
                'difference' => $plan['difference'],
            ];
            $order->setMeta('edits', $edits);
            $order->save();

            static::updateRedemptions($order, $plan['promotion_result']);
        });

        $order->refresh();
        $order->load(['payload.entities', 'payload.dropoff', 'customer', 'driverAssigned']);
        OrderPayments::snapshot($order);

        static::announce($order, $plan, (bool) data_get($input, 'notify_customer', true), (bool) data_get($input, 'notify_driver', true));

        return ['order' => $order, 'settlement' => $settlement, 'changes' => $plan['changes'], 'summary' => $plan['summary']];
    }

    /**
     * Work out the edit: what changes, the new totals, and what the order's stage does not allow.
     */
    protected static function plan(Order $order, array $input, bool $persist = false): array
    {
        $order->loadMissing(['payload.entities', 'payload.dropoff', 'payload.pickup', 'customer']);

        $store      = Store::where('public_id', $order->getMeta('storefront_id'))->first();
        $isPickup   = (bool) $order->isMeta('is_pickup');
        $locks      = static::locks($order);
        $changes    = [];
        $summary    = [];
        $violations = [];
        $warnings   = [];

        $current = [
            'subtotal'     => (int) $order->getMeta('subtotal', 0),
            'delivery_fee' => (int) $order->getMeta('delivery_fee', 0),
            'discount'     => (int) $order->getMeta('discount', 0),
            'tip'          => static::tipAmount($order->getMeta('tip'), (int) $order->getMeta('subtotal', 0)),
            'delivery_tip' => $isPickup ? 0 : static::tipAmount($order->getMeta('delivery_tip'), (int) $order->getMeta('subtotal', 0)),
            'total'        => (int) $order->getMeta('total', 0),
            'cod_amount'   => (int) data_get($order, 'payload.cod_amount', 0),
            'currency'     => $order->getMeta('currency'),
        ];

        // Items
        $lines = static::currentLines($order);
        if (array_key_exists('items', $input) && is_array($input['items'])) {
            [$lines, $itemChanges, $itemViolations] = static::proposedLines($order, $store, $input['items'], $locks['items']['rule'] ?? null);
            if ($itemChanges) {
                $changes[] = 'items';
                $summary   = [...$summary, ...$itemChanges];
            }
            $violations = [...$violations, ...$itemViolations];
            if ($itemChanges && ($locks['items']['rule'] ?? null) === 'locked') {
                $violations['items'] = $locks['items']['reason'];
            }
        }
        if (!$lines) {
            $violations['items'] = 'An order needs at least one item; cancel it instead.';
        }

        // Drop-off
        $dropoff = null;
        if (!empty($input['dropoff']) && !$isPickup) {
            $dropoff = static::resolveDropoff($order, (array) $input['dropoff'], (bool) data_get($input, 'save_place_to_customer', false), $persist);
            if (!$dropoff) {
                $violations['dropoff'] = 'The new address could not be found.';
            } elseif ($dropoff->uuid !== data_get($order, 'payload.dropoff_uuid')) {
                $changes[] = 'dropoff';
                $summary[] = 'Drop-off changed to ' . ($dropoff->address ?? $dropoff->street1 ?? $dropoff->name);
                if (isset($locks['dropoff'])) {
                    $violations['dropoff'] = $locks['dropoff']['reason'];
                }
            }
        }

        // Contact
        $contact = array_filter((array) data_get($input, 'contact', []), fn ($value) => $value !== null && $value !== '');
        if (array_intersect_key($contact, array_flip(['name', 'phone', 'email']))) {
            $changes[] = 'contact';
            $summary[] = 'Contact details updated';
            if (isset($locks['contact'])) {
                $violations['contact'] = $locks['contact']['reason'];
            }
        }

        // Time. A booking keeps its time in `meta.booking` (not `scheduled_at`, which Fleet-Ops
        // dispatches on its own), and moves only to a free slot of its service.
        $scheduledAt = null;
        $isBooking   = (bool) $order->isMeta('is_booking');
        if (array_key_exists('scheduled_at', $input)) {
            $scheduledAt = $input['scheduled_at'] ? Carbon::parse($input['scheduled_at']) : null;
            $wasAt       = $isBooking ? (data_get($order->meta, 'booking.start') ?? data_get($order->meta, 'booking_at')) : $order->scheduled_at;
            $was         = $wasAt ? Carbon::parse($wasAt) : null;
            if (optional($scheduledAt)->toIso8601String() !== optional($was)->toIso8601String()) {
                $changes[] = 'schedule';
                // In the store's time, as the store and the customer read it.
                $summary[] = $scheduledAt ? ($isBooking ? 'Booking moved to ' : 'Time moved to ') . $scheduledAt->copy()->setTimezone(BookingSlots::timezone($store))->format('D j M, H:i') : 'Time cleared (as soon as possible)';
                if ($isBooking) {
                    $service = static::bookingService($order);
                    if (!$scheduledAt) {
                        $violations['schedule'] = 'A booking needs a time.';
                    } elseif ($service && !BookingSlots::isAvailable($service, $scheduledAt)) {
                        $violations['schedule'] = 'That time is not free for ' . $service->name . '.';
                    }
                }
                $rule      = $locks['schedule']['rule'] ?? null;
                if ($rule === 'locked' || ($rule === 'earlier_only' && (!$scheduledAt || ($was && $scheduledAt->greaterThan($was))))) {
                    $violations['schedule'] = $locks['schedule']['reason'];
                }
            }
        }

        // Notes
        $notes = null;
        if (array_key_exists('notes', $input) && trim((string) $input['notes']) !== trim((string) $order->notes)) {
            $notes     = trim((string) $input['notes']) ?: null;
            $changes[] = 'notes';
            $summary[] = 'Notes updated';
            if (isset($locks['notes'])) {
                $violations['notes'] = $locks['notes']['reason'];
            }
        }

        // Prices: only items and the drop-off change them; other edits keep the order's prices.
        $reprice     = (bool) array_intersect(['items', 'dropoff'], $changes);
        $subtotal    = $reprice ? array_sum(array_column($lines, 'subtotal')) : $current['subtotal'];
        $deliveryFee = $current['delivery_fee'];
        if ($dropoff && in_array('dropoff', $changes, true)) {
            [$quoted, $quoteError] = $dropoff->exists ? static::quoteDeliveryFee($order, $store, $dropoff, $lines) : static::quoteDraftDeliveryFee($order, $store, $dropoff, $lines);
            if ($quoted !== null) {
                $deliveryFee = $quoted;
            } else {
                $warnings['delivery_fee'] = ($quoteError ?: 'No delivery rate covers the new address.') . ' The delivery fee is unchanged.';
            }
        }

        $promotionResult = $reprice ? static::promotions($order, $store, $lines, $isPickup, $deliveryFee) : null;
        $discount        = $promotionResult ? $promotionResult->discount() : $current['discount'];
        $tip             = static::tipAmount($order->getMeta('tip'), $subtotal);
        $deliveryTip     = $isPickup ? 0 : static::tipAmount($order->getMeta('delivery_tip'), $subtotal);
        $total           = $reprice ? max(0, $subtotal + ($isPickup ? 0 : $deliveryFee) + $tip + $deliveryTip - $discount) : $current['total'];
        $difference      = $total - $current['total'];

        if ($difference !== 0) {
            $changes[] = 'total';
            $payment   = OrderPayments::summary($order);
            if ($payment['state'] === OrderPayments::STATE_TO_COLLECT) {
                $changes[] = 'cod_amount';
            }
        }

        $proposed = [
            'subtotal'     => $subtotal,
            'delivery_fee' => $isPickup ? 0 : $deliveryFee,
            'discount'     => $discount,
            'promotions'   => $promotionResult ? $promotionResult->toPublicArray()['applied'] : $order->getMeta('promotions', []),
            // Codes the order used that the new items no longer qualify for, said before Save.
            'promotions_lost' => $promotionResult ? array_values(array_diff(
                collect($order->getMeta('promotions', []))->pluck('code')->filter()->all(),
                collect($promotionResult->toPublicArray()['applied'])->pluck('code')->filter()->all()
            )) : [],
            'tip'          => $tip,
            'delivery_tip' => $deliveryTip,
            'total'        => $total,
            'cod_amount'   => in_array('cod_amount', $changes, true) ? max(0, $current['cod_amount'] + $difference) : $current['cod_amount'],
            'currency'     => $current['currency'],
            'items'        => array_map(fn ($line) => Arr::except($line, ['product_model', 'entity_model']), $lines),
        ];

        return [
            'current'          => $current,
            'proposed'         => $proposed,
            'difference'       => $difference,
            'changes'          => array_values(array_unique($changes)),
            'summary'          => implode(' · ', $summary),
            'violations'       => $violations,
            'warnings'         => $warnings,
            'lines'            => $lines,
            'dropoff'          => $dropoff,
            'scheduled_at'     => $scheduledAt,
            'notes'            => $notes,
            'promotion_result' => $promotionResult,
        ];
    }

    /**
     * The order's items as edit lines.
     */
    protected static function currentLines(Order $order): array
    {
        return collect(data_get($order, 'payload.entities', []))
            ->filter(fn ($entity) => $entity instanceof Entity)
            ->map(fn (Entity $entity) => [
                'entity'        => $entity->public_id,
                'product'       => $entity->getMeta('product_id'),
                'name'          => $entity->name,
                'quantity'      => max(1, (int) $entity->getMeta('quantity', 1)),
                'variants'      => (array) $entity->getMeta('variants', []),
                'addons'        => (array) $entity->getMeta('addons', []),
                'note'          => $entity->getMeta('note'),
                'subtotal'      => (int) Utils::numbersOnly($entity->getMeta('subtotal', 0)),
                'substitutes'   => null,
                'entity_model'  => $entity,
                'product_model' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * The lines the edit asks for, priced, with a description of each change and any change the
     * stage does not allow (`removal_only`: lower quantities and removals only).
     *
     * @return array{0: array, 1: string[], 2: array}
     */
    protected static function proposedLines(Order $order, ?Store $store, array $items, ?string $rule): array
    {
        $current    = collect(static::currentLines($order))->keyBy('entity');
        $lines      = [];
        $changes    = [];
        $violations = [];
        $kept       = [];

        foreach ($items as $item) {
            $entityId = data_get($item, 'entity');
            $existing = $entityId ? $current->get($entityId) : null;
            $quantity = (int) data_get($item, 'quantity', $existing['quantity'] ?? 1);
            if ($quantity <= 0) {
                continue;
            }

            $productId = data_get($item, 'product', $existing['product'] ?? null);
            $product   = $productId ? Product::where('public_id', $productId)->when($store, fn ($query) => $query->where('store_uuid', $store->uuid))->first() : null;
            if (!$product) {
                $violations['items'] = 'A product on the order is no longer sold by this store.';
                continue;
            }

            $variants = array_key_exists('variants', (array) $item) ? array_values(array_filter((array) $item['variants'])) : ($existing['variants'] ?? []);
            $addons   = array_key_exists('addons', (array) $item) ? array_values(array_filter((array) $item['addons'])) : ($existing['addons'] ?? []);
            $line     = [
                'entity'        => $existing ? $entityId : null,
                'product'       => $product->public_id,
                'name'          => $product->name,
                'quantity'      => $quantity,
                'variants'      => $variants,
                'addons'        => $addons,
                'note'          => array_key_exists('note', (array) $item) ? (trim((string) $item['note']) ?: null) : ($existing['note'] ?? null),
                'subtotal'      => $existing && $quantity === $existing['quantity'] && $variants == $existing['variants'] && $addons == $existing['addons']
                    ? $existing['subtotal']
                    : Cart::calculateProductSubtotal($product, $quantity, $variants, $addons),
                'substitutes'   => data_get($item, 'substitutes'),
                'entity_model'  => $existing['entity_model'] ?? null,
                'product_model' => $product,
            ];
            $lines[] = $line;

            if ($existing) {
                $kept[] = $entityId;
                if ($quantity !== $existing['quantity']) {
                    $changes[] = $product->name . ' ' . $existing['quantity'] . '→' . $quantity;
                    if ($rule === 'removal_only' && $quantity > $existing['quantity']) {
                        $violations['items'] = 'The order is ready; items can only be removed.';
                    }
                }
                if ($variants != $existing['variants'] || $addons != $existing['addons']) {
                    $changes[] = $product->name . ' options changed';
                    if ($rule === 'removal_only') {
                        $violations['items'] = 'The order is ready; items can only be removed.';
                    }
                }
                if ($line['note'] !== $existing['note']) {
                    $changes[] = $product->name . ' note updated';
                }
            } else {
                $substitute = $line['substitutes'] ? $current->get($line['substitutes']) : null;
                $changes[]  = $substitute ? $substitute['name'] . ' replaced with ' . $product->name . ' ×' . $quantity : 'Added ' . $product->name . ' ×' . $quantity;
                if ($rule === 'removal_only') {
                    $violations['items'] = 'The order is ready; items can only be removed.';
                }
            }
        }

        $substituted = array_filter(array_column($lines, 'substitutes'));
        foreach ($current as $entityId => $existing) {
            if (!in_array($entityId, $kept, true) && !in_array($entityId, $substituted, true)) {
                $changes[] = 'Removed ' . $existing['name'];
            }
        }

        return [$lines, $changes, $violations];
    }

    /**
     * Re-evaluate the order's promotions on the new lines, without counting the order's own use.
     * Null when the order had no promotions and none apply automatically.
     */
    protected static function promotions(Order $order, ?Store $store, array $lines, bool $isPickup, int $deliveryFee): ?\Fleetbase\Storefront\Promotions\PromotionResult
    {
        if (!$store) {
            return null;
        }

        $promotionLines = array_map(fn ($line, $index) => new PromotionLine(
            (string) ($line['entity'] ?? 'line_' . $index),
            (int) $line['subtotal'],
            (int) $line['quantity'],
            $line['product'],
            $line['product_model']?->uuid ?? Product::where('public_id', $line['product'])->value('uuid'),
            $line['product_model']?->category_uuid ?? Product::where('public_id', $line['product'])->value('category_uuid'),
            $store->public_id,
            $store->uuid,
        ), $lines, array_keys($lines));

        $context                   = new PromotionContext($store, $promotionLines, $order->getMeta('currency') ?? $store->currency, $order->customer, $isPickup, $isPickup ? 0 : $deliveryFee);
        $context->excludeOrderUuid = $order->uuid;
        $codes                     = collect($order->getMeta('promotions', []))->pluck('code')->filter()->values()->all();

        try {
            return app(PromotionEngine::class)->evaluate($context, $codes);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Settle the price difference: lower the cash to collect, refund or credit, or ask for more.
     */
    protected static function settle(Order $order, int $difference, ?string $method, string $reason): ?array
    {
        if ($difference === 0) {
            return null;
        }

        $plan   = OrderSettlement::plan($order, $difference);
        $method = $method && in_array($method, $plan['options'], true) ? $method : $plan['action'];

        try {
            if ($method === 'cod_update') {
                return OrderSettlement::updateCashToCollect($order, $difference, $reason);
            }

            return $difference < 0
                ? OrderSettlement::refund($order, abs($difference), $method, $reason)
                : OrderSettlement::charge($order, $difference, $method, $reason);
        } catch (\Throwable $e) {
            throw new OrderEditException($e->getMessage(), 422);
        }
    }

    /**
     * Make the order's items match the lines: update kept ones, add new ones, remove the rest.
     */
    protected static function writeItems(Order $order, array $lines): void
    {
        $payload = $order->payload;
        $keep    = [];

        foreach ($lines as $line) {
            $meta = [
                'variants' => $line['variants'],
                'addons'   => $line['addons'],
                'subtotal' => $line['subtotal'],
                'quantity' => $line['quantity'],
                'note'     => $line['note'],
            ];

            if ($line['entity_model'] instanceof Entity) {
                $entity = $line['entity_model'];
                $entity->setMeta($meta);
                $entity->save();
                $keep[] = $entity->uuid;
                continue;
            }

            $substitute = $line['substitutes'] ? collect($payload->entities)->first(fn ($entity) => $entity->public_id === $line['substitutes']) : null;
            $entity     = Entity::fromStorefrontProduct($line['product_model'], [
                ...$meta,
                'substitute_for' => $substitute?->public_id,
                'added_by_edit'  => true,
            ])->fill([
                'company_uuid'  => $order->company_uuid,
                'payload_uuid'  => $payload->uuid,
                'customer_uuid' => $order->customer_uuid,
                'customer_type' => $order->customer_type,
            ]);
            $entity->save();
            $keep[] = $entity->uuid;
        }

        Entity::where('payload_uuid', $payload->uuid)->whereNotIn('uuid', $keep)->get()->each(fn ($entity) => $entity->delete());
        $payload->unsetRelation('entities');
    }

    /**
     * @return array<string, int> product public id => quantity on the order
     */
    protected static function quantitiesByProduct(array $lines): array
    {
        $quantities = [];
        foreach ($lines as $line) {
            if (!empty($line['product'])) {
                $quantities[$line['product']] = ($quantities[$line['product']] ?? 0) + (int) $line['quantity'];
            }
        }

        return $quantities;
    }

    /**
     * New contact details: on the customer's profile, or just for this order.
     */
    protected static function writeContact(Order $order, array $contact): void
    {
        $fields = array_filter(Arr::only($contact, ['name', 'phone', 'email']), fn ($value) => $value !== null && $value !== '');
        if (!$fields) {
            return;
        }

        if (($contact['scope'] ?? 'order') === 'profile' && $order->customer) {
            $order->customer->update($fields);

            return;
        }

        $order->setMeta('contact', array_merge((array) $order->getMeta('contact', []), $fields));
    }

    /**
     * The new drop-off: a saved place (`{ place }`) or an address, optionally saved to the customer.
     * Pricing an edit (`$persist` false) builds the address without saving it, so a preview never
     * leaves places behind.
     */
    protected static function resolveDropoff(Order $order, array $input, bool $saveToCustomer, bool $persist = true): ?Place
    {
        if (!empty($input['place'])) {
            return Place::where('company_uuid', $order->company_uuid)
                ->where(fn ($query) => $query->where('public_id', $input['place'])->orWhere('uuid', $input['place']))
                ->first();
        }

        $attributes = Arr::only($input, ['name', 'street1', 'street2', 'city', 'province', 'postal_code', 'neighborhood', 'district', 'building', 'security_access_code', 'country', 'phone', 'location', 'meta']);
        if (!empty($input['instructions'])) {
            $attributes['meta'] = [...(array) ($attributes['meta'] ?? []), 'instructions' => $input['instructions']];
        }
        if (empty($attributes['street1']) && empty($attributes['location'])) {
            return null;
        }

        if (!$persist) {
            $draft = new Place();
            $draft->fill(Arr::except($attributes, ['location']));
            $location = !empty($attributes['location']) ? FleetOpsUtils::getPointFromMixed($attributes['location']) : null;
            if ($location) {
                $draft->setAttribute('location', $location);
            }

            return $draft;
        }

        $place = Place::createFromMixed($attributes, ['company_uuid' => $order->company_uuid]);

        // createFromMixed() does not apply the attributes for an address array: the place is the
        // order's company's, or the quote (which finds places by company) cannot see it.
        $place?->forceFill([
            'company_uuid' => $place->company_uuid ?: $order->company_uuid,
            ...($saveToCustomer && $order->customer ? ['owner_uuid' => $order->customer->uuid, 'owner_type' => Utils::getMutationType('fleet-ops:contact')] : []),
        ]);
        if ($place?->isDirty()) {
            $place->save();
        }

        return $place;
    }

    /**
     * A delivery quote to an address a preview has not saved: the quote reads the drop-off by its
     * id, so the address is saved for the quote and everything is rolled back after, leaving no
     * place (or quote) behind. The edit itself saves the address and quotes it the same way.
     *
     * @return array{0: ?int, 1: ?string}
     */
    protected static function quoteDraftDeliveryFee(Order $order, ?Store $store, Place $draft, array $lines): array
    {
        $connection = $draft->getConnection();
        $connection->beginTransaction();

        try {
            $draft->forceFill(['company_uuid' => $order->company_uuid])->save();

            return static::quoteDeliveryFee($order, $store, $draft, $lines);
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * A delivery quote to the new drop-off through the store's own quote logic.
     *
     * @return array{0: ?int, 1: ?string}
     */
    protected static function quoteDeliveryFee(Order $order, ?Store $store, Place $dropoff, array $lines): array
    {
        $origin = data_get($order, 'payload.pickup');
        if (!$store || !$origin) {
            return [null, 'The order has no pickup to quote from.'];
        }

        session([
            'storefront_key'               => $store->key,
            'storefront_store'             => $store->uuid,
            'storefront_store_public_id'   => $store->public_id,
            'storefront_network'           => null,
            'storefront_network_public_id' => null,
            'storefront_currency'          => $store->currency,
            'customer_id'                  => $order->customer_uuid,
        ]);

        try {
            $cart = Cart::newCart('edit_' . Str::lower(Str::random(16)));
            $cart->update(['currency' => $store->currency]);
            foreach ($lines as $line) {
                $cart->add($line['product'], $line['quantity'], $line['variants'], $line['addons']);
            }

            $request = GetServiceQuoteFromCart::createFrom(Request::create('/', 'GET', [
                'origin'      => $origin->public_id,
                'destination' => $dropoff->public_id,
                'cart'        => $cart->public_id,
            ]), new GetServiceQuoteFromCart());
            $request->setContainer(app())->setRedirector(app('redirect'));
            $request->validateResolved();

            $response = app(ServiceQuoteController::class)->fromCart($request);
            $cart->delete();
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        } finally {
            session()->forget(static::SESSION_KEYS);
        }

        if ($response instanceof JsonResponse) {
            return [null, data_get($response->getData(true), 'errors.0', data_get($response->getData(true), 'error'))];
        }

        $quote = $response->resource ?? null;
        if ($quote instanceof \Illuminate\Support\Collection) {
            $quote = $quote->first();
        }

        return $quote instanceof ServiceQuote ? [(int) Utils::numbersOnly($quote->amount), null] : [null, null];
    }

    /**
     * Keep the order's promotion uses in step with the new prices; a promotion that no longer
     * applies is released.
     */
    protected static function updateRedemptions(Order $order, $result): void
    {
        if (!$result) {
            return;
        }

        $applied = collect($result->applied)->keyBy('promotion_uuid');
        PromotionRedemption::where('order_uuid', $order->uuid)
            ->where('status', PromotionRedemption::STATUS_REDEEMED)
            ->get()
            ->each(function (PromotionRedemption $redemption) use ($applied) {
                $entry = $applied->get($redemption->promotion_uuid);
                $entry
                    ? $redemption->update(['amount' => (int) ($entry['amount'] ?? 0) + (int) ($entry['delivery_amount'] ?? 0)])
                    : $redemption->update(['status' => PromotionRedemption::STATUS_RELEASED]);
            });
    }

    /**
     * Broadcast `order.changed` (Fleet-Ops pushes it to the driver) and tell the customer.
     */
    protected static function announce(Order $order, array $plan, bool $notifyCustomer, bool $notifyDriver): void
    {
        if (class_exists(OrderChanged::class) && method_exists(OrderChanged::class, 'dispatchFor')) {
            OrderChanged::dispatchFor($order, $plan['changes'], $plan['summary'], [
                'total'      => $plan['proposed']['total'],
                'currency'   => $plan['proposed']['currency'],
                'changed_by' => static::actorName() ? static::actorName() . ' (store)' : null,
            ], $notifyDriver);
        }

        if ($notifyCustomer && $order->customer) {
            try {
                $order->customer->notify(new StorefrontOrderUpdated($order, $plan['summary'], $plan['changes']));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The service a booking books: the product of its line with a time, else its first bookable one.
     */
    protected static function bookingService(Order $order): ?Product
    {
        $entities = collect(data_get($order, 'payload.entities', []));
        $line     = $entities->first(fn ($entity) => data_get($entity, 'meta.scheduled_at')) ?? $entities->first();
        $ids      = $entities->map(fn ($entity) => data_get($entity, 'meta.product_id'))->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return null;
        }

        $products = Product::whereIn('public_id', $ids)->with(['hours', 'store'])->get()->keyBy('public_id');

        return $products->get(data_get($line, 'meta.product_id')) ?? $products->first(fn ($product) => $product->is_bookable);
    }

    /**
     * Move a booking to a new start: its `meta.booking`, `booking_at` and its service lines' time.
     */
    protected static function moveBooking(Order $order, Carbon $start): void
    {
        $service  = static::bookingService($order);
        $duration = (int) (data_get($order->meta, 'booking.duration_minutes') ?: ($service ? BookingSlots::service($service)['duration_minutes'] : BookingSlots::DEFAULT_DURATION));

        $order->setMeta([
            'booking_at' => $start->toIso8601String(),
            'booking'    => ['start' => $start->toIso8601String(), 'end' => $start->copy()->addMinutes($duration)->toIso8601String(), 'duration_minutes' => $duration],
        ]);

        foreach (data_get($order, 'payload.entities', []) as $entity) {
            if (data_get($entity, 'meta.scheduled_at')) {
                $entity->setMeta('scheduled_at', $start->toIso8601String());
                $entity->save();
            }
        }
    }

    protected static function tipAmount($tip, int $subtotal): int
    {
        if (!$tip) {
            return 0;
        }

        if (is_string($tip) && Str::endsWith($tip, '%')) {
            return (int) Utils::calculatePercentage((float) str_replace(',', '', Str::beforeLast($tip, '%')), $subtotal);
        }

        return (int) Utils::numbersOnly($tip);
    }

    protected static function actorName(): ?string
    {
        $uuid = session('user');

        return $uuid ? User::where('uuid', $uuid)->value('name') : null;
    }
}
