> v0.4.19 ~ "Marketplace storefronts with safer checkout and customer verification"

---
## Highlights

Storefront networks can now power a multi-merchant marketplace. Network clients can discover member stores, locations, categories, products, tags, reviews, and payment gateways while the API keeps every result inside the active marketplace.

Carts and checkout now validate merchant membership, store locations, product availability, online status, currency, and the network's multi-store policy. Delivery quotes preserve one origin per merchant, and cart responses include merchant details without querying each line separately.

---
## Security and Reliability

- Authenticated checkout now treats the `Customer-Token` identity as authoritative and rejects a conflicting customer ID with `403`.
- The app-review verification bypass is disabled by default and only works for explicitly allowlisted email addresses or phone numbers.
- Switching between store and network keys clears the previous storefront scope, preventing filters from leaking between requests.
- Store, category, product, location, and review lookups are constrained to the active storefront context.
- Invalid cart, location, review, Apple sign-in, and SMS-provider states now return controlled API errors instead of internal exceptions.

---
## API and Checkout Changes

- Network store discovery supports search, category and tag filters, online state, ratings, popularity, trending activity, age, nearest distance, and maximum distance.
- Product creation now defaults to `published`, matching the console, and marketplace reads only return published, available products.
- Checkout accepts both `serviceQuote` and the documented `service_quote` field.
- Cash, card, and payment-intent checkout responses now include the `checkout` public ID alongside the existing token so clients can query checkout status.
- A cart that has already produced an order can no longer be mutated; retrieving its old ID creates a fresh cart.
- SMS login and account-closure requests fall back to email when SMS is unavailable, and responses identify the delivery method.

---
## Upgrade Steps

No database migration is required.

Installations that use a fixed code for app-store review accounts must now configure both values; the previous `999000` default no longer works:

```dotenv
STOREFRONT_BYPASS_VERIFICATION_CODE=<a secret, rotated code>
STOREFRONT_REVIEW_ACCOUNTS=apple-review@example.com,+15555550100
```

Release the matching Storefront SDK and API specification before distributing marketplace-enabled app builds. Publish the corresponding documentation with the backend release.

---
## Need help?

- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
