> v0.4.26 ~ "Nested categories, QPay orders complete again, new stores and networks show straight away, and truck orders are marked"

---
## Highlights

- **QPay orders complete in the published app again.** Storefront app 1.2.x asks for the checkout's status once when the customer returns from their bank app, and finishes only when the answer has the order. Since v0.4.25 that answer could come back without one, leaving the customer waiting. A status request without `verify` now checks with QPay and creates the order straight away. Apps that send `verify` are unchanged.
- **New stores and networks show straight away.** A network created from the context switcher, or a company's first store, appears in the switcher without a page reload. Saving the first store also loads the dashboard, order alerts and counts for it. (#148)
- **Network orders open at their own URL.** An order opened from a network's Orders page has its own URL (`/storefront/networks/<network>/orders/<order>`), so it can be reloaded and shared. (#149)
- **The order panel scrolls.** The order panel opened in place (dashboard, customer panel, network overview, checkout siblings, redemptions) now scrolls. (#149)
- **Settings save after a toggle.** Switching a network or store online, toggling a rule, or changing the currency, default order config, phone or proof-of-delivery method enables **Save changes**. (#152)
- **A truck's status decides whether it takes orders.** A truck is available while its Storefront status is active, whatever its vehicle, its driver's Navigator app or a telematics device reports. The API's truck `online` now means `status === 'active'`, and the dashboard counts inactive trucks. New trucks start inactive: set **Status → Active** to put one on sale. (#153)
- **Nested categories.** Product categories and catalog (menu) categories nest up to three levels, for example Drinks › Coffee › Cold brew. The category form has a parent picker, the sidebar offers "New subcategory", the catalog editor shows a tree with indent, outdent and move, and opening a parent shows its own products plus those of every category under it. Public API: categories carry `parent` and `depth`, `with_subcategories` lists the categories under each one, `products?category=` includes subcategories' products (`category_direct=1` keeps an exact match), and public products carry `category`. A parent in another store or catalog, a loop, or a fourth level is refused with `422`; removing a category moves its subcategories up a level. The customer app change is fleetbase/storefront-app#82. (#156)
- **Catalog categories have icons.** The catalog editor's pencil opens the same modal product categories use, with name, description and icon; the catalog save stores them and the `CatalogCategory` resource returns `icon` and `icon_color`. (#155)
- **Fix: a category icon upload no longer writes the file record into `icon`.** The upload callbacks set only `icon_file_uuid` and `icon_url`. (#157)
- **Fix: a network's saved gateways and notification channels show after a refresh.** The settings page now loads them itself instead of relying on the networks list. (#154)
- **Truck orders are marked.** Order lists and the order panel show the food truck an order was placed at. The order API adds `meta.food_truck_id` and `meta.food_truck` (`{ id, name }`). (#150)

---
## Upgrading
- **Requires fleetbase/core-api v1.6.71** (`composer.json` now requires `^1.6.71`): nested categories read `parent_uuid` from core's internal Category resource (fleetbase/core-api#316).
- No migrations.
- A network's Orders page listing every order in the company was caused by fleetbase/core-api caching one filter per model under Octane. Deploy fleetbase/core-api v1.6.71 or later (fleetbase/core-api#315) with this release.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
