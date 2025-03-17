<?php

namespace Fleetbase\Storefront\Support;

use Fleetbase\Storefront\Models\Customer;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentMethod;

class StripeUtils
{
    /**
     * Checks if the given customer's saved payment method is valid and attached to them on Stripe.
     * If not, attempts to attach it.
     *
     * @param Customer $customer an instance of your Customer model
     *
     * @return bool true if the payment method is valid and attached, false otherwise
     */
    public static function isCustomerPaymentMethodValid(Customer $customer): bool
    {
        $stripeCustomerId = $customer->getMeta('stripe_id');
        $paymentMethodId  = $customer->getMeta('stripe_payment_method_id');

        if (!$stripeCustomerId || !$paymentMethodId) {
            return false;
        }

        try {
            $pm = PaymentMethod::retrieve($paymentMethodId);

            return $pm && $pm->customer === $stripeCustomerId;
        } catch (\Exception $e) {
            Log::error('Error verifying or attaching payment method: ' . $e->getMessage());

            return false;
        }
    }
}
