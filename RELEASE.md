> v0.5.1 ~ "Push apps"

---
## Highlights

- **Push notifications go through push apps.** Each store and network has one push app (core-api's push apps, the same ones Fleet-Ops uses) holding its mobile app's Apple and Firebase settings, plus an optional Sign in with Apple key. Storefront's own notification channels and push senders are gone. Set it up under **Settings → Push notifications** (a network: **Settings → Notifications**).
- **Installed apps keep working.** Upgrading turns each store's and network's APN, FCM and Sign in with Apple channels into its push app and links the devices registered from it. Apps keep registering with their storefront key; `noty_channel_…` keys sent as `push_app` still resolve.

---
## Upgrading

- **Requires fleetbase/core-api v2.0.3** (push apps). Upgrade core-api first.
- Run `php artisan migrate` after upgrading. The migration moves notification channels into push apps and leaves the `notification_channels` table in place.
- The internal `notification-channels` endpoints are replaced by `storefront/int/v1/push-apps/{store or network}`. Roles keep the same `storefront … notification-channel` permissions.
