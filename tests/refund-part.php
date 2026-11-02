<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Part refunds asked for from the admin (Shopclass 7.1).
 */

const PARTIAL_REFUND_STUB = true;

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\PartialRefundableGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', 'pi_test_1', 'paid');

harness_section('refund part');

check('the gateway offers part refunds', $gateway instanceof PartialRefundableGateway);

$http->on('POST', 'refunds', 200, array('id' => 're_p1', 'object' => 'refund', 'status' => 'succeeded'));
$r      = $gateway->refundPart($order, 2_500_000, 'order:1:refund:1');
$req    = end($http->requests);
$params = $http->lastParams();
pin('an accepted part refund is reported as refunded', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('with the refund id', array(1, 're_p1'), array($r->getOrderId(), $r->getExternalRef()));
pin('of the order\'s payment intent', 'pi_test_1', $params['payment_intent']);
pin('for the amount in cents', '250', $params['amount'] ?? null);
pin('with the order, site and core\'s key in the metadata', array('order_id' => '1', 'site' => SITE, 'refund_key' => 'order:1:refund:1'), $params['metadata']);
pin('with core\'s key as the idempotency key, per site', 'refund-order:1:refund:1-' . SITE, $req['headers']['Idempotency-Key']);

$gateway->refundPart($order, 2_500_000, 'order:1:refund:2');
pin('the next part refund has its own key', 'refund-order:1:refund:2-' . SITE, end($http->requests)['headers']['Idempotency-Key']);

$http->on('POST', 'refunds', 200, array('id' => 're_p2', 'object' => 'refund', 'status' => 'pending'));
$r = $gateway->refundPart($order, 1_000_000, 'order:1:refund:3');
pin('a pending part refund is accepted with its id', array(CallbackResult::OUTCOME_PENDING, 're_p2', 'Stripe is still processing the refund.'), array($r->getOutcome(), $r->getExternalRef(), $r->getMessage()));

$http->on('POST', 'refunds', 200, array('id' => 're_p3', 'object' => 'refund', 'status' => 'failed'));
pin('a failed part refund changes nothing', array(CallbackResult::OUTCOME_IGNORED, 'Stripe did not accept the refund (failed)'), array(
    $gateway->refundPart($order, 1_000_000, 'order:1:refund:4')->getOutcome(),
    $gateway->refundPart($order, 1_000_000, 'order:1:refund:4')->getMessage(),
));

$http->on('POST', 'refunds', 400, array('error' => array('message' => 'Refund amount ($99.00) is greater than unrefunded amount on charge ($7.49)')));
pin('a refusal comes back with Stripe\'s reason', 'Stripe answered 400: Refund amount ($99.00) is greater than unrefunded amount on charge ($7.49)', $gateway->refundPart($order, 9_000_000, 'order:1:refund:5')->getMessage());

$throws = static function (callable $fn): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return true;
    }

    return false;
};
$http->down('POST', 'refunds');
check('Stripe down throws, so core keeps the key for the retry', $throws(static fn () => $gateway->refundPart($order, 1_000_000, 'order:1:refund:6')));
$http->on('POST', 'refunds', 503, array('error' => array('message' => 'Server error')));
check('a Stripe 5xx throws too', $throws(static fn () => $gateway->refundPart($order, 1_000_000, 'order:1:refund:6')));

harness_section('refused before Stripe is asked');

$http->on('POST', 'refunds', 200, array('id' => 're_p9', 'status' => 'succeeded'));
$count = count($http->requests);
foreach (array(0, -1_000_000, 9_990_001) as $amount) {
    pin('amount ' . $amount . ' is refused', 'the refund must be more than 0 and at most the order total', $gateway->refundPart($order, $amount, 'order:1:refund:7')->getMessage());
}
pin('a part of a cent is refused', 'amount cannot be refunded in USD', $gateway->refundPart($order, 1_234_567, 'order:1:refund:7')->getMessage());
foreach (array('', 'order 1', str_repeat('k', 41)) as $key) {
    pin('key "' . $key . '" is refused', 'malformed refund key', $gateway->refundPart($order, 1_000_000, $key)->getMessage());
}
$none = $orders->add(9_990_000, 'USD', null, 'paid');
pin('an order with no Stripe payment is refused', 'no Stripe payment found for this order', $gateway->refundPart($none, 1_000_000, 'order:2:refund:1')->getMessage());
pin('without calling Stripe', $count, count($http->requests));

harness_section('other currencies');

$yen = $orders->add(1_000_000_000, 'JPY', 'pi_test_yen', 'paid');
$gateway->refundPart($yen, 300_000_000, 'order:3:refund:1');
pin('a zero-decimal currency is sent in whole units', '300', $http->lastParams()['amount']);

$old = $orders->add(9_990_000, 'USD', 'cs_test_old', 'paid');
$http->on('GET', 'checkout/sessions/cs_test_old', 200, array('id' => 'cs_test_old', 'payment_intent' => 'pi_test_old'));
$gateway->refundPart($old, 1_000_000, 'order:4:refund:1');
pin('an order holding only a session id is refunded through its payment intent', 'pi_test_old', $http->lastParams()['payment_intent']);

exit(harness_result());
