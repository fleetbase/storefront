# App Review access

Apple and Google test every app before it goes on sale. Your Storefront app signs
customers in with a code sent by text message, and app store reviewers can't receive
one. Apple often rejects an app its reviewers can't sign in to.

App Review access gives reviewers a test phone number and a sign-in code that you
enter in App Store Connect and Google Play Console. Nobody is sent a text message.

## Turn it on

1. Open **Storefront → Settings → App Review**. For a network, open the network's
   **Settings** and go to **App Review**.
2. Choose **Turn on**. A six-digit sign-in code is created.
3. Copy the code straight away. It is only shown once.
4. Enter the phone number and the code in each store:
   - **App Store Connect:** your app's version page, under **App Review
     Information → Sign-in required**. Enter the phone number as the user name and
     the code as the password, and add a note such as "Sign in with this phone
     number, then enter this code when asked."
   - **Google Play Console:** **App content → App access**, as a set of
     instructions with the phone number and the code.

The phone number is **+1 202 555 0143**. It belongs to a range kept for films and
books, so it can't reach a real person, and it is never sent a message. It is the
same for every store: the code decides which store it signs in to, and a code from
one store doesn't work at another.

## What reviewers see

The reviewer signs in like a customer: they enter the phone number, the app asks for
a code, and they enter yours. They can sign up, browse, add the number at checkout,
place orders and delete the account, all without a text message.

## Orders from reviewers

Orders a reviewer places are real orders in your store, so you can see what they
tested. They are marked **App Review order** at the top of the order, and staff
alerts about them say they are App Review test orders. They are never sent to a
driver automatically, even if your store dispatches orders automatically.

Don't prepare or deliver them. Cancel them when you're ready.

## Create a new code or turn it off

- **Create new code** replaces the code. The old one stops working at once, so enter
  the new one in App Store Connect and Google Play Console before your next
  submission.
- **Turn off** stops reviewers signing in. Turn it off once your app is approved if
  you like, and turn it back on before you submit an update. Turning it back on
  restores the same code, unless you created a new one.

Only staff with the **manage-app-review** permission on stores (or networks) can
change it; administrators always can.

## Self-hosted installs

Self-hosted installs can still set review accounts for the whole instance with
environment variables. They work alongside each store's setting, and are used only
for the identities they list:

```
STOREFRONT_BYPASS_VERIFICATION_CODE=<a secret code>
STOREFRONT_REVIEW_ACCOUNTS=reviewer@example.com,+12025550143
```

Both are required. The code works only for the listed phone numbers and email
addresses, in every store on the instance, and they are never sent a code either.

## For developers

App Review access is core-api's (`Fleetbase\Support\AppReview`, table `review_accesses`, core-api 1.6.74 or later). Storefront registers stores as `storefront-store` and networks as `storefront-network`, with the review number above, and the Console uses core's endpoints. They take the store's or network's id or public id, and are scoped to the signed-in company:

```
GET  /int/v1/review-access/storefront-store/{id}
PUT  /int/v1/review-access/storefront-store/{id}
GET  /int/v1/review-access/storefront-network/{id}
PUT  /int/v1/review-access/storefront-network/{id}
```

`PUT` takes `enabled` (true or false) and `regenerate_code` (true to replace the code), both optional. Turning access on when there is no code creates one. Both return:

```json
{
    "review_access": {
        "type": "storefront-store",
        "enabled": true,
        "phone": "+12025550143",
        "phone_display": "+1 202 555 0143",
        "has_code": true,
        "code_generated_at": "2026-10-24T09:12:00+00:00",
        "enabled_at": "2026-10-24T09:12:00+00:00",
        "code": "482913"
    }
}
```

`code` is present only in the response that created it. The server stores only a hash of it, so it can't be read back later; create a new one instead. `GET` needs `storefront view store` (or `network`), `PUT` needs `storefront manage-app-review store` (or `network`). From PHP, call `Fleetbase\Support\AppReview::update($store, true)` and `AppReview::status($store)`.
