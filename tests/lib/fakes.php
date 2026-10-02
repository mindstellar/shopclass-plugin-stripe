<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Stand-ins for Stripe and for core's order table.
 */

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\stripe\Config;
use mindstellar\stripe\Http\HttpClient;
use mindstellar\stripe\Http\HttpException;
use mindstellar\stripe\OrderStore;
use mindstellar\stripe\StripeGateway;

/** Answers from a script and records every request. */
final class FakeHttp implements HttpClient
{
    /** @var array<int,array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = array();

    /** @var array<string,array{status:int,body:string}|HttpException> "METHOD path" => answer */
    public array $routes = array();

    public function on(string $method, string $path, int $status, array $body): void
    {
        $this->routes[$method . ' ' . $path] = array('status' => $status, 'body' => json_encode($body));
    }

    public function down(string $method, string $path): void
    {
        $this->routes[$method . ' ' . $path] = new HttpException('Stripe could not be reached: timeout');
    }

    public function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        $path   = substr(strtok($url, '?'), strlen('https://api.stripe.com/v1/'));
        $answer = $this->routes[$method . ' ' . $path] ?? array('status' => 404, 'body' => '{"error":{"message":"No such object"}}');
        if ($answer instanceof HttpException) {
            throw $answer;
        }

        return $answer;
    }

    /** The last request's form body, decoded. */
    public function lastParams(): array
    {
        parse_str((string) end($this->requests)['body'], $params);

        return $params;
    }
}

/** Orders in an array. */
final class MemoryOrders implements OrderStore
{
    /** @var array<int,Order> */
    public array $orders = array();

    public array $emails = array();

    public function add(int $amount = 9_990_000, string $currency = 'USD', ?string $ref = null, string $status = Order::STATUS_PENDING, string $gateway = 'stripe', string $date = '2026-01-01 00:00:00', array $meta = array()): Order
    {
        $id                = count($this->orders) + 1;
        $this->orders[$id] = new Order($id, 7, $gateway, $ref, $amount, $currency, 100, $status, $meta, $date);

        return $this->orders[$id];
    }

    public function find(int $id): ?Order
    {
        return $this->orders[$id] ?? null;
    }

    public function findByRef(string $ref): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->getExternalRef() === $ref && $order->getGateway() === 'stripe') {
                return $order;
            }
        }

        return null;
    }

    /** As core's Orders::attachRef(): pending only, unique per gateway, false when nothing changed. */
    public function attachSession(int $orderId, string $sessionId): bool
    {
        $order = $this->orders[$orderId] ?? null;
        if ($order === null || !$order->isPending() || $order->getExternalRef() === $sessionId) {
            return false;
        }
        $owner = $this->findByRef($sessionId);
        if ($owner !== null && $owner->getId() !== $orderId) {
            return false;
        }
        $this->set($orderId, Order::STATUS_PENDING, $sessionId);

        return true;
    }

    /** @var bool|null true makes setMeta throw, false makes it refuse */
    public ?bool $metaFails = null;

    public function setMeta(int $orderId, string $key, $value): bool
    {
        if ($this->metaFails === true) {
            throw new RuntimeException('database is down');
        }
        $o = $this->orders[$orderId] ?? null;
        if ($o === null || $this->metaFails === false) {
            return false;
        }
        $meta = $o->getMeta();
        if ($value === null) {
            unset($meta[$key]);
        } else {
            $meta[$key] = $value;
        }
        $this->orders[$orderId] = new Order($o->getId(), $o->getUserId(), $o->getGateway(), $o->getExternalRef(), $o->getAmount(), $o->getCurrency(), $o->getCredits(), $o->getStatus(), $meta, $o->getDate());

        return true;
    }

    public function markFailed(int $orderId): void
    {
        $this->set($orderId, Order::STATUS_FAILED, null);
    }

    public function stalePending(int $seconds, int $limit): array
    {
        return array_values(array_filter($this->orders, static fn (Order $o): bool => $o->isPending()
            && str_starts_with((string) $o->getExternalRef(), 'cs_')
            && strtotime($o->getDate()) <= time() - $seconds));
    }

    public function buyerEmail(int $userId): ?string
    {
        return $this->emails[$userId] ?? null;
    }

    public function set(int $id, string $status, ?string $ref): void
    {
        $o                 = $this->orders[$id];
        $this->orders[$id] = new Order($o->getId(), $o->getUserId(), $o->getGateway(), $ref ?? $o->getExternalRef(), $o->getAmount(), $o->getCurrency(), $o->getCredits(), $status, $o->getMeta(), $o->getDate());
    }
}

const SITE = 'a1b2c3d4e5f60718';
const WHSEC = 'whsec_test_secret';

function fake_config(array $over = array()): Config
{
    $v = $over + array(
        'mode'       => 'test',
        'secret'     => 'sk_test_123',
        'webhook'    => WHSEC,
        'currencies' => array(),
        'billing'    => 'USD',
        'admin'      => true,
    );
    $admin = $v['admin'];

    return new Config(
        $v['mode'],
        $v['secret'],
        $v['webhook'],
        'Card (Stripe)',
        $v['currencies'],
        $v['billing'],
        SITE,
        static fn (int $id): string => 'https://shop.test/index.php?page=route&route=stripe-return&order=' . $id,
        'https://shop.test/buy',
        static fn (): bool => $admin
    );
}

/**
 * A gateway on fakes. $GLOBALS['settled'] records what reached the settle step, and
 * $GLOBALS['queued'] what was queued.
 */
function fake_gateway(FakeHttp $http, MemoryOrders $orders, array $config = array(), ?Closure $settle = null): StripeGateway
{
    $GLOBALS['settled'] = array();
    $GLOBALS['queued']  = array();
    $GLOBALS['input']   = array('', '');

    $gateway = null;
    $gateway = new StripeGateway(
        fake_config($config),
        $http,
        $orders,
        static fn (): array => $GLOBALS['input'],
        $settle ?? static function (array $request) use (&$gateway): CallbackResult {
            $result               = $gateway->handleCallback($request);
            $GLOBALS['settled'][] = $result;

            return $result;
        },
        static function (string $id): void {
            $GLOBALS['queued'][] = $id;
        }
    );

    return $gateway;
}

/** A checkout session as Stripe returns it. */
function session(Order $order, array $over = array()): array
{
    return $over + array(
        'id'                  => 'cs_test_abc' . $order->getId(),
        'object'              => 'checkout.session',
        'client_reference_id' => (string) $order->getId(),
        'metadata'            => array('order_id' => (string) $order->getId(), 'site' => SITE),
        'amount_total'        => 999,
        'currency'            => 'usd',
        'payment_status'      => 'paid',
        'status'              => 'complete',
        'payment_intent'      => 'pi_test_' . $order->getId(),
        'livemode'            => false,
        'url'                 => 'https://checkout.stripe.com/c/pay/cs_test_abc' . $order->getId(),
    );
}

function event(string $type, array $object, bool $live = false, string $id = 'evt_test_1'): array
{
    return array('id' => $id, 'object' => 'event', 'type' => $type, 'livemode' => $live, 'data' => array('object' => $object));
}

/** Put a signed webhook on the fake input. */
function deliver(array $event, string $secret = WHSEC, ?int $time = null): string
{
    $body             = json_encode($event);
    $GLOBALS['input'] = array($body, \mindstellar\stripe\Signature::sign($body, $secret, $time ?? time()));

    return $body;
}
