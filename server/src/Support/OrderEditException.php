<?php

namespace Fleetbase\Storefront\Support;

/**
 * An order edit that cannot be made as asked: the stage does not allow it (422), the total moved
 * since it was previewed (409), or the money could not be settled (422).
 */
class OrderEditException extends \RuntimeException
{
    public function __construct(string $message, int $status = 422, public array $context = [])
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode() ?: 422;
    }
}
