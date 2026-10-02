<?php

/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\stripe;

use Closure;
use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\CheckoutIntent;
use mindstellar\billing\DashboardLinkGateway;
use mindstellar\billing\Order;
use mindstellar\billing\RefundableGateway;
use mindstellar\stripe\Http\HttpClient;
use mindstellar\stripe\Http\HttpException;
use Throwable;

/**
 * Stripe Checkout as a Shopclass payment gateway. Orders settle only from signed webhooks or
 * data fetched from Stripe, always through Billing::handleCallback() so core's checks apply.
 */
final class StripeGateway implements RefundableGateway, DashboardLinkGateway
{
    /** Gateway id stored on every order. */
    public const ID = 'stripe';

    /** Order meta key for the mode a payment was made in. */
    public const META_LIVEMODE = 'stripe_livemode';

    /** Request key holding a Verified object. */
    public const TRUSTED = 'stripe_verified';

    /** Events the webhook acts on. Select these in the Stripe dashboard. */
    public const EVENTS = array(
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'charge.refunded',
    );

    private StripeApi $api;

    /** fn(): array{0:string,1:string} -- the raw body and the Stripe-Signature header. */
    private Closure $input;

    /** fn(array $request): CallbackResult -- settle a verified request through core. */
    private Closure $settle;

    /** fn(string $eventId): void -- queue an event for a later retry. */
    private Closure $queue;

    public function __construct(
        private Config $config,
        HttpClient $http,
        private OrderStore $orders,
        ?Closure $input = null,
        ?Closure $settle = null,
        ?Closure $queue = null
    ) {
        $this->api    = new StripeApi($http, $config->secretKey);
        $this->input  = $input ?? static fn (): array => array(
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''),
        );
        $this->settle = $settle ?? static fn (array $request): CallbackResult => Billing::handleCallback(self::ID, $request);
        $this->queue  = $queue ?? static function (string $eventId): void {
            osc_job_enqueue(Plugin::JOB_EVENT, array('event' => $eventId), array('unique_key' => $eventId));
        };
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getName(): string
    {
        return $this->config->name;
    }

    /**
     * The currencies listed in settings, or the billing currency when none are.
     *
     * @return string[]
     */
    public function getSupportedCurrencies(): array
    {
        if ($this->config->currencies !== array()) {
            return $this->config->currencies;
        }

        return in_array($this->config->billingCurrency, Money::SUPPORTED, true) ? array($this->config->billingCurrency) : array();
    }

    /**
     * Offered at checkout once the active mode has both keys and Stripe takes the billing
     * currency. In test mode only a signed-in admin sees it, so a live site cannot hand out free credits.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        if (!$this->hasKeys() || !in_array($this->config->billingCurrency, $this->getSupportedCurrencies(), true)) {
            return false;
        }

        return $this->config->live() || ($this->config->isAdmin !== null && ($this->config->isAdmin)());
    }

    /**
     * Whether the active mode has both keys. Webhooks and lookups need only this.
     *
     * @return bool
     */
    public function hasKeys(): bool
    {
        return $this->config->secretKey !== '' && $this->config->webhookSecret !== '';
    }

