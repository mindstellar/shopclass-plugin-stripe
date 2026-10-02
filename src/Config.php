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

/**
 * The settings the gateway runs on, read once per request. Tests build one by hand.
 */
final class Config
{
    /**
     * @param string   $mode            'test' or 'live'
     * @param string   $secretKey       The active mode's secret key
     * @param string   $webhookSecret   The active mode's webhook signing secret
     * @param string   $name            Name at checkout
     * @param string[] $currencies      Codes the admin allows; empty means every one Stripe takes
     * @param string   $billingCurrency The site's billing currency
     * @param string   $siteId          Random id of this install, so two sites can share one Stripe account
     * @param Closure  $returnUrl       fn(int $orderId): string
     * @param string   $cancelUrl       Where a buyer who backs out lands
     * @param Closure|null $isAdmin     fn(): bool, whether an admin is signed in; test mode is offered only to them
     */
    public function __construct(
        public string $mode,
        public string $secretKey,
        public string $webhookSecret,
        public string $name,
        public array $currencies,
        public string $billingCurrency,
        public string $siteId,
        public Closure $returnUrl,
        public string $cancelUrl,
        public ?Closure $isAdmin = null
    ) {
    }

    /**
     * Read the saved settings.
     *
     * @return self
     */
    public static function fromSettings(): self
    {
        $mode = self::value('mode') === 'live' ? 'live' : 'test';

        return new self(
            $mode,
            trim((string) self::value($mode . '_secret_key')),
            trim((string) self::value($mode . '_webhook_secret')),
            (string) self::value('name'),
            self::parseCurrencies((string) self::value('currencies')),
            strtoupper((string) osc_billing_currency()),
            self::siteId(),
            static fn (int $orderId): string => osc_route_url(Plugin::ROUTE_RETURN, array('order' => $orderId)),
            osc_billing_buy_url(),
            static fn (): bool => function_exists('osc_is_admin_user_logged_in') && (bool) osc_is_admin_user_logged_in()
        );
    }

    /**
     * Whether payments are real.
     *
     * @return bool
     */
    public function live(): bool
    {
        return $this->mode === 'live';
    }

    /**
     * Comma-separated codes to a clean list: upper case, three letters, ones Stripe takes.
     *
     * @param string $list
     *
     * @return string[]
     */
    public static function parseCurrencies(string $list): array
    {
        $codes = array();
        foreach (explode(',', strtoupper($list)) as $code) {
            $code = trim($code);
            if (in_array($code, Money::SUPPORTED, true)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * This install's id, kept in the plugin's preference section across reinstalls.
     * Made by install(); the insert-if-absent keeps two first requests from making two.
     *
     * @return string
     */
    public static function siteId(): string
    {
        $id = (string) osc_get_preference('site_id', Plugin::PAGE);
        if (preg_match('/^[a-f0-9]{16}$/', $id)) {
            return $id;
        }

        $table = DB_TABLE_PREFIX . 't_preference';
        osc_db_execute(
            'INSERT IGNORE INTO ' . $table . " (s_section, s_name, s_value, e_type) VALUES (?, 'site_id', ?, 'STRING')",
            array(Plugin::PAGE, bin2hex(random_bytes(8)))
        );
        $id = (string) osc_db_scalar('SELECT s_value FROM ' . $table . " WHERE s_section = ? AND s_name = 'site_id'", array(Plugin::PAGE));
        if (function_exists('osc_reset_preferences')) {
            osc_reset_preferences();
        }

        return $id;
    }

    /**
     * One saved setting, or its declared default.
     *
     * @param string $name
     *
     * @return mixed
     */
    private static function value(string $name)
    {
        return osc_settings_value(Plugin::PAGE, $name);
    }
}
