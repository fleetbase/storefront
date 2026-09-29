> v0.4.23 ~ "A busy store can no longer rate-limit every other store"

---
## Highlights

The Storefront API rate limiter is isolated per store and per device. It ran before the storefront session was set up, so it keyed every bucket on the client IP. Behind a load balancer that is the balancer's address, so every store shared one bucket, and the key was identical to core-api's API limiter. One busy store, or one busy integration on the core API, could return `429 Too many requests` to every Storefront app on the platform. The limiter now keys on the store key plus the client IP, so each device of each store has its own limit. (#108)

---
## Upgrade Steps

No migrations. Deploy alongside Fleetbase `0.7.66` (Core API `1.6.66`), which also moves the core API limiter to per-consumer buckets and resolves the real client IP behind proxies (`TRUSTED_PROXIES`).
