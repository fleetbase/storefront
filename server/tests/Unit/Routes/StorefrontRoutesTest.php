<?php

use Illuminate\Support\Facades\Route;

class StorefrontRouteRecorder
{
    public array $routes = [];

    public function prefix(string $prefix): self
    {
        return $this;
    }

    public function namespace(string $namespace): self
    {
        return $this;
    }

    public function middleware(string|array $middleware): self
    {
        return $this;
    }

    public function group(array|Closure $attributes, ?Closure $callback = null): self
    {
        ($callback ?? $attributes)($this);

        return $this;
    }

    public function get(string $uri, mixed $action): self
    {
        return $this->record('GET', $uri, $action);
    }

    public function post(string $uri, mixed $action): self
    {
        return $this->record('POST', $uri, $action);
    }

    public function put(string $uri, mixed $action): self
    {
        return $this->record('PUT', $uri, $action);
    }

    public function patch(string $uri, mixed $action): self
    {
        return $this->record('PATCH', $uri, $action);
    }

    public function delete(string $uri, mixed $action): self
    {
        return $this->record('DELETE', $uri, $action);
    }

    public function match(array $methods, string $uri, mixed $action): self
    {
        foreach ($methods as $method) {
            $this->record(strtoupper($method), $uri, $action);
        }

        return $this;
    }

    public function fleetbaseRoutes(string $resource, ?Closure $callback = null): self
    {
        $this->routes[] = ['FLEETBASE', $resource, null];

        if ($callback) {
            $callback($this, fn (string $action): string => $resource . ':' . $action);
        }

        return $this;
    }

    private function record(string $method, string $uri, mixed $action): self
    {
        $this->routes[] = [$method, $uri, $action];

        return $this;
    }
}

test('storefront route file registers public consumable and internal API contracts', function () {
    $router = new StorefrontRouteRecorder();
    app()->instance('router', $router);
    Route::clearResolvedInstance('router');

    require dirname(__DIR__, 3) . '/src/routes.php';

    expect($router->routes)->toContain(
        ['GET', 'about', 'StoreController@about'],
        ['POST', '/', 'ProductController@create'],
        ['POST', 'receipt', 'OrderController@getReceipt'],
        ['POST', 'send-push-notification', 'ActionController@sendPushNotification'],
        ['FLEETBASE', 'orders', null],
        ['FLEETBASE', 'products', null],
        ['GET', '/', 'MetricsController@all'],
    )->and(count($router->routes))->toBeGreaterThan(50);
});
