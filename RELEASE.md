> v0.4.22 ~ "Reliable push notifications, a customer inbox, and promotions"

---
## Highlights

Push notifications reach Android and iOS devices again. Storefront's push code had several separate faults:
- A store's Firebase credentials were stored in a config slot the Firebase client never reads, so Android pushes could not be sent with them.
- iOS development and sandbox tokens were rejected.
- Marketplace orders were signed with the store's credentials instead of the network app's.
- A reinstalled or shared device kept notifying the previous customer.

Push delivery has been rebuilt on a single channel that picks the right credentials per device, retries tokens Apple or Google say belong to another app or environment, prunes dead tokens, and logs every failure.

Customers now have a **notifications inbox**. Order updates, promotions and campaigns are kept for the app to list, badge and mark as read, and new ones arrive in realtime.

Stores and networks get **promotions**:
- percentage off, amount off, free delivery, and buy X get Y discounts
- automatic or unlocked by promo codes
- targeting, schedules, usage limits, budgets and stacking rules
- priced into cash, Stripe and QPay checkouts alike

**Customer segments** and **campaigns** send targeted notifications now or on a schedule, and a promotion can be announced to customers when it starts. All of it is managed from new console screens under Promotions.

---
## Features

- **Notifications inbox** (`storefront/v1/notifications`): list with `unread`/`type`/`limit`/`offset`, unread count, retrieve, mark one or all as read, delete. Notifications are scoped to the customer and to the storefront app they use. Realtime delivery goes over `contact.{uuid}`.
- **Notification preferences** (`notifications/preferences`): `order_updates` turns off order status pushes, and the inbox copy is kept. `promotions` turns off promotional notifications and campaigns entirely.
- **Promotions:** automatic or promo-code discounts owned by a store or network, with:
  - product, category and store targeting
  - minimum spend or items, and first order only
  - date ranges and weekly time windows
  - total, per-customer and per-code usage limits
  - budgets and maximum discounts
  - stacking and priorities
- **Checkout integration:** checkouts price promotions when they're created and reserve each use under a row lock, so limits and budgets can't be overspent and the amount charged always matches the discount shown. Captured orders record the discount on the transaction and order, and marketplace orders split it per store.
- **Public promotion endpoints:** `promotions` lists the storefront's live deals. Carts can apply, remove and preview promo codes (`carts/{id}/promo-code`, `carts/{id}/promotions`), and checkouts accept `promo_codes`.
- **Customer segments:** rule-based audiences by order count, recent or lapsed ordering, join date, spend and push reachability, with live audience previews.
- **Campaigns:** push and/or inbox messages with an image and deep link, sent to a segment, chosen customers or everyone, now or on a schedule, in queued batches. A promotion can be announced with a campaign that goes out when it starts.
- **Console:** Promotions, Campaigns and Segments screens, including promo-code generation and management.
- **Notification channel test:** a "Send test" action in the console shows Apple's or Google's exact answer for a pasted device token.
- **Ukrainian translation.**

---
## Fixes

- **Firebase credentials:** FCM clients are built from the store's own Firebase service account. Previously the account JSON was written into `credentials.private_key` of the platform project and never worked. A store channel no longer needs the platform to have its own Firebase project either.
- **Marketplace pushes:** network orders use the network app's push credentials first, then the store's.
- **iOS environments:** tokens Apple rejects with `BadDeviceToken` are retried in the other APNs environment. APNs channels gain an `environment` setting (auto, production or sandbox).
- **Device registration:** registering a device moves the token to the customer who registered it most recently, normalizes the platform (`iOS` used to match nothing), and validates input. A new `customers/unregister-device` endpoint removes a token on sign out.
- **Push ordering and errors:** push is sent before mail and database delivery, so an email failure no longer stops it. A notification failure when auto-accepting an order is now logged instead of swallowed.
- **Payloads:** Android pushes are sent with high priority and an optional channel id, and iOS pushes play a sound.
- `Storefront::about()` returns `null` for an unknown storefront key instead of throwing.

---
## Upgrade Steps

1. Run the migrations. They add `promotions`, `promotion_codes`, `promotion_redemptions`, `customer_segments` and `campaigns`:

   ```bash
   php artisan migrate
   ```

2. **Scheduler:** make sure it runs. `storefront:dispatch-campaigns` runs every minute and `storefront:release-promotion-reservations` every 15 minutes.
3. **Queue worker:** make sure one runs. Campaigns are delivered in queued batches, and several order notifications were already queued.
4. **Optional:** fleetbase/core-api adds `user_devices.app_identifier`, `environment` and `last_seen_at`. With it, devices remember which app and APNs environment issued their token. Without it everything works and the server infers both.
5. **Optional:** set `STOREFRONT_PUSH_ANDROID_CHANNEL_ID`, or the per-channel `android_channel_id`, to post Android pushes to a specific notification channel.

**Behavior changes to note:**
- **Order notification data:** order notifications stored in the database now keep the readable body in `message`; most types used to store the status code there. `type`, `title` and `body` are added, and every earlier key is kept.
- **Register device:** `POST customers/register-device` now requires `token` and a `platform` of `ios` or `android`.
- **Promotional push action:** the console's promotional push notification action now sends a campaign. It's recorded, respects customers' promotion preferences, also appears in their inbox, and is delivered by the queue.
- **Discount transaction item:** the discount is stored as a positive amount with `code: discount` and `meta.direction: credit`, because the Money cast drops signs. Anything that sums transaction items should subtract it.

---
## Need help?

- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
