> v0.4.21 ~ "QPay checkout restored and marketplace network repairs"

---
## Highlights

QPay checkout works again. v0.4.20 cached the QPay access token using `expires_in`, which QPay returns as an absolute timestamp rather than a lifetime in seconds — so the token was held far past its real life, and once QPay expired it every storefront request kept presenting a dead token and was answered with `NO_CREDENTIALS` even though the merchant credentials were valid. A token is minted per authentication again.

Marketplace networks also pick up three repairs: saving a network with options, listing the categories its stores are grouped under, and creating those categories all failed in ways that only surfaced against a fully seeded marketplace.

---
## Fixes

- QPay authenticates fresh on every call, restoring checkout invoice creation, the `capture-qpay` callback, checkout status polling, and eBarimt receipts. Caching the token is only safe once the expiry timestamp is read as a timestamp, so it is gone rather than patched.
- Saving a `Network` with options no longer fails: the options mutator bypassed the model's JSON cast and stored a raw array.
- `Network::categories()` matched `for = 'network_category'`, a value nothing writes, so the relation was always empty. It now matches the `storefront_network` categories the console and the v1 API already use.
- `Network::createCategory()` derived its owner type from `network:storefront`, which resolves to no class. It now resolves to the `Network` model, so created categories are owned correctly.

---
## Testing Fixtures

The storefront testing seeders are split into two complete, idempotent fixtures over a shared concern, both run by `TestingSeeder`:

- `StoreSeeder` seeds one standalone store end to end — order config, a store location with weekly hours, a sandbox Stripe gateway, categories, products covering variants, addons and sale, unavailable and draft cases, a published catalog, customers, an open cart, a pending checkout, orders spread across the last month, and reviews.
- `NetworkSeeder` seeds a marketplace: a network with its own sandbox gateway, network categories, member stores built the same way, and network-tagged orders across them.

This closes gaps that made local testing misleading: no gateway, location or hours were seeded, so Stripe checkout and delivery quoting could not be exercised at all; core product categories were never purged and duplicated on every run; addon categories had no owner, so the console never listed them; every order collapsed to `created` because the status is reset when the tracking number is generated; and all records shared a single timestamp. Each seeder now tags and purges only its own fixtures.

---
## Upgrade Steps

No database migration is required.

Nothing needs clearing after deploying: the fixed code never reads the cached QPay token, so any entry left by v0.4.20 is inert. Installations still on v0.4.20 that need QPay working before they can deploy can drop that entry to buy one token lifetime, but the upgrade is the fix:

```bash
php artisan cache:forget storefront:qpay:token:<md5 of "<qpay base uri>|<merchant username>">
```

Releases are now cut from `release/v0.0.0` branches instead of `dev-v0.0.0`. The tag workflow accepts both while open branches move over, so no in-flight release branch is stranded.

---
## Need help?

- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
