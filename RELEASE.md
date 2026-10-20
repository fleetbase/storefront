> v0.4.28 ~ "Customers can sign up with their phone number again"

---
## Highlights

- **Phone sign-up works again.** Since v0.4.25, signing up with a phone number failed for everyone with "That code didn't match": the code arrived, but the request was refused before it was checked. Phone codes are stored hashed (an HMAC, never the code itself), and the sign-up request still validated the code by looking up the plain code in `verification_codes`, which can never match a hash. The request now only requires a code; the check itself, against the newest live code for that number with attempt counting and lockout, is unchanged. Email sign-up and sign-in were not affected.

---
## Upgrading
- No migrations.
- Customers who tried to sign up while this was broken only need to request a new code.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
