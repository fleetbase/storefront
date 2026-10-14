# Promotions, campaigns, and customer segments

Open **Storefront → Promotions** to manage discounts, campaigns, and customer
segments for the selected store.

## Promotions

Choose **New promotion**, then enter the customer-facing name, description,
discount type, and trigger. Automatic promotions apply to eligible carts; code
promotions require a promo code. Expand the conditions, targeting, limits, and
stacking sections to configure optional rules. Blank optional limits impose no
limit. Schedule fields are optional; the form explains when each takes effect.

Use **Manage codes** to generate a batch or enter one specific code. Entering a
specific code disables the batch count, prefix, and length fields because they do
not apply to that operation. The code list shows loading and failure states, with
a retry action if it cannot load.

The customer app shows the code of a public code promotion only when the promotion
has a reusable code that is active, not assigned to a customer and not expired
(for example one specific code entered with **Manage codes**). Batches of
single-use codes are never shown; send them to customers with a campaign instead.
Public promotions with weekly hours stay visible in the app outside those hours,
marked with when they next start.

## Campaigns

Enter an internal campaign name, notification title, and message. Choose an
audience and at least one delivery channel. Link a promotion or a URL if needed.

- **Save as draft** keeps the campaign for later editing.
- **Send now** saves and sends the campaign immediately.
- **Schedule** requires a future date and time.

The modal's confirmation button reflects the selected action. Campaigns that are
sending, sent, or canceled open for viewing with their fields disabled. Canceling
an editable form discards its unsaved changes.

## Customer segments

Name the segment and fill in the rules that define its audience. Customers must
match every rule entered. Blank rules do not restrict the audience. The form
explains which order and spending history the rules use.

The matching-customer count refreshes as rules change. While it refreshes, the
form shows a loading message instead of an outdated count. An unavailable preview
is identified separately from zero matching customers.

## Lists and product categories

Each list has a section-specific search field. Empty search results offer **Show
all** to clear filtering and return to the first page; a new list offers its create
action. Canceling a promotion, campaign, or segment edit restores unsaved values.

Product category navigation highlights only the selected category, including
while editing its products. **All Products** is active only in the full catalog.
