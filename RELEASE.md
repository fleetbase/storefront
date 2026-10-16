> v0.4.26 ~ "New stores and networks show straight away, network orders open at their own URL, and truck orders are marked"

---
## Highlights

- **QPay orders complete in the published app again.** Storefront app 1.2.x asks for the checkout's status once when the customer returns from their bank app, and finishes only when the answer has the order. Since v0.4.25 that answer could come back without one, leaving the customer waiting. A status request without `verify` now checks with QPay and creates the order straight away. Apps that send `verify` are unchanged.
- **New stores and networks show straight away.** A network created from the context switcher, or a company's first store, appears in the switcher without a page reload. Saving the first store also loads the dashboard, order alerts and counts for it. (#148)
- **Network orders open at their own URL.** An order opened from a network's Orders page has its own URL (`/storefront/networks/<network>/orders/<order>`), so it can be reloaded and shared. (#149)
- **The order panel scrolls.** The order panel opened in place (dashboard, customer panel, network overview, checkout siblings, redemptions) now scrolls. (#149)
- **Settings save after a toggle.** Switching a network or store online, toggling a rule, or changing the currency, default order config, phone or proof-of-delivery method enables **Save changes**. (#152)
- **A truck's status decides whether it takes orders.** A truck is available while its Storefront status is active, whatever its vehicle, its driver's Navigator app or a telematics device reports. The API's truck `online` now means `status === 'active'`, and the dashboard counts inactive trucks. New trucks start inactive: set **Status → Active** to put one on sale. (#153)
- **Truck orders are marked.** Order lists and the order panel show the food truck an order was placed at. The order API adds `meta.food_truck_id` and `meta.food_truck` (`{ id, name }`). (#150)

---
## Upgrading
- No migrations.
- A network's Orders page listing every order in the company was caused by fleetbase/core-api caching one filter per model under Octane. Deploy fleetbase/core-api v1.6.71 or later (fleetbase/core-api#315) with this release.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
