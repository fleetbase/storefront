<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Fleetbase\Storefront\Models\Network;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Metrics service to pull analyitcs and metrics from Storefront.
 *
 * Ex:
 * $metrics = Metrics::new($company)->withTotalStores()->withOrdersCanceled()->get();
 */
class Metrics
{
    protected \DateTime $start;
    protected \DateTime $end;
    protected Company $company;
    protected array $metrics = [];

    public static function new(Company $company, \DateTime $start = null, \DateTime $end = null): Metrics
    {
        $start = $start === null ? Carbon::create(1900)->toDateTime() : $start;
        $end   = $end === null ? Carbon::tomorrow()->toDateTime() : $end;

        return (new static())->setCompany($company)->between($start, $end);
    }

    public static function forCompany(Company $company, \DateTime $start = null, \DateTime $end = null): Metrics
    {
        return static::new($company, $start, $end);
    }

    public function start(\DateTime $start): Metrics
    {
        $this->start = $start;

        return $this;
    }

    public function end(\DateTime $end): Metrics
    {
        $this->end = $end;

        return $this;
    }

    public function between(\DateTime $start, \DateTime $end): Metrics
    {
        return $this->start($start)->end($end);
    }

    private function setCompany(Company $company): Metrics
    {
        $this->company = $company;

        return $this;
    }

    private function set($key, $value = null): Metrics
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->set($k, $v);
            }

            return $this;
        }

        $this->metrics = data_set($this->metrics, $key, $value);

        return $this;
    }

    public function get()
    {
        return $this->metrics;
    }

    public function with(?array $metrics = [])
    {
        if (empty($metrics)) {
            $metrics = array_slice(get_class_methods($this), 9);
        }

        $metrics = array_map(
            function ($metric) {
                return Str::camel($metric);
            },
            $metrics
        );

        foreach ($metrics as $metric) {
            if (method_exists($this, $metric)) {
                $this->{$metric}();
            }
        }

        return $this;
    }

    public function totalProducts(callable $callback = null): Metrics
    {
        $query = Product::where('company_uuid', $this->company->uuid);

        if (is_callable($callback)) {
            $callback($query);
        }

        $data = $query->count();

        return $this->set('total_products', $data);
    }

    public function totalStores(callable $callback = null): Metrics
    {
        $query = Store::where('company_uuid', $this->company->uuid);

        if (is_callable($callback)) {
            $callback($query);
        }

        $data = $query->count();

        return $this->set('total_stores', $data);
    }

    public function totalNetworks(callable $callback = null): Metrics
    {
        $query = Network::where('company_uuid', $this->company->uuid);

        if (is_callable($callback)) {
            $callback($query);
        }

        $data = $query->count();

        return $this->set('total_networks', $data);
    }

    public function ordersInProgress(callable $callback = null): Metrics
    {
        $query = Order::where('company_uuid', $this->company->uuid)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->where('type', 'storefront')
            ->whereNotIn('status', ['completed', 'created', 'pending', 'canceled']);

        if (is_callable($callback)) {
            $callback($query);
        }

        $data = $query->count();

        return $this->set('orders_in_progress', $data);
    }

    public function ordersCompleted(callable $callback = null): Metrics
    {
        $query = Order::where('company_uuid', $this->company->uuid)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->where('type', 'storefront')
            ->where('status', 'completed');

        if (is_callable($callback)) {
            $callback($query);
        }

        $data = $query->count();

        return $this->set('orders_completed', $data);
    }

    public function ordersCanceled(callable $callback = null): Metrics
    {
        $query = Order::where('company_uuid', $this->company->uuid)
            ->whereBetween('created_at', [$this->start, $this->end])
            ->where('type', 'storefront')
            ->where('status', 'canceled');

        if (is_callable($callback)) {
            $callback($query);
        }

        $data = $query->count();

        return $this->set('orders_canceled', $data);
    }
}
