# Sign in with Apple and account deletion

If your iPhone app offers Sign in with Apple, Apple requires that deleting an account in
the app also revokes the customer's Sign in with Apple tokens (App Review guideline
5.1.1(v)). Storefront does this for you once it has a Sign in with Apple key for your app.

## Using your push key (most stores)

You can use the same key for push notifications and Sign in with Apple. If the key in your
store's **APN** notification channel (**Storefront → Settings → Notifications**) has Sign in
with Apple ticked, with your app as its primary App ID, and the channel's bundle ID is your
app's, you're done: Storefront uses that key. You don't need to add anything else.

If you're creating that key now, tick both **Apple Push Notifications service (APNs)** and
**Sign in with Apple** on it (steps below), then enter it in the APN channel.

## A separate key

Use this if your push key can't have Sign in with Apple, or you'd rather keep them apart.

### Create the key

1. In your Apple Developer account, open **Certificates, IDs & Profiles → Keys** and add
   a key.
2. Tick **Sign in with Apple**, choose **Configure** and pick your app's App ID as the
   primary App ID. You can tick **Apple Push Notifications service (APNs)** on the same
   key if you want one key for both.
3. Download the `.p8` file. Apple lets you download it only once.
4. Note the **Key ID** shown for the key, and your **Team ID** (top right of the
   developer site, or under **Membership**).

### Enter it in the Console

1. Open **Storefront → Settings → Notifications** and choose **New channel**.
2. Choose **Sign in with Apple (account deletion)** and fill in:
   - **Key ID** and **Team ID** from above.
   - **App bundle ID**: your app's bundle ID, for example `com.yourstore.app`.
   - **Private key content**: open the `.p8` file in a text editor and paste all of it,
     including the `BEGIN` and `END` lines.
3. Save.

For a network's app, add the key to any store in the same organization; the bundle ID
decides which app it is for.

## What happens

- When a customer signs in with Apple, Storefront exchanges the one-time code Apple gives
  the app for a refresh token and keeps it, encrypted.
- When the customer deletes their account, Storefront revokes that token with Apple, then
  deletes the account.
- The account is always deleted, even if no key is set up or Apple can't be reached. Each
  case is written to the server log.
- Customers who signed in before you added the key have no token kept yet. Theirs is kept
  the next time they sign in with Apple.
