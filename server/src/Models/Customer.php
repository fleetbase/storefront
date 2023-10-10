<?php

namespace Fleetbase\Storefront\Models;

use Fleetbase\FleetOps\Models\Contact;
use Illuminate\Support\Str;

class Customer extends Contact
{
    /**
     * The key to use in the payload responses
     *
     * @var string
     */
    protected string $payloadKey = 'customer';

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviews()
    {
        return $this->hasMany(Review::class, 'customer_uuid');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function productReviews()
    {
        return $this->hasMany(Review::class, 'customer_uuid')->where('subject_type', 'Fleetbase\Storefront\Models\Product');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function storeReviews()
    {
        return $this->hasMany(Review::class, 'customer_uuid')->where('subject_type', 'Fleetbase\Storefront\Models\Store');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviewUploads()
    {
        return $this->hasMany(\Fleetbase\Models\File::class, 'uploader_uuid')->where('type', 'storefront_review_upload');
    }

    /**
     * @return integer
     */
    public function getReviewsCountAttribute()
    {
        return $this->reviews()->count();
    }

    /**
     * Count the number of orders for the storefront with the given ID
     *
     * @param int $id The ID of the storefront to count orders for
     * @return int The number of storefront orders for the customer with this UUID
     */
    public function countStorefrontOrdersFrom($id)
    {
        return \Fleetbase\FleetOps\Models\Order::where(
            [
                'customer_uuid' => $this->uuid,
                'type' => 'storefront',
                'meta->storefront_id' => $id
            ]
        )->count();
    }

    /**
     * Find a customer with the given public ID
     *
     * @param string $publicId The public ID of the customer to find
     * @return static|null The customer with the given public ID, or null if none was found
     */
    public static function findFromCustomerId($publicId)
    {
        if (Str::startsWith($publicId, 'customer')) {
            $publicId = Str::replaceFirst('customer', 'contact', $publicId);
        }

        return static::where('public_id', $publicId)->first();
    }
}
