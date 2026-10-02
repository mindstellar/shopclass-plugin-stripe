<?php

/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\stripe\Money;
use mindstellar\stripe\Plugin;
use mindstellar\stripe\StripeGateway;

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's
 * capability, validates each field and stores it under the "stripe" section.
 */

$secret = static function (string $name, string $label, string $prefix, string $help): array {
    return array(
        'type'        => 'secret',
        'name'        => $name,
        'label'       => $label,
        'help'        => $help,
        'placeholder' => $prefix . '…',
        'masked'      => true,
        'reveal'      => true,
        'write_only'  => false,
        'attrs'       => array('autocomplete' => 'new-password', 'spellcheck' => 'false'),
        // A blank box keeps the stored key.
        'persist'     => static fn ($value) => trim((string) $value) === '' ? null : trim((string) $value),
        'validate'    => static function ($value) use ($prefix) {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            $prefixes = $prefix === 'whsec_' ? array('whsec_') : array($prefix, 'r' . substr($prefix, 1));

            foreach ($prefixes as $p) {
                if (str_starts_with($value, $p)) {
                    return null;
                }
            }

            return sprintf(__('This should start with "%s"', 'stripe'), $prefix);
        },
    );
};

$webhook = static function (): void {
    echo '<p><code>' . osc_esc_html(Plugin::webhookUrl()) . '</code></p>';
    echo '<p>' . osc_esc_html(__('In Stripe, open Developers > Webhooks, add an endpoint with this URL, and select these events:', 'stripe')) . '</p><ul>';
    foreach (StripeGateway::EVENTS as $event) {
        echo '<li><code>' . osc_esc_html($event) . '</code></li>';
    }
    echo '</ul><p>' . osc_esc_html(__('Then copy the endpoint\'s signing secret into the webhook secret field for the same mode.', 'stripe')) . '</p>';
};

return array(
    'title'  => __('Stripe Payment', 'stripe'),
    'menu'   => 'plugins',
    'intro'  => __('Take card payments for credit packages through Stripe Checkout. Buyers pay on a page hosted by Stripe, so no card data reaches this site.', 'stripe'),
    'groups' => array(
        array(
            'title'  => __('Account', 'stripe'),
            'fields' => array(
                array(
                    'type'    => 'radio',
                    'name'    => 'mode',
                    'label'   => __('Mode', 'stripe'),
                    'help'    => __('Test mode uses your test keys and Stripe\'s test cards. No real money moves.', 'stripe'),
                    'options' => array(
                        'test' => __('Test', 'stripe'),
                        'live' => __('Live', 'stripe'),
                    ),
                    'default' => 'test',
                ),
                $secret('test_secret_key', __('Test secret key', 'stripe'), 'sk_test_', __('From Developers > API keys, with "Viewing test data" on. Leave blank to keep the saved key.', 'stripe')),
                $secret('test_webhook_secret', __('Test webhook secret', 'stripe'), 'whsec_', __('The signing secret of your test webhook endpoint. Leave blank to keep the saved one.', 'stripe')),
                $secret('live_secret_key', __('Live secret key', 'stripe'), 'sk_live_', __('From Developers > API keys. A restricted key needs write access to Checkout Sessions and Refunds, and read access to Events and PaymentIntents.', 'stripe')),
                $secret('live_webhook_secret', __('Live webhook secret', 'stripe'), 'whsec_', __('The signing secret of your live webhook endpoint. Leave blank to keep the saved one.', 'stripe')),
            ),
        ),
        array(
            'title'  => __('Webhook', 'stripe'),
            'fields' => array(
                array(
                    'type'   => 'custom',
                    'name'   => 'webhook_info',
                    'label'  => __('Endpoint URL', 'stripe'),
                    'render' => $webhook,
                ),
            ),
        ),
        array(
            'title'  => __('Checkout', 'stripe'),
            'fields' => array(
                array(
                    'type'      => 'text',
                    'name'      => 'name',
                    'label'     => __('Name at checkout', 'stripe'),
                    'default'   => __('Card (Stripe)', 'stripe'),
                    'maxlength' => 60,
                    'required'  => true,
                ),
                array(
                    'type'        => 'text',
                    'name'        => 'currencies',
                    'label'       => __('Currencies', 'stripe'),
                    'placeholder' => 'USD, EUR',
                    'help'        => __('Comma-separated codes. Leave empty to use the billing currency.', 'stripe'),
                    'sanitize'    => static function ($value) {
                        $codes = array_filter(array_map('trim', explode(',', strtoupper((string) $value))));

                        return implode(', ', array_unique($codes));
                    },
                    'validate'    => static function ($value) {
                        foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $code) {
                            if (!in_array($code, Money::SUPPORTED, true)) {
                                return sprintf(__('Stripe does not take "%s"', 'stripe'), $code);
                            }
                        }

                        return null;
                    },
                ),
            ),
        ),
    ),
);