    /**
     * Open a Stripe Checkout session for the order and send the buyer to it.
     *
     * @param Order $order
     *
     * @return CheckoutIntent
     */
    public function createCheckout(Order $order): CheckoutIntent
    {
        $currency = strtoupper($order->getCurrency());
        $minor    = Money::toMinor($order->getAmount(), $currency);
        if ($minor === null || !in_array($currency, $this->getSupportedCurrencies(), true)) {
            return $this->checkoutFailed($order, 'amount cannot be charged in ' . $currency);
        }

        $meta   = array('order_id' => (string) $order->getId(), 'site' => $this->config->siteId);
        $return = ($this->config->returnUrl)($order->getId());
        $params = array(
            'mode'                => 'payment',
            'line_items'          => array(array(
                'quantity'   => 1,
                'price_data' => array(
                    'currency'     => strtolower($currency),
                    'unit_amount'  => $minor,
                    'product_data' => array(
                        'name' => sprintf(__('%d credits', 'stripe'), $order->getCredits()),
                    ),
                ),
            )),
            'client_reference_id' => (string) $order->getId(),
            'metadata'            => $meta,
            'payment_intent_data' => array('metadata' => $meta),
            // Stripe fills in {CHECKOUT_SESSION_ID} itself, so it must not be URL-encoded.
            'success_url'         => $return . (str_contains($return, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'          => $this->config->cancelUrl,
        );
        $email = $this->orders->buyerEmail($order->getUserId());
        if ($email !== null) {
            $params['customer_email'] = $email;
        }

        try {
            $session = $this->api->createCheckoutSession($params, 'checkout-' . $this->config->siteId . '-' . $order->getId());
        } catch (HttpException $e) {
            return $this->checkoutFailed($order, $e->getMessage());
        }

        $id  = (string) ($session['id'] ?? '');
        $url = (string) ($session['url'] ?? '');
        if (!str_starts_with($id, 'cs_') || !str_starts_with($url, 'https://')) {
            return $this->checkoutFailed($order, 'unexpected checkout session');
        }

        // Without the session on the order, the return page and the daily check cannot find it,
        // and a closed order could be paid but never credited. Stripe replays the same session on a retry.
        if (!$this->orders->attachSession($order->getId(), $id)) {
            $stored = $this->orders->find($order->getId());
            if ($stored === null || !$stored->isPending() || $stored->getExternalRef() !== $id) {
                return $this->checkoutFailed($order, 'could not store session ' . $id . ' on the order');
            }
        }

        return CheckoutIntent::redirect($url);
    }

    /**
     * Read a callback: a signed webhook from Stripe, or a Verified object from this plugin.
     *
     * @param array $request
     *
     * @return CallbackResult
     */
    public function handleCallback(array $request): CallbackResult
    {
        if (!$this->hasKeys()) {
            // Retryable: Stripe keeps the event and sends it again once the keys are in.
            return CallbackResult::ignored('stripe is not configured', true);
        }

        $verified = $request[self::TRUSTED] ?? null;
        if ($verified instanceof Verified) {
            return $verified->kind === Verified::SESSION
                ? $this->fromSession($this->api->session($verified->session), $verified->orderId)
                : $this->fromEvent($verified->event);
        }

        return $this->webhook();
    }

    /**
     * Refund a paid order in full.
     *
     * @param Order $order
     *
     * @return CallbackResult
     */
    public function refund(Order $order): CallbackResult
    {
        try {
            $intent = $this->paymentIntentOf($order);
            if ($intent === null) {
                return CallbackResult::ignored('no Stripe payment found for this order');
            }
            $refund = $this->api->createRefund(
                array(
                    'payment_intent' => $intent,
                    'metadata'       => array('order_id' => (string) $order->getId(), 'site' => $this->config->siteId),
                ),
                'refund-order-' . $order->getId() . '-' . $this->config->siteId
            );
        } catch (HttpException $e) {
            $code = $e->getCode();
            if ($code >= 400 && $code < 500) {
                // Stripe replays a refused request under the same idempotency key for 24 hours.
                return CallbackResult::ignored(rtrim(self::short($e->getMessage()), '.')
                    . '. Stripe gives the same answer for this order for 24 hours; refund it in the Stripe dashboard instead.');
            }

            return CallbackResult::ignored(self::short($e->getMessage()));
        }

        $status = (string) ($refund['status'] ?? '');
        if (!str_starts_with((string) ($refund['id'] ?? ''), 're_')) {
            return CallbackResult::ignored('Stripe did not accept the refund');
        }
        if ($status === 'succeeded') {
            return CallbackResult::refunded($order->getId(), (string) $refund['id']);
        }
        if ($status === 'pending' || $status === 'requires_action') {
            // The charge.refunded webhook settles the order once Stripe finishes.
            return CallbackResult::ignored('Stripe is still processing the refund; the order updates when it completes.');
        }

        return CallbackResult::ignored('Stripe did not accept the refund' . ($status !== '' ? ' (' . $status . ')' : ''));
    }

    /**
     * The order's payment in the Stripe dashboard. Stripe documents no dashboard page for a
     * checkout session, so an order that only holds a session gets no link.
     *
     * @param Order $order
     *
     * @return string|null
     */
    public function dashboardUrl(Order $order): ?string
    {
        $ref = (string) $order->getExternalRef();
        if (!preg_match('/^(pi|cs)_[A-Za-z0-9_]+$/', $ref) || !str_starts_with($ref, 'pi_')) {
            return null;
        }
        if (!in_array($order->getStatus(), array(Order::STATUS_PAID, Order::STATUS_REFUNDED), true)) {
            return null;
        }

        // A payment intent id does not say its mode. Use the one stored when it was paid, else the current mode.
        $live = $order->meta(self::META_LIVEMODE);
        if (!is_bool($live)) {
            $live = $this->config->live();
        }

        return 'https://dashboard.stripe.com' . ($live ? '' : '/test') . '/payments/' . rawurlencode($ref);
    }

    /**
     * Fetch an event from Stripe and settle it. The queued job runs this.
     *
     * @param string $eventId
     *
     * @return CallbackResult
     * @throws Throwable when Stripe or the database fails, so the job is retried
     */
    public function processEvent(string $eventId): CallbackResult
    {
        if (!preg_match('/^evt_[A-Za-z0-9_]{1,250}$/', $eventId)) {
            return CallbackResult::ignored('malformed event id');
        }

        return ($this->settle)(array(self::TRUSTED => Verified::event($this->api->event($eventId))));
    }

    /**
     * Look a session up at Stripe and settle its order.
     *
     * @param string $sessionId
     * @param int    $orderId The order the session must belong to
     *
     * @return CallbackResult
     */
    public function processSession(string $sessionId, int $orderId): CallbackResult
    {
        if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{1,250}$/', $sessionId)) {
            return CallbackResult::ignored('malformed session id');
        }

        return ($this->settle)(array(self::TRUSTED => Verified::session($sessionId, $orderId)));
    }

    /**
     * Check pending orders Stripe never told us about.
     *
     * @param int $olderThan Seconds
     * @param int $limit
     *
     * @return int how many were looked up
     * @throws HttpException when any lookup failed, so the job is retried
     */
    public function reconcile(int $olderThan = 3600, int $limit = 100): int
    {
        $checked = 0;
        $errors  = array();
        $prefix  = 'cs_' . ($this->config->live() ? 'live' : 'test') . '_';
        foreach ($this->orders->stalePending($olderThan, $limit) as $order) {
            if (!str_starts_with((string) $order->getExternalRef(), $prefix)) {
                // Opened in the other mode: this mode's key cannot see it.
                continue;
            }
            try {
                $this->processSession((string) $order->getExternalRef(), $order->getId());
                $checked++;
            } catch (Throwable $e) {
                $errors[] = '#' . $order->getId() . ': ' . self::short($e->getMessage());
            }
        }
        if ($errors !== array()) {
            throw new HttpException('Some Stripe orders could not be checked: ' . implode('; ', $errors));
        }

        return $checked;
    }

    /**
     * The webhook: check the signature against the raw body, then settle the event.
     *
     * @return CallbackResult
     */
    private function webhook(): CallbackResult
    {
        [$body, $header] = ($this->input)();
        if (!Signature::verify($body, $header, $this->config->webhookSecret)) {
            return CallbackResult::ignored('bad signature');
        }

        $event = json_decode($body, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            return CallbackResult::ignored('malformed event');
        }
        if (($event['livemode'] ?? null) !== $this->config->live()) {
            return CallbackResult::ignored('event is for the other mode');
        }
        if (!in_array($event['type'], self::EVENTS, true)) {
            return CallbackResult::ignored('event not handled');
        }

        try {
            $result = ($this->settle)(array(self::TRUSTED => Verified::event($event)));
        } catch (Throwable $e) {
            try {
                ($this->queue)($event['id']);
            } catch (Throwable $ignored) {
                // The queue is down too. Stripe still retries the event.
            }

            return CallbackResult::ignored('could not settle, queued: ' . self::short($e->getMessage()), true);
        }

        return CallbackResult::ignored('settled as ' . $result->getOutcome());
    }

    /**
     * What one verified event means for its order.
     *
     * @param array<string,mixed> $event
     *
     * @return CallbackResult
     */
    private function fromEvent(array $event): CallbackResult
    {
        $object = $event['data']['object'] ?? null;
        if (!is_array($object)) {
            return CallbackResult::ignored('event has no object');
        }

        switch ($event['type'] ?? '') {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                return $this->fromSession($object, null);

            case 'checkout.session.async_payment_failed':
                return $this->sessionFailed($object, 'payment failed');

            case 'checkout.session.expired':
                return $this->sessionFailed($object, 'checkout expired');

            case 'charge.refunded':
                return $this->fromCharge($object);

            default:
                return CallbackResult::ignored('event not handled');
        }
    }

    /**
     * A session Stripe vouches for: paid, expired, or not yet either.
     *
     * @param array<string,mixed> $session
     * @param int|null            $expected The order the caller expects, if any
     *
     * @return CallbackResult
     */
    private function fromSession(array $session, ?int $expected): CallbackResult
    {
        $order = $this->sessionOrder($session, $expected);
        if ($order === null) {
            return CallbackResult::ignored('not an order of this site');
        }
        if (($session['status'] ?? '') === 'expired') {
            return $this->sessionFailed($session, 'checkout expired');
        }
        if (($session['payment_status'] ?? '') !== 'paid') {
            return CallbackResult::ignored('not paid yet');
        }

        $currency = strtoupper((string) ($session['currency'] ?? ''));
        $intent   = self::id($session['payment_intent'] ?? null);
        if ($order->isPending()) {
            $this->rememberMode($order, $session['livemode'] ?? null);
        }

        // Stripe's own figures; core refuses them when they differ from the order.
        return CallbackResult::paid(
            $order->getId(),
            $intent ?? (string) $session['id'],
            Money::toMicros((int) ($session['amount_total'] ?? -1), $currency),
            $currency
        );
    }

    /**
     * Store the mode a payment was made in, for the dashboard link. Done before core settles,
     * since the result does not carry it; it is Stripe's own data and never changes money or status.
     *
     * @param Order $order
     * @param mixed $livemode
     *
     * @return void
     */
    private function rememberMode(Order $order, $livemode): void
    {
        if (!is_bool($livemode)) {
            return;
        }
        try {
            if (!$this->orders->setMeta($order->getId(), self::META_LIVEMODE, $livemode)) {
                error_log('Stripe: could not store the mode on order #' . $order->getId());
            }
        } catch (Throwable $e) {
            error_log('Stripe: could not store the mode on order #' . $order->getId() . ': ' . $e->getMessage());
        }
    }

    /**
     * Close the session's order unpaid. Core does not compare money here, so this does.
     *
     * @param array<string,mixed> $session
     * @param string              $reason
     *
     * @return CallbackResult
     */
    private function sessionFailed(array $session, string $reason): CallbackResult
    {
        $order = $this->sessionOrder($session, null);
        if ($order === null || !$this->sameMoney($order, $session['amount_total'] ?? null, $session['currency'] ?? null)) {
            return CallbackResult::ignored('not an order of this site');
        }

        return CallbackResult::failed($order->getId(), $reason, (string) $session['id']);
    }

    /**
     * A charge refunded in full reverses its order. A partial refund changes nothing.
     *
     * @param array<string,mixed> $charge
     *
     * @return CallbackResult
     */
    private function fromCharge(array $charge): CallbackResult
    {
        if (($charge['refunded'] ?? false) !== true) {
            return CallbackResult::ignored('partial refund');
        }

        $intent = self::id($charge['payment_intent'] ?? null);
        $order  = $this->metaOrder($charge['metadata'] ?? null);
        if ($order === null && $intent !== null) {
            $order = $this->orders->findByRef($intent);
            if ($order === null) {
                $order = $this->metaOrder($this->api->paymentIntent($intent)['metadata'] ?? null);
            }
        }

        if ($order === null || !$this->sameMoney($order, $charge['amount'] ?? null, $charge['currency'] ?? null)) {
            return CallbackResult::ignored('not an order of this site');
        }

        return CallbackResult::refunded($order->getId(), $intent ?? (string) ($charge['id'] ?? ''));
    }

    /**
     * The order a session was opened for, when it is this site's and a Stripe order.
     *
     * @param array<string,mixed> $session
     * @param int|null            $expected
     *
     * @return Order|null
     */
    private function sessionOrder(array $session, ?int $expected): ?Order
    {
        if (!str_starts_with((string) ($session['id'] ?? ''), 'cs_')) {
            return null;
        }
        $ref   = (string) ($session['client_reference_id'] ?? '');
        $order = $this->metaOrder($session['metadata'] ?? null);
        if ($order === null || $ref !== (string) $order->getId()) {
            return null;
        }
        if ($expected !== null && $order->getId() !== $expected) {
            return null;
        }

        return $order;
    }

    /**
     * The order named by metadata this plugin wrote, or null for another site's.
     *
     * @param mixed $meta
     *
     * @return Order|null
     */
    private function metaOrder($meta): ?Order
    {
        if (!is_array($meta) || ($meta['site'] ?? null) !== $this->config->siteId) {
            return null;
        }
        $id = (string) ($meta['order_id'] ?? '');
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $id)) {
            return null;
        }
        $order = $this->orders->find((int) $id);

