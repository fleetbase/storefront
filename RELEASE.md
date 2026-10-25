> v0.4.30 ~ "App store reviewers can sign in, and Continue with Fleetbase"

---
## Highlights

- **App Review access.** Apple and Google reviewers can't receive the texted sign-in code, and Apple rejects apps its reviewers can't sign in to. A store or network can now turn on **Settings → App Review**: reviewers sign in with the test number +1 202 555 0143 (a range kept for fiction, never texted) and a six-digit code the owner creates in the Console and enters in App Store Connect and Google Play Console. The code is stored hashed and shown once; it works only in the store that created it. The mechanism is core-api's App Review access (core-api 1.6.74), which Navigator and future apps share. Reviewers can sign up, sign in, add the number at checkout and delete their account without a text message, on Fleetbase Cloud as well as self-hosted. See [docs/app-review.md](docs/app-review.md).
- **Reviewers' orders are marked.** Orders placed by the review account carry `meta.app_review = true`, are labelled **App Review order** in the order panel and staff alerts, and are never auto-dispatched to a driver.
- **Self-hosted review accounts keep working** (`STOREFRONT_BYPASS_VERIFICATION_CODE` with `STOREFRONT_REVIEW_ACCOUNTS`), now for sign-up as well, and the accounts they list are never sent a code.
- **Endpoints and permission.** The Console uses core's `GET|PUT /int/v1/review-access/storefront-store/{id}` (and `storefront-network`). A new permission, `storefront manage-app-review store` / `network`, is needed to change it. (#162)
- **Continue with Fleetbase.** Apps signed in with core-api's OAuth provider (core-api 1.6.74) can, with an organization administrator's consent, read the organization's stores and networks with their public keys (`storefront:read`, `GET /storefront/oauth/v1/storefronts`), get a dedicated API key for their Storefront app (`storefront:app-key`, `POST /storefront/oauth/v1/app-key`, listed under Developers → API Keys) and turn App Review access on or off (`storefront:review-access`, through core's `/oauth/v1/review-access/storefront-store/{id}`). This is how AppHelm connects a Cloud customer's storefront without pasting keys. (#163)

---
## Upgrading
- **Requires fleetbase/core-api v1.6.74** (`composer.json` now requires `^1.6.74`), which adds App Review access (fleetbase/core-api#323) and the OAuth provider (fleetbase/core-api#324). Run migrations: core adds `review_accesses` and the OAuth tables; Storefront adds none.
- Grant `manage-app-review` on stores or networks to staff who aren't administrators and should manage App Review access (the Storefront Administrator role's full-access policy already covers it).

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
