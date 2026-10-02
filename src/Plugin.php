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
use mindstellar\billing\Order;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\job\Job;
use mindstellar\settings\SettingsPageRegistry;
use mindstellar\stripe\Http\CurlClient;
use Params;
use Throwable;

/**
 * The wiring: registration, the return page, background jobs and admin notices.
 */
final class Plugin
{
    /** Settings page id, and the preference section its values live in. */
    public const PAGE = 'stripe';

    /** Route the buyer comes back to from Stripe Checkout. */
    public const ROUTE_RETURN = 'stripe-return';

    /** Job that re-fetches one event from Stripe and settles it. */
    public const JOB_EVENT = 'stripe.event';

    /** Daily job that checks pending orders Stripe never told us about. */
    public const JOB_RECONCILE = 'stripe.reconcile';

    /** fn(): StripeGateway -- replaces the real gateway in tests. */
    public static ?Closure $factory = null;

    /**
     * Make this install's site id, unless one survives from an earlier install.
     *
     * @return void
     */
    public static function install(): void
    {
        Config::siteId();
    }

    /**
     * Remove the stored settings. The site id stays, so orders still in flight settle after a reinstall.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        foreach (array_keys(SettingsPageRegistry::instance()->fields(self::PAGE)) as $name) {
            osc_delete_preference($name, self::PAGE);
        }
    }

    /**
     * The gateway, built on the current settings.
     *
     * @return StripeGateway
     */
    public static function gateway(): StripeGateway
    {
        if (self::$factory !== null) {
            return (self::$factory)();
        }

        return new StripeGateway(Config::fromSettings(), new CurlClient(), new CoreOrderStore());
    }

    /**
     * Register the gateway while billing is on, and hand it back.
     *
     * @return StripeGateway|null
     */
    public static function register(): ?StripeGateway
    {
        if (!osc_billing_enabled()) {
            return null;
        }
        $gateway = self::gateway();
        PaymentGatewayRegistry::instance()->register($gateway);

        return $gateway;
    }

    /**
     * The URL to paste into Stripe as the webhook endpoint.
     *
     * @return string
     */
    public static function webhookUrl(): string
    {
        return osc_base_url(true) . '?page=billing&action=callback&gateway=' . StripeGateway::ID;
    }

    /**
     * The buyer is back from Stripe Checkout. Look the session up at Stripe and settle it.
     *
     * @return void
     */
    public static function returnPage(): void
    {
        if (!osc_is_web_user_logged_in()) {
            osc_redirect_to(osc_user_login_url());
        }

        self::settleReturn(
            self::register(),
            new CoreOrderStore(),
            Params::getParamInt('order'),
            (int) osc_logged_user_id(),
            Params::getParamString('session_id')
        );
        osc_redirect_to(osc_billing_enabled() ? osc_billing_orders_url() : osc_base_url());
    }

    /**
     * The return page's work: check the buyer owns the order, settle it, flash where it stands.
     * Stripe is asked only for a pending order whose stored session is the one in the URL.
     *
     * @param StripeGateway|null $gateway   Null while billing is off
     * @param OrderStore         $orders
     * @param int                $orderId
     * @param int                $userId    The signed-in buyer
     * @param string             $sessionId From the URL
     *
     * @return string the order's status afterwards, or '' when it was refused
     */
    public static function settleReturn(?StripeGateway $gateway, OrderStore $orders, int $orderId, int $userId, string $sessionId): string
    {
        $order = $orders->find($orderId);
        if ($gateway === null
            || $order === null
            || $order->getUserId() !== $userId
            || $order->getGateway() !== StripeGateway::ID
        ) {
            osc_add_flash_error_message(__('That order is not available.', 'stripe'));

            return '';
        }

        $before = $order->getStatus();
        $ref    = (string) $order->getExternalRef();
        if ($order->isPending() && $sessionId !== '' && hash_equals($ref, $sessionId)) {
            try {
                $gateway->processSession($sessionId, $order->getId());
            } catch (Throwable $e) {
                // Stripe or the database did not answer. The webhook and the daily check settle it later.
                error_log('Stripe: return page for order #' . $order->getId() . ': ' . $e->getMessage());
            }
        }

        $now   = $orders->find($order->getId());
        $after = $now === null ? '' : $now->getStatus();
        self::flashOutcome($order->getId(), $before, $after);

        return $after;
    }

