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
 * Checks a Stripe-Signature header: t=<time>,v1=<hmac>[,v1=...] over "<time>.<raw body>".
 */
final class Signature
{
    /** How old a signed event may be, in seconds. */
    public const TOLERANCE = 300;

    /**
     * Whether $header signs $payload with $secret, within the tolerance.
     *
     * @param string   $payload   The raw request body, byte for byte
     * @param string   $header    The Stripe-Signature header
     * @param string   $secret    The endpoint's signing secret (whsec_...)
     * @param int|null $now       Current time, for tests
     * @param int      $tolerance Seconds
     *
     * @return bool
     */
    public static function verify(string $payload, string $header, string $secret, ?int $now = null, int $tolerance = self::TOLERANCE): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $time       = null;
        $signatures = array();
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't' && ctype_digit($pair[1])) {
                $time = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($time === null || $signatures === array()) {
            return false;
        }
        if (abs(($now ?? time()) - $time) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $time . '.' . $payload, $secret);
        $match    = false;
        foreach ($signatures as $signature) {
            // Compare every one, so the time taken does not say which matched.
            $match = hash_equals($expected, $signature) || $match;
        }

        return $match;
    }

    /**
     * A header as Stripe would send it. For tests and local replays.
     *
     * @param string $payload
     * @param string $secret
     * @param int    $time
     *
     * @return string
     */
    public static function sign(string $payload, string $secret, int $time): string
    {
        return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, $secret);
    }
}
