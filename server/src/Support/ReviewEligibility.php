<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Storefront\Models\Product;
use Fleetbase\Storefront\Models\Review;
use Fleetbase\Storefront\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether a customer may review a store or a product.
 *
 * A review has to come from a completed order that was placed with the store, or that
 * contains the product, and each order can be reviewed once per subject. The result also
 * says why a review is not allowed, so the app can explain it instead of hiding the button.
 */
class ReviewEligibility
{
    public const SIGN_IN_REQUIRED    = 'sign_in_required';
    public const UNSUPPORTED_SUBJECT = 'unsupported_subject';
    public const NO_COMPLETED_ORDER  = 'no_completed_order';
    public const ALREADY_REVIEWED    = 'already_reviewed';

    public const COMPLETED_STATUSES = ['completed'];

    /**
     * How many recent completed orders are considered when looking for one to review.
     */
    public const ORDER_LOOKBACK = 50;

    /**
     * @var array<string, string>
     */
    public const MESSAGES = [
        self::SIGN_IN_REQUIRED    => 'Sign in to write a review.',
        self::UNSUPPORTED_SUBJECT => 'Only stores and products can be reviewed.',
        self::NO_COMPLETED_ORDER  => 'You can review this after an order with it is completed.',
        self::ALREADY_REVIEWED    => 'You have already reviewed this for your completed orders.',
    ];

    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?Order $order = null,
        public ?Review $review = null,
    ) {
    }

    /**
     * Check whether the customer can review the subject, optionally for one specific order.
     *
     * When no order is given, the most recent completed order that has not been reviewed for
     * this subject is used.
     */
    public static function check(?Contact $customer, mixed $subject, ?string $orderId = null): self
    {
        if (!$customer) {
            return new self(false, self::SIGN_IN_REQUIRED);
        }

        $query = static::completedOrdersFor($customer, $subject);
        if (!$query) {
            return new self(false, self::UNSUPPORTED_SUBJECT);
        }

        if ($orderId) {
            $query->where(fn ($orderQuery) => $orderQuery->where('public_id', $orderId)->orWhere('uuid', $orderId));
        }

        $orders = $query->orderByDesc('created_at')->limit(static::ORDER_LOOKBACK)->get();
        if ($orders->isEmpty()) {
            return new self(false, self::NO_COMPLETED_ORDER);
        }

        $reviews = Review::where('customer_uuid', $customer->uuid)
            ->where('subject_uuid', $subject->uuid)
            ->whereIn('order_uuid', $orders->pluck('uuid'))
            ->get()
            ->keyBy('order_uuid');

        $unreviewed = $orders->first(fn ($order) => !$reviews->has($order->uuid));
        if (!$unreviewed) {
            $latest = $orders->first();

            return new self(false, self::ALREADY_REVIEWED, $latest, $reviews->get($latest->uuid));
        }

        return new self(true, null, $unreviewed);
    }

    /**
     * The customer's completed orders that include the subject, or null when the subject
     * cannot be reviewed.
     */
    public static function completedOrdersFor(Contact $customer, mixed $subject): ?Builder
    {
        $query = Order::query()
            ->where('customer_uuid', $customer->uuid)
            ->whereIn('status', static::COMPLETED_STATUSES);

        if ($subject instanceof Store) {
            return $query->where('meta->storefront_id', $subject->public_id);
        }

        if ($subject instanceof Product) {
            return $query->whereIn(
                'payload_uuid',
                Entity::query()->select('payload_uuid')->where('internal_id', $subject->public_id)->whereNotNull('payload_uuid')
            );
        }

        return null;
    }

    public function message(): ?string
    {
        return $this->reason ? static::MESSAGES[$this->reason] : null;
    }

    /**
     * @return array{can_review: bool, reason: ?string, message: ?string, order: ?string, review: ?string}
     */
    public function toArray(): array
    {
        return [
            'can_review' => $this->allowed,
            'reason'     => $this->reason,
            'message'    => $this->message(),
            'order'      => $this->order?->public_id,
            'review'     => $this->review?->public_id,
        ];
    }
}
