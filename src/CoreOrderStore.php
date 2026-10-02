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
use mindstellar\billing\Orders;

/**
 * Orders through core's own Orders model.
 */
final class CoreOrderStore implements OrderStore
{
    public function find(int $id): ?Order
    {
        return Orders::find($id);
    }

    public function findByRef(string $ref): ?Order
    {
        return Orders::findByGatewayRef(StripeGateway::ID, $ref);
    }

    public function attachSession(int $orderId, string $sessionId): bool
    {
        return Orders::attachRef($orderId, StripeGateway::ID, $sessionId);
    }

    public function setMeta(int $orderId, string $key, $value): bool
    {
        return Orders::setMeta($orderId, $key, $value);
    }

    public function markFailed(int $orderId): void
    {
        Orders::settle($orderId, Order::STATUS_FAILED);
    }

    public function stalePending(int $seconds, int $limit): array
    {
        $cutoff = time() - $seconds;
        $out    = array();
        $filter = array('status' => Order::STATUS_PENDING, 'gateway' => StripeGateway::ID);
        foreach (Orders::search($filter, $limit) as $order) {
            $ref = (string) $order->getExternalRef();
            if (str_starts_with($ref, 'cs_') && strtotime($order->getDate()) <= $cutoff) {
                $out[] = $order;
            }
        }

        return $out;
    }

    public function buyerEmail(int $userId): ?string
    {
        $user  = osc_db_table(DB_TABLE_PREFIX . 't_user')->where('pk_i_id', $userId)->first();
        $email = is_array($user) ? (string) ($user['s_email'] ?? '') : '';

        return $email === '' ? null : $email;
    }
}