        return $order !== null && $order->getGateway() === self::ID ? $order : null;
    }

    /**
     * Whether Stripe's amount and currency are the order's.
     *
     * @param Order $order
     * @param mixed $minor
     * @param mixed $currency
     *
     * @return bool
     */
    private function sameMoney(Order $order, $minor, $currency): bool
    {
        return is_int($minor)
            && is_string($currency)
            && strtoupper($currency) === strtoupper($order->getCurrency())
            && Money::toMicros($minor, $currency) === $order->getAmount();
    }

    /**
     * The payment intent behind a paid order.
     *
     * @param Order $order
     *
     * @return string|null
     */
    private function paymentIntentOf(Order $order): ?string
    {
        $ref = (string) $order->getExternalRef();
        if (str_starts_with($ref, 'pi_')) {
            return $ref;
        }
        if (str_starts_with($ref, 'cs_')) {
            return self::id($this->api->session($ref)['payment_intent'] ?? null);
        }

        return null;
    }

    /**
     * An object's id, whether Stripe sent the id or the expanded object.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function id($value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Close the order and send the buyer back to choose again.
     *
     * @param Order  $order
     * @param string $reason
     *
     * @return CheckoutIntent
     */
    private function checkoutFailed(Order $order, string $reason): CheckoutIntent
    {
        error_log('Stripe: checkout for order #' . $order->getId() . ' failed: ' . self::short($reason));
        $this->orders->markFailed($order->getId());
        if (function_exists('osc_add_flash_error_message')) {
            osc_add_flash_error_message(__('Card payment could not be started. Please try again later.', 'stripe'));
        }

        return CheckoutIntent::redirect($this->config->cancelUrl);
    }

    /**
     * One line, at most 200 characters, for a log or the admin.
     *
     * @param string $message
     *
     * @return string
     */
    private static function short(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return strlen($message) > 200 ? substr($message, 0, 197) . '...' : $message;
    }
}
