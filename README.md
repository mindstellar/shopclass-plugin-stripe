# Stripe Payment

Take card payments for ShopClass credit packages through
[Stripe Checkout](https://stripe.com/payments/checkout). Buyers pay on Stripe's hosted
page, so no card data reaches your site. A signed webhook marks the order paid, and
core adds the credits.

## Requirements

- ShopClass 6.4.0 or later, with billing turned on
- PHP 8.0 or later, with the curl extension
- Cron running, for retries and the daily check of pending orders

## Setup

1. Install and activate the plugin, then open **Plugins → Stripe**.
2. In the Stripe dashboard, open **Developers → API keys** and copy the secret key
   (`sk_test_…` in test mode) into *Test secret key*.
3. Open **Developers → Webhooks**, add an endpoint with the URL the settings page shows:

   ```
   https://example.com/index.php?page=billing&action=callback&gateway=stripe
   ```

   and select these events:

   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
   - `checkout.session.expired`
   - `charge.refunded`

4. Copy the endpoint's signing secret (`whsec_…`) into *Test webhook secret* and save.

Stripe shows at checkout only once both keys for the active mode are set and the site's
billing currency is one Stripe takes.

Saved keys are never shown again. Leave a key's box blank to keep it.

## Test mode

The plugin starts in test mode, and an admin notice says so. In test mode only a
signed-in admin sees Stripe at checkout, so a live site cannot give credits away. Pay with Stripe's
[test cards](https://docs.stripe.com/testing), such as `4242 4242 4242 4242`.

To go live, add the live secret key, add a second webhook endpoint in live mode with the
same URL and events, paste its signing secret, then switch *Mode* to *Live*.

## How an order is paid

- When the buyer comes back from Stripe, the plugin asks Stripe for the session and
  settles the order at once.
- The webhook settles it too, for a buyer who closes the tab. Bank debits that clear
  later arrive this way.
- If the site cannot record a payment, the event is queued and retried, and Stripe sends
  it again. If it still fails, the site's contact address gets an e-mail.
- Once a day, pending Stripe orders over an hour old are checked with Stripe.

Every path goes through core's billing checks: amount, currency and owner must match,
and a replayed event credits nothing.

## Refunds

Open the order under **Billing → Orders** and press **Refund**. The plugin refunds the
whole payment at Stripe and core takes the credits back. If Stripe is still processing
the refund, the order changes once Stripe reports it done. A full refund made in the
Stripe dashboard is recorded the same way. Partial refunds change nothing on the site.

## Several sites, one Stripe account

Each site tags its sessions with its own id and ignores events for other sites, so
several sites can share an account. Give each site its own webhook endpoint.

## Translations

Copy `languages/stripe.pot` to `languages/<locale>/messages.po`, translate it, and compile
it to `messages.mo` in the same folder. After changing strings, run `php tools/make-pot.php`.

## Tests

```bash
./tests/run.sh                                  # unit tests, no network
php tests/integration/billing.php               # core's scratch MySQL on port 33061
```

Both load core from `SHOPCLASS_CORE`, or a sibling checkout named `osclass`.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
