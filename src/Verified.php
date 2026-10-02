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
 * Data the plugin trusts: a checked or fetched event, or a session id to look up at Stripe.
 * An HTTP request cannot carry an object, so a forged callback can never pass one.
 */
final class Verified
{
    public const EVENT   = 'event';
    public const SESSION = 'session';

    /**
     * @param string              $kind    One of the constants
     * @param array<string,mixed> $event   The event, for EVENT
     * @param string              $session The session id, for SESSION
     * @param int|null            $orderId The order the session must belong to, for SESSION
     */
    private function __construct(
        public string $kind,
        public array $event = array(),
        public string $session = '',
        public ?int $orderId = null
    ) {
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return self
     */
    public static function event(array $event): self
    {
        return new self(self::EVENT, $event);
    }

    /**
     * @param string $sessionId cs_...
     * @param int    $orderId
     *
     * @return self
     */
    public static function session(string $sessionId, int $orderId): self
    {
        return new self(self::SESSION, array(), $sessionId, $orderId);
    }
}
