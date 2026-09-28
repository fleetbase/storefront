<?php

namespace Fleetbase\Storefront\Promotions;

class PromotionUnavailableException extends \RuntimeException
{
    public function __construct(public string $promotionName)
    {
        parent::__construct('The promotion "' . $promotionName . '" is no longer available. Please review your cart and try again.');
    }
}
