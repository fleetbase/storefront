> v0.4.20 ~ "Verified payments and gateway callbacks that arrive"

---
## Highlights

Payment capture is now settled by the payment provider rather than by the client. A Stripe checkout is captured only after Fleetbase retrieves the PaymentIntent it linked to that checkout and confirms the payment succeeded and matches the checkout, and QPay callbacks reach a route that exists so a paid invoice completes its order instead of waiting for a client to poll.

Capture itself is serialized per checkout, so a retry or a double-tap can no longer produce two orders for one payment.

---
## Security and Reliability

- Stripe capture verifies the server-linked PaymentIntent — status, amount, amount received, currency, customer, and live/test mode — before creating an order. Client-supplied transaction details can no longer stand in for the provider's verified values.
- Checkout capture is serialized per checkout: a concurrent capture returns `409` while the first is still in progress, and an already-captured checkout returns its existing order instead of creating a second one.
- Customer profile updates require the `Customer-Token` of the customer being updated. A missing or mismatched token returns `403`, on both `/customers/{id}` and the `/contacts/{id}` alias.
- QPay payment cancel and refund requests no longer send a malformed callback URL.

---
## API and Checkout Changes

- QPay invoices carry the per-checkout callback URL, and the callback targets the registered `capture-qpay` endpoint — the previous default named a route that was never registered, so payment notifications were lost. The URL is built from one helper that honors the configured storefront route prefix.
- Capturing a Stripe checkout whose payment has not completed returns `402`; a missing or mismatched PaymentIntent returns `422`, and a temporary Stripe verification failure returns `502`.
- A cash pickup checkout no longer requires a delivery service quote.
- Checkouts persist the Stripe PaymentIntent they were initialized with, which is the identifier capture verifies against.
- QPay access tokens are reused until shortly before they expire instead of re-authenticating on every checkout, callback, and status poll, and invoice line items resolve their products in one query rather than two per cart item.

---
## Upgrade Steps

This release adds a `stripe_payment_intent_id` column to `checkouts`. Run migrations after deploying:

```bash
php artisan migrate
```

Update `fleetbase/core-api` to **v1.6.60 or newer**. The QPay callback URL depends on the `Utils::apiUrl` query-string fix released there; against an older core-api the checkout identifier is appended as a path segment and the callback cannot be matched.

Two client-facing contracts changed:

- Clients must confirm the Stripe PaymentIntent before calling checkout capture. A client that captured optimistically now receives `402` until the payment succeeds.
- Clients that update a customer profile must send that customer's `Customer-Token`; the storefront key alone is no longer sufficient.

Publish the matching API specification and documentation with this release.

---
## Need help?

- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
