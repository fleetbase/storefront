> v0.4.30 ~ "App store reviewers can sign in"

---
## Highlights

- **App Review access.** Apple and Google reviewers can't receive the texted sign-in code, and Apple rejects apps its reviewers can't sign in to. A store or network can now turn on **Settings → App Review**: reviewers sign in with the test number +1 202 555 0143 (a range kept for fiction, never texted) and a six-digit code the owner creates in the Console and enters in App Store Connect and Google Play Console. The code is stored hashed and shown once; it works only in the store that created it. Reviewers can sign up, sign in, add the number at checkout and delete their account without a text message, on Fleetbase Cloud as well as self-hosted. See [docs/app-review.md](docs/app-review.md).
- **Reviewers' orders are marked.** Orders placed by the review account carry `meta.app_review = true`, are labelled **App Review order** in the order panel and staff alerts, and are never auto-dispatched to a driver.
- **Self-hosted review accounts keep working** (`STOREFRONT_BYPASS_VERIFICATION_CODE` with `STOREFRONT_REVIEW_ACCOUNTS`), now for sign-up as well, and the accounts they list are never sent a code.
- **New internal endpoints** `GET|PUT /storefront/int/v1/stores/{id}/app-review` and `.../networks/{id}/app-review`, and a new permission, `storefront manage-app-review store` / `network`.

---
## Upgrading
- Run migrations: adds the `app_review_accesses` table on the Storefront connection.
- Grant `manage-app-review` on stores or networks to staff who aren't administrators and should manage App Review access (the Storefront Administrator role's full-access policy already covers it).

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
