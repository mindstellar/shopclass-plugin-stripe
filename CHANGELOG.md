# Changelog

## 0.1.7

### Changed
- On Shopclass 7.0 the settings page stays reachable from the plugin list, since 7.0 has no Billing menu for plugin pages.
- In test mode Stripe is offered with only the secret key; live mode still needs the webhook signing secret.
- The settings page moves under Billing.
- Test mode also shows at checkout for a signed-in admin on themes that pay through the API (Shopclass 7.1).
- A payment for an order already closed (failed or cancelled) is refunded at Stripe.

## 0.1.6

### Changed
- Core registries are reached through getInstance() on Shopclass 7.0, and the gateway registers on the registry the hook passes.
- Tests load the core container the registries now sit on.

## 0.1.5

### Changed
- The check for unanswered payments reads its orders through core's order store, oldest first.
- After paying, the buyer returns to the theme's own order page when the theme has one.
- The description and README say the plugin pays for shop orders as well as credit packages.
- The README covers install, settings, what the plugin stores and its hooks.
- The CI tests run against the core branch named by the `SHOPCLASS_CORE_REF` variable, `develop` by default.

## 0.1.4

### Fixed
- Shipped and delivered orders keep the payment link, a late payment for them is not reported as stuck, and goods orders are not told credits were added.

## 0.1.3

### Changed
- A shop order shows what was bought in Stripe Checkout, not a credits count.
- On Shopclass 7.0 the gateway is built only on pages that show a payment, not on every page.

## 0.1.2

### Changed
- Works with Shopclass 7.0 as well as 6.4.

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
