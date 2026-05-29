<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Models\Cart;
use Fleetbase\Storefront\Models\Checkout;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    private const CANCELED_STATUSES = ['canceled', 'order_canceled'];

    public function overview(Request $request)
    {
        [$start, $end, $previousStart, $previousEnd] = $this->dateRanges($request);
        $store                                       = $this->resolveStore($request);
        $companyUuid                                 = $this->companyUuid($request);

        $currentOrders            = $this->orders($companyUuid, $start, $end, $store)->get();
        $previousOrders           = $this->orders($companyUuid, $previousStart, $previousEnd, $store)->get();
        $currency                 = $store->currency ?? data_get($currentOrders->first(), 'meta.currency', 'USD');
        $currentRevenue           = $this->sumOrderRevenue($currentOrders);
        $previousRevenue          = $this->sumOrderRevenue($previousOrders);
        $currentOrderCount        = $currentOrders->whereNotIn('status', self::CANCELED_STATUSES)->count();
        $previousOrderCount       = $previousOrders->whereNotIn('status', self::CANCELED_STATUSES)->count();
        $completedOrders          = $currentOrders->where('status', 'completed')->count();
        $activeOrders             = $currentOrders->whereNotIn('status', array_merge(self::CANCELED_STATUSES, ['completed']))->count();
        $currentCustomers         = $currentOrders->whereNotNull('customer_uuid')->pluck('customer_uuid')->unique()->count();
        $previousCustomers        = $previousOrders->whereNotNull('customer_uuid')->pluck('customer_uuid')->unique()->count();
        $currentAov               = $currentOrderCount > 0 ? round($currentRevenue / $currentOrderCount, 2) : 0;
        $previousAov              = $previousOrderCount > 0 ? round($previousRevenue / $previousOrderCount, 2) : 0;
        $canceledOrders           = $currentOrders->whereIn('status', self::CANCELED_STATUSES)->count();
        $previousCanceledOrders   = $previousOrders->whereIn('status', self::CANCELED_STATUSES)->count();
        $cancellationRate         = $currentOrders->count() > 0 ? round(($canceledOrders / $currentOrders->count()) * 100, 2) : 0;
        $previousCancellationRate = $previousOrders->count() > 0 ? round(($previousCanceledOrders / $previousOrders->count()) * 100, 2) : 0;
        $cartConversion           = $this->cartConversion($companyUuid, $start, $end, $store, $currentOrderCount);
        $previousCartConversion   = $this->cartConversion($companyUuid, $previousStart, $previousEnd, $store, $previousOrderCount);

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'currency' => $currency,
            'metrics'  => [
                'revenue'             => $this->metric($currentRevenue, $previousRevenue, 'money', $currency),
                'orders'              => $this->metric($currentOrderCount, $previousOrderCount),
                'average_order_value' => $this->metric($currentAov, $previousAov, 'money', $currency),
                'active_orders'       => $this->metric($activeOrders, $previousOrders->whereNotIn('status', array_merge(self::CANCELED_STATUSES, ['completed']))->count()),
                'completed_orders'    => $this->metric($completedOrders, $previousOrders->where('status', 'completed')->count()),
                'customers'           => $this->metric($currentCustomers, $previousCustomers),
                'stores'              => $this->metric(Store::where('company_uuid', $companyUuid)->count(), Store::where('company_uuid', $companyUuid)->count()),
                'products'            => $this->metric($this->productCount($companyUuid, $store), $this->productCount($companyUuid, $store)),
                'cart_conversion'     => $this->metric($cartConversion, $previousCartConversion, 'percent'),
                'cancellation_rate'   => $this->metric($cancellationRate, $previousCancellationRate, 'percent', null, true),
            ],
        ]);
    }

    public function revenueTrend(Request $request)
    {
        [$start, $end] = $this->dateRanges($request);
        $store         = $this->resolveStore($request);
        $companyUuid   = $this->companyUuid($request);
        $orders        = $this->orders($companyUuid, $start, $end, $store)->get();
        $days          = $this->days($start, $end);
        $revenue       = [];
        $counts        = [];

        foreach ($days as $date) {
            $ordersForDay = $orders->filter(function ($order) use ($date) {
                return Carbon::parse($order->created_at)->toDateString() === $date;
            });
            $revenue[] = $this->sumOrderRevenue($ordersForDay);
            $counts[]  = $ordersForDay->whereNotIn('status', self::CANCELED_STATUSES)->count();
        }

        return response()->json([
            'labels'   => $days,
            'datasets' => [
                [
                    'label'           => 'Revenue',
                    'data'            => $revenue,
                    'borderColor'     => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.16)',
                    'fill'            => true,
                    'tension'         => 0.35,
                    'yAxisID'         => 'y',
                ],
                [
                    'label'           => 'Orders',
                    'data'            => $counts,
                    'borderColor'     => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.12)',
                    'fill'            => false,
                    'tension'         => 0.35,
                    'yAxisID'         => 'y1',
                ],
            ],
            'summary' => [
                'revenue'  => array_sum($revenue),
                'orders'   => array_sum($counts),
                'currency' => $store->currency ?? data_get($orders->first(), 'meta.currency', 'USD'),
            ],
        ]);
    }

    public function ordersByStatus(Request $request)
    {
        [$start, $end] = $this->dateRanges($request);
        $orders        = $this->orders($this->companyUuid($request), $start, $end, $this->resolveStore($request))->get();
        $groups        = $orders->groupBy(function ($order) {
            return $order->status ?: 'unknown';
        })->map->count()->sortDesc();

        return response()->json([
            'labels'   => $groups->keys()->values(),
            'datasets' => [
                [
                    'label'           => 'Orders',
                    'data'            => $groups->values(),
                    'backgroundColor' => ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#f43f5e', '#64748b'],
                    'borderWidth'     => 0,
                ],
            ],
            'total' => $orders->count(),
        ]);
    }

    public function topProducts(Request $request)
    {
        [$start, $end] = $this->dateRanges($request);
        $store         = $this->resolveStore($request);
        $companyUuid   = $this->companyUuid($request);
        $products      = [];

        $this->checkouts($companyUuid, $start, $end, $store)->get()->each(function ($checkout) use (&$products, $store) {
            $items = data_get($checkout, 'cart_state.items', []);
            foreach ($items as $item) {
                if ($store && data_get($item, 'store_id') !== $store->public_id) {
                    continue;
                }

                $productId = data_get($item, 'product_id');
                if (!$productId) {
                    continue;
                }

                if (!isset($products[$productId])) {
                    $products[$productId] = [
                        'id'       => $productId,
                        'name'     => data_get($item, 'name', $productId),
                        'quantity' => 0,
                        'revenue'  => 0,
                        'currency' => $checkout->currency,
                    ];
                }

                $products[$productId]['quantity'] += (int) data_get($item, 'quantity', 1);
                $products[$productId]['revenue'] += (float) data_get($item, 'subtotal', 0);
            }
        });

        $names = Product::whereIn('public_id', array_keys($products))->pluck('name', 'public_id');

        return response()->json([
            'products' => collect($products)->map(function ($product) use ($names) {
                $product['name'] = $names[$product['id']] ?? $product['name'];

                return $product;
            })->sortByDesc('revenue')->take(8)->values(),
        ]);
    }

    public function customerInsights(Request $request)
    {
        [$start, $end]       = $this->dateRanges($request);
        $store               = $this->resolveStore($request);
        $companyUuid         = $this->companyUuid($request);
        $orders              = $this->orders($companyUuid, $start, $end, $store)->whereNotNull('customer_uuid')->get();
        $customerOrderCounts = $orders->groupBy('customer_uuid')->map->count();
        $returningCustomers  = $customerOrderCounts->filter(function ($count, $customerUuid) use ($companyUuid, $start, $store) {
            return $count > 1 || $this->orders($companyUuid, null, $start->copy()->subSecond(), $store)->where('customer_uuid', $customerUuid)->exists();
        })->count();
        $totalCustomers = $customerOrderCounts->count();
        $newCustomers   = max($totalCustomers - $returningCustomers, 0);
        $repeatRate     = $totalCustomers > 0 ? round(($returningCustomers / $totalCustomers) * 100, 2) : 0;

        return response()->json([
            'new_customers'       => $newCustomers,
            'returning_customers' => $returningCustomers,
            'repeat_rate'         => $repeatRate,
            'total_customers'     => $totalCustomers,
            'known_customers'     => Contact::where(['company_uuid' => $companyUuid, 'type' => 'customer'])->count(),
        ]);
    }

    private function dateRanges(Request $request): array
    {
        $end           = $request->date('end') ? Carbon::parse($request->date('end'))->endOfDay() : Carbon::now()->endOfDay();
        $start         = $request->date('start') ? Carbon::parse($request->date('start'))->startOfDay() : $end->copy()->subDays(29)->startOfDay();
        $seconds       = max($start->diffInSeconds($end), 1);
        $previousEnd   = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subSeconds($seconds);

        return [$start, $end, $previousStart, $previousEnd];
    }

    private function companyUuid(Request $request): ?string
    {
        return session('company') ?? data_get($request->user(), 'company_uuid') ?? data_get($request->user(), 'company.uuid');
    }

    private function resolveStore(Request $request): ?Store
    {
        $store = $request->input('store') ?? $request->input('storefront');
        if (!$store) {
            return null;
        }

        return Store::where('uuid', $store)->orWhere('public_id', $store)->first();
    }

    private function orders(?string $companyUuid, ?Carbon $start = null, ?Carbon $end = null, ?Store $store = null)
    {
        $query = Order::where(['company_uuid' => $companyUuid, 'type' => 'storefront'])->whereNull('deleted_at');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
        }

        if ($store) {
            $query->where('meta->storefront_id', $store->public_id);
        }

        return $query;
    }

    private function checkouts(?string $companyUuid, Carbon $start, Carbon $end, ?Store $store = null)
    {
        $query = Checkout::where('company_uuid', $companyUuid)
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($query) {
                $query->whereNotNull('order_uuid')->orWhere('captured', true);
            });

        if ($store) {
            $query->where(function ($query) use ($store) {
                $query->where('store_uuid', $store->uuid)->orWhere('cart_state->checkout_store_id', $store->public_id);
            });
        }

        return $query;
    }

    private function sumOrderRevenue(Collection $orders): float
    {
        return round($orders->whereNotIn('status', self::CANCELED_STATUSES)->sum(function ($order) {
            return (float) data_get($order, 'meta.total', 0);
        }), 2);
    }

    private function productCount(?string $companyUuid, ?Store $store = null): int
    {
        $query = Product::where('company_uuid', $companyUuid);
        if ($store) {
            $query->where('store_uuid', $store->uuid);
        }

        return $query->count();
    }

    private function cartConversion(?string $companyUuid, Carbon $start, Carbon $end, ?Store $store, int $orders): float
    {
        $carts = Cart::where('company_uuid', $companyUuid)->whereBetween('created_at', [$start, $end])->get();

        if ($store) {
            $carts = $carts->filter(function ($cart) use ($store) {
                return collect($cart->items)->contains(function ($item) use ($store) {
                    return data_get($item, 'store_id') === $store->public_id;
                });
            });
        }

        $cartCount = $carts->count();

        return $cartCount > 0 ? round(($orders / $cartCount) * 100, 2) : 0;
    }

    private function metric($current, $previous, string $format = 'number', ?string $currency = null, bool $inverse = false): array
    {
        $delta        = $current - $previous;
        $deltaPercent = $previous != 0 ? round(($delta / abs($previous)) * 100, 2) : ($current > 0 ? 100 : 0);

        return [
            'value'         => $current,
            'previous'      => $previous,
            'delta'         => $delta,
            'delta_percent' => $deltaPercent,
            'format'        => $format,
            'currency'      => $currency,
            'inverse'       => $inverse,
        ];
    }

    private function days(Carbon $start, Carbon $end): array
    {
        $days   = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $days;
    }
}
