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

use mindstellar\stripe\Http\HttpClient;
use mindstellar\stripe\Http\HttpException;

/**
 * The few Stripe REST calls the gateway makes. Every answer is decoded JSON.
 */
final class StripeApi
{
    public const BASE = 'https://api.stripe.com/v1/';

    /** Pinned so a change to the account's default API version cannot change these shapes. */
    public const VERSION = '2024-06-20';

    public function __construct(
        private HttpClient $http,
        private string $secretKey
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @param string              $idempotencyKey
     *
     * @return array<string,mixed>
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->call('POST', 'checkout/sessions', $params, $idempotencyKey);
    }

    /**
     * @param string $id cs_...
     *
     * @return array<string,mixed>
     */
    public function session(string $id): array
    {
        return $this->call('GET', 'checkout/sessions/' . rawurlencode($id));
    }

    /**
     * @param string $id evt_...
     *
     * @return array<string,mixed>
     */
    public function event(string $id): array
    {
        return $this->call('GET', 'events/' . rawurlencode($id));
    }

    /**
     * @param string $id pi_...
     *
     * @return array<string,mixed>
     */
    public function paymentIntent(string $id): array
    {
        return $this->call('GET', 'payment_intents/' . rawurlencode($id));
    }

    /**
     * @param array<string,mixed> $params
     * @param string              $idempotencyKey
     *
     * @return array<string,mixed>
     */
    public function createRefund(array $params, string $idempotencyKey): array
    {
        return $this->call('POST', 'refunds', $params, $idempotencyKey);
    }

    /**
     * @param string              $method
     * @param string              $path
     * @param array<string,mixed> $params
     * @param string|null         $idempotencyKey
     *
     * @return array<string,mixed>
     * @throws HttpException on a transport error, an error status or a body that is not JSON
     */
    private function call(string $method, string $path, array $params = array(), ?string $idempotencyKey = null): array
    {
        if ($this->secretKey === '') {
            throw new HttpException('No Stripe secret key is set');
        }

        $headers = array(
            'Authorization'  => 'Bearer ' . $this->secretKey,
            'Stripe-Version' => self::VERSION,
            'Content-Type'   => 'application/x-www-form-urlencoded',
        );
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url  = self::BASE . $path;
        $body = null;
        if ($method === 'POST') {
            $body = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        } elseif ($params !== array()) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        }

        $response = $this->http->send($method, $url, $headers, $body);
        $decoded  = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            throw new HttpException('Stripe answered ' . $response['status'] . ($message !== '' ? ': ' . $message : ''), $response['status']);
        }
        if (!is_array($decoded)) {
            throw new HttpException('Stripe sent a response that is not JSON');
        }

        return $decoded;
    }
}
