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
 * Orders through core's own order store.
 */
final class CoreOrderStore implements OrderStore
{
    /**
     * Core's order store: OrderStore from Shopclass 7.0, Orders before it.
     *
     * @return class-string
     */
    private static function orders(): string
    {
        return class_exists('mindstellar\\billing\\OrderStore') ? 'mindstellar\\billing\\OrderStore' : 'mindstellar\\billing\\Orders';
    }

    public function find(int $id): ?Order
    {
        return self::orders()::find($id);
    }

    public function findByRef(string $ref): ?Order
    {
        return self::orders()::findByGatewayRef(StripeGateway::ID, $ref);
    }

    public function attachSession(int $orderId, string $sessionId): bool
    {
        return self::orders()::attachRef($orderId, StripeGateway::ID, $sessionId);
    }

    public function setMeta(int $orderId, string $key, $value): bool
    {
        return self::orders()::setMeta($orderId, $key, $value);
    }

    public function markFailed(int $orderId): void
    {
        self::orders()::settle($orderId, Order::STATUS_FAILED);
    }

    public function stalePending(int $seconds, int $limit): array
    {
        $orders = self::orders();
        if (method_exists($orders, 'pendingBefore')) {
            return $orders::pendingBefore(StripeGateway::ID, date('Y-m-d H:i:s', time() - $seconds + 1), $limit, 'cs_');
        }
        $cutoff = time() - $seconds;
        $out    = array();
        $filter = array('status' => Order::STATUS_PENDING, 'gateway' => StripeGateway::ID);
        foreach ($orders::search($filter, $limit) as $order) {
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