    /**
     * Tell the buyer where their order stands.
     *
     * @param int    $orderId
     * @param string $before Status before the return page ran
     * @param string $after  Status now
     *
     * @return void
     */
    public static function flashOutcome(int $orderId, string $before, string $after): void
    {
        if ($after === Order::STATUS_PAID) {
            osc_add_flash_ok_message($before === Order::STATUS_PAID
                ? sprintf(__('Order #%d is paid.', 'stripe'), $orderId)
                : sprintf(__('Payment for order #%d received. Credits added.', 'stripe'), $orderId));
        } elseif ($after === Order::STATUS_PENDING) {
            osc_add_flash_info_message(sprintf(__('Payment for order #%d is processing. Credits are added once Stripe confirms it.', 'stripe'), $orderId));
        } else {
            osc_add_flash_warning_message(sprintf(__('Order #%d was not paid.', 'stripe'), $orderId));
        }
    }

    /**
     * Register the job handlers. Cron does not run init, so each registers the gateway itself.
     *
     * @return void
     */
    public static function registerJobs(): void
    {
        osc_job_register_handler(self::JOB_EVENT, static function (Job $job): void {
            $gateway = self::register();
            if ($gateway === null) {
                throw new \RuntimeException('billing is switched off');
            }
            $gateway->processEvent((string) $job->get('event', ''));
        });
        osc_job_register_handler(self::JOB_RECONCILE, static function (Job $job): void {
            $gateway = self::register();
            if ($gateway !== null && $gateway->hasKeys()) {
                $gateway->reconcile();
            }
        });

        osc_job_describe(self::JOB_EVENT, __('Record a Stripe payment', 'stripe'), static function (array $payload): string {
            return (string) ($payload['event'] ?? '');
        });
        osc_job_describe(self::JOB_RECONCILE, __('Check pending Stripe orders', 'stripe'));
    }

    /**
     * Queue the daily check, once.
     *
     * @return void
     */
    public static function daily(): void
    {
        if (osc_billing_enabled()) {
            osc_job_ensure(self::JOB_RECONCILE);
        }
    }

    /**
     * Mail the site's contact address when a Stripe job stops retrying.
     *
     * @param string $type
     * @param mixed  $payload
     * @param string $error
     * @param int    $id
     *
     * @return void
     */
    public static function jobGaveUp($type, $payload = array(), $error = '', $id = 0): void
    {
        if ($type !== self::JOB_EVENT && $type !== self::JOB_RECONCILE) {
            return;
        }

        $event = is_array($payload) ? (string) ($payload['event'] ?? '') : '';
        $lines = array(
            __('A Stripe payment could not be recorded after several tries.', 'stripe'),
            $event !== '' ? sprintf(__('Stripe event: %s', 'stripe'), $event) : '',
            sprintf(__('Error: %s', 'stripe'), (string) $error),
            sprintf(__('Retry it under Tools > System info > Jobs (job #%d), and check the order in Billing > Orders.', 'stripe'), (int) $id),
        );
        $body = '';
        foreach (array_filter($lines) as $line) {
            $body .= '<p>' . osc_esc_html($line) . '</p>';
        }

        error_log('Stripe: job #' . (int) $id . ' (' . $type . ') gave up: ' . $error);
        try {
            osc_sendMail(array(
                'to'      => osc_contact_email(),
                'subject' => __('Stripe: a payment needs checking', 'stripe'),
                'body'    => $body,
            ));
        } catch (Throwable $e) {
            error_log('Stripe: could not mail the admin: ' . $e->getMessage());
        }
    }

    /**
     * A notice on every admin page while test mode is on.
     *
     * @return void
     */
    public static function adminNotice(): void
    {
        if (!osc_billing_enabled() || osc_settings_value(self::PAGE, 'mode') === 'live') {
            return;
        }
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Stripe is in test mode: no real money is taken. Switch to live mode before the site takes payments.', 'stripe'))
            . ' <a href="' . osc_esc_html(osc_settings_page_url(self::PAGE)) . '">'
            . osc_esc_html(__('Stripe settings', 'stripe')) . '</a></div>';
    }
}
