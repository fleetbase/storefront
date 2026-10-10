<?php

use Fleetbase\Storefront\Http\Controllers\StoreController;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Http\Request;

test('console store queries eager load what the store resource serializes', function () {
    $controller = new StoreController();

    $plain = Store::query();
    $controller->onQueryRecord($plain, Request::create('/int/v1/stores', 'GET'));

    $withCategory = Store::query();
    $controller->onQueryRecord($withCategory, Request::create('/int/v1/stores', 'GET', [
        'network'       => 'network_uuid',
        'with_category' => 1,
    ]));

    expect(array_keys($plain->getEagerLoads()))->toBe(['logo', 'backdrop'])
        ->and($plain->toSql())->toContain('reviews_avg_rating')
        ->and(array_keys($withCategory->getEagerLoads()))->toBe(['logo', 'backdrop', 'networks']);
});
