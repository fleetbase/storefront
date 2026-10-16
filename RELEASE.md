> v0.4.27 ~ "Customer notes reach every order and lead the order panel"

---
## Highlights

- **QPay orders keep the customer's note.** A note written at checkout was lost on QPay orders: the order is created by QPay's callback, which carries no note. The app now saves the note on the checkout before paying (`PUT storefront/v1/checkouts/notes`, by the checkout's token), and every way an order is created takes it from there when none is sent with the capture. A note saved after the order was placed is added to it. Notes are trimmed and capped at 2,000 characters.
- **The customer's note leads the order panel.** Delivery instructions, gate codes and other numbers to call now sit at the top of the order panel in a highlighted box, with line breaks kept and phone numbers that can be called. Staff notes stay in the Notes section.

---
## Upgrading
- No migrations.
- The Storefront app sends the note from v0.1.0 on; older apps keep sending it with the capture (Stripe) as before.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
