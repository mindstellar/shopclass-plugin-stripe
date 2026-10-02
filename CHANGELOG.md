# Changelog

## 0.1.1

### Changed
- The download is smaller: the market screenshots are no longer inside the zip.

## 0.1.0

### New
- Card payments for credit packages through Stripe Checkout; no card data on the site.
- Orders are settled when the buyer returns and by a signed webhook, both through core's billing checks.
- Webhook signatures are checked against the raw body, with a five-minute window.
- Payments that clear later, failed payments and expired checkouts update the order.
- An event the site could not record is queued and retried; the admin is e-mailed if it still fails.
- A daily check settles pending orders Stripe never reported.
- Refunds from the admin order screen; full refunds made in Stripe are recorded too.
- A "View payment" link on the admin order screen opens the payment in the Stripe dashboard (test or live, as it was paid).
- Test and live mode with separate keys; test mode is offered only to signed-in admins.
- Zero- and three-decimal currencies are charged in the right units.
- Several sites can share one Stripe account.
- Translation template in `languages/stripe.pot`.
