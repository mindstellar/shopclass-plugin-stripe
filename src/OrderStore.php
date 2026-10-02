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

use mindstellar\billing\Order;

/**
 * What the gateway reads and writes about orders. CoreOrderStore is the real one.
 */
interface OrderStore
{
    public function find(int $id): ?Order;

    /**
     * The Stripe order carrying this provider reference (a session or payment intent id).
     */
    public function findByRef(string $ref): ?Order;

    /**
     * Remember the checkout session on a pending order.
     *
     * @return bool false when the order is not pending, already holds it, or another order has it
     */
    public function attachSession(int $orderId, string $sessionId): bool;

    /**
     * Store one plugin meta value on the order. Other keys are kept.
     *
     * @param bool|int|float|string|null $value
     */
    public function setMeta(int $orderId, string $key, $value): bool;

    /**
     * Close a pending order whose checkout could not start.
     */
    public function markFailed(int $orderId): void;

    /**
     * Pending Stripe orders older than $seconds that have a session to check.
     *
     * @return Order[]
     */
    public function stalePending(int $seconds, int $limit): array;

    /**
     * The buyer's e-mail, when there is one.
     */
    public function buyerEmail(int $userId): ?string;
}
