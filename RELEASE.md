> v0.4.25 ~ "Verified reviews, order chat with the driver, richer public promotions and faster store listings"

---
## Highlights

- **Verified-purchase reviews.** Reviews can be tied to a delivered order and are marked verified. A new eligibility endpoint tells the app whether a customer can review a store, and customers can delete their own reviews. Reviewer email and phone are no longer exposed publicly; public reviews show a short name such as "Ada B.".
- **Chat with the driver.** Customers can chat with the driver delivering their order: get the chat, page through messages, send text and photos, and mark messages read. New messages are broadcast on the chat's `chat.{id}` channel.
- **Public promotions.** Public promotion payloads now include the owning store, the shareable code, availability and the next start time.
- **Product options.** Public product payloads now include each add-on category's `is_required` and `max_selectable`.
- **Faster store listings.** Network and Console store listings are quicker.
- **Private review photos.** Review photos upload without a public ACL, so private buckets work.

---
## Upgrading
- Run migrations. This release adds an index on `reviews.subject_uuid` and an `order_uuid` column on `reviews`.
- Order chat needs storefront socket authentication and fleetbase/core-api v1.6.69 or later.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
