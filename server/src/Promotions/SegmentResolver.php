<?php

namespace Fleetbase\Storefront\Promotions;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\Models\Transaction;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the customer query for a segment's rules.
 *
 * All rules are optional and combined with AND. Order rules count the customer's storefront
 * orders placed with the owning store or network (canceled orders excluded):
 *
 * - `customers`: only these customer (contact) uuids
 * - `min_orders` / `max_orders`: order count bounds (`max_orders: 0` targets customers who never ordered)
 * - `ordered_within_days`: ordered at least once in the last N days
 * - `not_ordered_within_days`: ordered before, but not in the last N days (lapsed customers)
 * - `joined_within_days`: customer created in the last N days
 * - `min_spent`: total successful storefront payments across the company, in minor units
 * - `has_push_device`: `true` for customers with a registered push device
 */
class SegmentResolver
{
    public const RULES = ['customers', 'min_orders', 'max_orders', 'ordered_within_days', 'not_ordered_within_days', 'joined_within_days', 'min_spent', 'has_push_device'];

    public function query(Store|Network $owner, array $rules = [], ?Carbon $now = null): Builder
    {
        $now    = $now ?? Carbon::now();
        $orders = function ($query) use ($owner) {
            $query->where('type', 'storefront')
                ->where('status', '!=', 'canceled')
                ->where(function ($query) use ($owner) {
                    $query->where('meta->storefront_id', $owner->public_id)
                        ->orWhere('meta->storefront_network_id', $owner->public_id);
                });
        };

        $query = Contact::query()
            ->where('company_uuid', $owner->company_uuid)
            ->where('type', 'customer');

        if (!empty($rules['customers'])) {
            $query->whereIn('uuid', (array) $rules['customers']);
        }

        if (isset($rules['min_orders']) && $rules['min_orders'] !== null) {
            $query->whereHas('customerOrders', $orders, '>=', (int) $rules['min_orders']);
        }

        if (isset($rules['max_orders']) && $rules['max_orders'] !== null) {
            $query->has('customerOrders', '<=', (int) $rules['max_orders'], 'and', $orders);
        }

        if (!empty($rules['ordered_within_days'])) {
            $since = $now->copy()->subDays((int) $rules['ordered_within_days']);
            $query->whereHas('customerOrders', function ($query) use ($orders, $since) {
                $orders($query);
                $query->where('created_at', '>=', $since);
            });
        }

        if (!empty($rules['not_ordered_within_days'])) {
            $since = $now->copy()->subDays((int) $rules['not_ordered_within_days']);
            $query->whereHas('customerOrders', $orders)
                ->whereDoesntHave('customerOrders', function ($query) use ($orders, $since) {
                    $orders($query);
                    $query->where('created_at', '>=', $since);
                });
        }

        if (!empty($rules['joined_within_days'])) {
            $query->where('created_at', '>=', $now->copy()->subDays((int) $rules['joined_within_days']));
        }

        if (!empty($rules['min_spent'])) {
            $transactions = (new Transaction())->getTable();
            $contacts     = $query->getModel()->getTable();
            $query->whereRaw(
                '(select coalesce(sum(' . $transactions . '.amount), 0) from ' . $transactions . ' where ' . $transactions . '.customer_uuid = ' . $contacts . '.uuid and ' . $transactions . ".type = 'storefront' and " . $transactions . ".status = 'success' and " . $transactions . '.deleted_at is null) >= ?',
                [(int) $rules['min_spent']]
            );
        }

        if (!empty($rules['has_push_device'])) {
            $query->whereHas('devices', fn ($query) => $query->where(fn ($query) => $query->whereNotIn('status', ['invalid', 'inactive'])->orWhereNull('status')));
        }

        return $query;
    }
}
