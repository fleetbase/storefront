<?php

namespace Fleetbase\Storefront\Support;

class OrderConfig {
    /**
     * Provides default order configs for Storefront.
     */
    public function get(): ?array
    {
        return config('storefront.api.types.order', []);
    }
}