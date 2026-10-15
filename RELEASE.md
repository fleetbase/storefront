> v0.4.26 ~ "New stores and networks show straight away, network orders open at their own URL, and truck orders are marked"

---
## Highlights

- **New stores and networks show straight away.** A network created from the context switcher, or a company's first store, appears in the switcher without a page reload. Saving the first store also loads the dashboard, order alerts and counts for it. (#148)
- **Network orders open at their own URL.** An order opened from a network's Orders page has its own URL (`/storefront/networks/<network>/orders/<order>`), so it can be reloaded and shared. (#149)
- **The order panel scrolls.** The order panel opened in place (dashboard, customer panel, network overview, checkout siblings, redemptions) now scrolls. (#149)
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
