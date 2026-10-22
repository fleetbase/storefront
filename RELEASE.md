> v0.4.29 ~ "A phone sign-up keeps its number"

---
## Highlights

- **Phone sign-up saves the number.** Signing up with a phone number and texted code created the account without that number: the app sends it as the `identity` the code was filed under, and account creation only read a separate `phone` field. Customers were then asked to verify the same number again, couldn't sign back in by phone, and the same number could sign up a second time. The verified identity is now the account's phone, on the user and the customer contact, and is marked verified (`phone_verified_at`), as is an existing user's matching number. A `phone` sent alongside still takes precedence. Email sign-up is unchanged.

---
## Upgrading
- No migrations.
- Accounts created by phone before this release have no phone saved. They are asked to add it once in the app (Storefront App v0.1.1 also asks at checkout).

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
