> v0.4.31 ~ "Sign in with Apple tokens are revoked on deletion, and Launch App opens AppHelm"

---
## Highlights

- **Sign in with Apple tokens are revoked when a customer deletes their account,** as App Review guideline 5.1.1(v) requires. Tokens are stored encrypted at sign-in, and a missing key or a refusal from Apple never blocks sign-in or deletion. See `docs/sign-in-with-apple.md`.
- **Launch App opens AppHelm,** Fleetbase's service for publishing a store as a web and mobile app. Fleetbase Cloud accounts sign in with the account they're already using, and self-hosted installs go to the page that explains the service.

---
## Upgrading

- Run migrations: adds `apple_sign_in_tokens` on the storefront connection.
- Customers who signed in with Apple before a key was configured have no token stored yet. Theirs is stored the next time they sign in with Apple.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
