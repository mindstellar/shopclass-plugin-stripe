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

/**
 * Converts between core's micros (value x 1,000,000) and Stripe's minor units.
 */
final class Money
{
    /** Currencies whose Stripe amount is in whole units. UGX is not one: see WHOLE_ONLY. */
    public const ZERO_DECIMAL = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    );

    /** Currencies Stripe charges in thousandths. Stripe wants the last digit to be 0. */
    public const THREE_DECIMAL = array('BHD', 'JOD', 'KWD', 'OMR', 'TND');

    /**
     * Whole-unit currencies Stripe still takes as two-decimal amounts ending in 00.
     * HUF and TWD charge two-decimal amounts as normal; their whole-unit rule is for payouts.
     */
    public const WHOLE_ONLY = array('ISK', 'UGX');

    /** Every currency Stripe can charge in, from its supported currencies list. */
    public const SUPPORTED = array(
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT',
        'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF',
        'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB',
        'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG',
        'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW',
        'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK',
        'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR',
        'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB',
        'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SZL', 'THB', 'TJS',
        'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VND', 'VUV',
        'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    );

    /**
     * How many decimal places Stripe's minor unit has for $currency.
     *
     * @param string $currency ISO 4217, any case
     *
     * @return int
     */
    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        return in_array($currency, self::THREE_DECIMAL, true) ? 3 : 2;
    }

    /**
     * Micros to Stripe's minor units, or null when Stripe cannot charge that exact amount.
     *
     * @param int    $micros
     * @param string $currency
     *
     * @return int|null
     */
    public static function toMinor(int $micros, string $currency): ?int
    {
        $currency = strtoupper($currency);
        $divisor  = 10 ** (6 - self::decimals($currency));
        if ($micros < 0 || $micros % $divisor !== 0) {
            return null;
        }
        $minor = intdiv($micros, $divisor);

        if (in_array($currency, self::THREE_DECIMAL, true) && $minor % 10 !== 0) {
            return null;
        }
        if (in_array($currency, self::WHOLE_ONLY, true) && $minor % 100 !== 0) {
            return null;
        }

        return $minor;
    }

    /**
     * Stripe's minor units to micros.
     *
     * @param int    $minor
     * @param string $currency
     *
     * @return int
     */
    public static function toMicros(int $minor, string $currency): int
    {
        return $minor * 10 ** (6 - self::decimals($currency));
    }
}
