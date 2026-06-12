<?php

namespace Fleetbase\Storefront\Http\Controllers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\Customer;
use Fleetbase\Storefront\Models\FoodTruck;
use Fleetbase\Storefront\Models\Gateway;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\NotificationChannel;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    private const SEARCH_TYPES = ['products', 'catalogs', 'customers', 'orders', 'networks', 'stores', 'food-trucks', 'gateways', 'notification-channels'];

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) ($request->input('query') ?: $request->input('q')));
        $limit = max(1, min((int) $request->input('limit', 12), 24));
        $store = $this->storefront($request);

        if ($query === '') {
            return response()->json(['results' => []]);
        }

        $types        = $this->requestedTypes($request);
        $perTypeLimit = max(1, (int) ceil($limit / max(count($types), 1)));
        $results      = collect();

        foreach ($types as $type) {
            if (!$this->canSearchType($type)) {
                continue;
            }

            $results = $results->merge($this->searchType($type, $query, $perTypeLimit, $store));
        }

        return response()->json([
            'results' => $results->take($limit)->values(),
        ]);
    }

    private function requestedTypes(Request $request): array
    {
        $types = $request->input('types', self::SEARCH_TYPES);

        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }

        if (!is_array($types)) {
            return self::SEARCH_TYPES;
        }

        $types = array_values(array_intersect($types, self::SEARCH_TYPES));

        return empty($types) ? self::SEARCH_TYPES : $types;
    }

    private function canSearchType(string $type): bool
    {
        $permissions = [
            'products'              => 'storefront see product',
            'catalogs'              => 'storefront see catalog',
            'customers'             => 'storefront see customer',
            'orders'                => 'storefront see order',
            'networks'              => 'storefront see network',
            'stores'                => 'storefront see store',
            'food-trucks'           => 'storefront see food-truck',
            'gateways'              => 'storefront see gateway',
            'notification-channels' => 'storefront see notification-channel',
        ];

        $user = Auth::getUserFromSession();

        if ($user?->isAdmin()) {
            return true;
        }

        return Auth::can($permissions[$type]);
    }

    private function searchType(string $type, string $query, int $limit, ?Store $store): Collection
    {
        return match ($type) {
            'products'              => $this->searchProducts($query, $limit, $store),
            'catalogs'              => $this->searchCatalogs($query, $limit, $store),
            'customers'             => $this->searchCustomers($query, $limit, $store),
            'orders'                => $this->searchOrders($query, $limit, $store),
            'networks'              => $this->searchNetworks($query, $limit, $store),
            'stores'                => $this->searchStores($query, $limit, $store),
            'food-trucks'           => $this->searchFoodTrucks($query, $limit, $store),
            'gateways'              => $this->searchGateways($query, $limit, $store),
            'notification-channels' => $this->searchNotificationChannels($query, $limit, $store),
            default                 => collect(),
        };
    }

    private function searchProducts(string $query, int $limit, ?Store $store): Collection
    {
        return Product::where('company_uuid', session('company'))
            ->when($store, fn (Builder $builder) => $builder->where('store_uuid', $store->uuid))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'description', 'sku', 'status'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'sku', 'status'])
            ->map(fn (Product $product) => [
                'label'       => $product->name ?: $product->public_id,
                'description' => trim(implode(' ', array_filter([$product->sku, $product->status, $product->public_id]))),
                'icon'        => 'box',
                'type'        => 'Product',
                'route'       => 'console.storefront.products.index.index.edit',
                'models'      => [$product->public_id],
                'breadcrumb'  => 'Storefront > Products',
            ]);
    }

    private function searchCatalogs(string $query, int $limit, ?Store $store): Collection
    {
        return Catalog::where('company_uuid', session('company'))
            ->when($store, fn (Builder $builder) => $builder->where('store_uuid', $store->uuid))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'description', 'status'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'status'])
            ->map(fn (Catalog $catalog) => [
                'label'       => $catalog->name ?: $catalog->public_id,
                'description' => trim(implode(' ', array_filter([$catalog->status, $catalog->description, $catalog->public_id]))),
                'icon'        => 'book-open',
                'type'        => 'Catalog',
                'route'       => 'console.storefront.catalogs.index',
                'queryParams' => ['query' => $query],
                'breadcrumb'  => 'Storefront > Catalogs',
            ]);
    }

    private function searchCustomers(string $query, int $limit, ?Store $store): Collection
    {
        return Customer::where('company_uuid', session('company'))
            ->when($store, function (Builder $builder) use ($store) {
                $builder->whereHas('customerOrders', fn (Builder $query) => $query->where('meta->storefront_id', $store->public_id));
            })
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'email', 'phone', 'internal_id'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'email', 'phone'])
            ->map(fn (Customer $customer) => [
                'label'       => $customer->name ?: $customer->public_id,
                'description' => trim(implode(' ', array_filter([$customer->email, $customer->phone, $customer->public_id]))),
                'icon'        => 'user',
                'type'        => 'Customer',
                'route'       => 'console.storefront.customers.index.view',
                'models'      => [$customer->public_id],
                'breadcrumb'  => 'Storefront > Customers',
            ]);
    }

    private function searchOrders(string $query, int $limit, ?Store $store): Collection
    {
        return Order::where('company_uuid', session('company'))
            ->whereNotNull('meta->storefront_id')
            ->when($store, fn (Builder $builder) => $builder->where('meta->storefront_id', $store->public_id))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'internal_id', 'status'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'internal_id', 'status'])
            ->map(fn (Order $order) => [
                'label'       => $order->public_id ?: $order->internal_id,
                'description' => trim(implode(' ', array_filter([$order->status, $order->internal_id]))),
                'icon'        => 'file-invoice-dollar',
                'type'        => 'Order',
                'route'       => 'console.storefront.orders.index.view',
                'models'      => [$order->public_id],
                'breadcrumb'  => 'Storefront > Orders',
            ]);
    }

    private function searchNetworks(string $query, int $limit, ?Store $store): Collection
    {
        return Network::where('company_uuid', session('company'))
            ->when($store, function (Builder $builder) use ($store) {
                $builder->whereHas('stores', fn (Builder $query) => $query->where('stores.uuid', $store->uuid));
            })
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'description', 'email', 'phone', 'website'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'email', 'phone'])
            ->map(fn (Network $network) => [
                'label'       => $network->name ?: $network->public_id,
                'description' => trim(implode(' ', array_filter([$network->email, $network->phone, $network->public_id]))),
                'icon'        => 'network-wired',
                'type'        => 'Network',
                'route'       => 'console.storefront.networks.index.network',
                'models'      => [$network->public_id],
                'breadcrumb'  => 'Storefront > Networks',
            ]);
    }

    private function searchStores(string $query, int $limit, ?Store $store): Collection
    {
        return Store::where('company_uuid', session('company'))
            ->when($store, fn (Builder $builder) => $builder->where('uuid', $store->uuid))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'description', 'email', 'phone', 'website'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'email', 'phone'])
            ->map(fn (Store $store) => [
                'label'       => $store->name ?: $store->public_id,
                'description' => trim(implode(' ', array_filter([$store->email, $store->phone, $store->public_id]))),
                'icon'        => 'store',
                'type'        => 'Store',
                'route'       => 'console.storefront.settings.index',
                'queryParams' => ['query' => $query],
                'breadcrumb'  => 'Storefront > Settings',
            ]);
    }

    private function searchFoodTrucks(string $query, int $limit, ?Store $store): Collection
    {
        return FoodTruck::where('company_uuid', session('company'))
            ->when($store, fn (Builder $builder) => $builder->where('store_uuid', $store->uuid))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'status'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'status'])
            ->map(fn (FoodTruck $foodTruck) => [
                'label'       => $foodTruck->public_id,
                'description' => $foodTruck->status ?: 'Food truck',
                'icon'        => 'truck',
                'type'        => 'Food Truck',
                'route'       => 'console.storefront.food-trucks.index',
                'queryParams' => ['query' => $query],
                'breadcrumb'  => 'Storefront > Food Trucks',
            ]);
    }

    private function searchGateways(string $query, int $limit, ?Store $store): Collection
    {
        return Gateway::where('company_uuid', session('company'))
            ->when($store, fn (Builder $builder) => $builder->where('owner_uuid', $store->uuid))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'uuid', 'name', 'description', 'code', 'type'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description', 'code', 'type'])
            ->map(fn (Gateway $gateway) => [
                'label'       => $gateway->name ?: $gateway->public_id,
                'description' => trim(implode(' ', array_filter([$gateway->code, $gateway->type, $gateway->description]))),
                'icon'        => 'cash-register',
                'type'        => 'Gateway',
                'route'       => 'console.storefront.settings.gateways',
                'queryParams' => ['query' => $query],
                'breadcrumb'  => 'Storefront > Settings > Gateways',
            ]);
    }

    private function searchNotificationChannels(string $query, int $limit, ?Store $store): Collection
    {
        return NotificationChannel::where('company_uuid', session('company'))
            ->when($store, fn (Builder $builder) => $builder->where('owner_uuid', $store->uuid))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['uuid', 'name', 'scheme', 'app_key'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'name', 'scheme', 'app_key'])
            ->map(fn (NotificationChannel $channel) => [
                'label'       => $channel->name ?: $channel->app_key,
                'description' => trim(implode(' ', array_filter([$channel->scheme, $channel->app_key]))),
                'icon'        => 'bell-concierge',
                'type'        => 'Notification Channel',
                'route'       => 'console.storefront.settings.notifications',
                'queryParams' => ['query' => $query],
                'breadcrumb'  => 'Storefront > Settings > Notifications',
            ]);
    }

    private function storefront(Request $request): ?Store
    {
        $storefront = $request->input('storefront');

        if (!$storefront) {
            return null;
        }

        return Store::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($storefront) {
                $builder->where('public_id', $storefront)->orWhere('uuid', $storefront);
            })
            ->first();
    }

    private function whereLike(Builder $builder, array $columns, string $query): void
    {
        $like = '%' . Str::replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $builder->{$method}($column, 'like', $like);
        }
    }
}
