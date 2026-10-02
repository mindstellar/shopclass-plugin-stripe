<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Refunds asked for from the admin.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\RefundableGateway;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', 'pi_test_1', 'paid');

harness_section('refund');

check('the gateway offers refunds', $gateway instanceof RefundableGateway);

$http->on('POST', 'refunds', 200, array('id' => 're_1', 'object' => 'refund', 'status' => 'succeeded'));
$r   = $gateway->refund($order);
$req = end($http->requests);
pin('an accepted refund refunds the order', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('with the refund id', array(1, 're_1'), array($r->getOrderId(), $r->getExternalRef()));
pin('of the order\'s payment intent', 'pi_test_1', $http->lastParams()['payment_intent']);
pin('with an idempotency key, so a double click refunds once', 'refund-order-1-' . SITE, $req['headers']['Idempotency-Key']);

foreach (array('pending', 'requires_action') as $status) {
    $http->on('POST', 'refunds', 200, array('id' => 're_2', 'object' => 'refund', 'status' => $status));
    $r = $gateway->refund($order);
    pin('a ' . $status . ' refund leaves the order for the webhook', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
    pin('and says so', 'Stripe is still processing the refund; the order updates when it completes.', $r->getMessage());
}

$http->on('POST', 'refunds', 200, array('id' => 're_3', 'object' => 'refund', 'status' => 'failed'));
$r = $gateway->refund($order);
pin('a failed refund changes nothing', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
pin('and says why', 'Stripe did not accept the refund (failed)', $r->getMessage());

$http->on('POST', 'refunds', 400, array('error' => array('message' => 'Charge ch_1 has already been refunded.')));
$r = $gateway->refund($order);
pin('a refusal is ignored', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
pin('with Stripe\'s reason, and that Stripe repeats it for 24 hours', 'Stripe answered 400: Charge ch_1 has already been refunded. Stripe gives the same answer for this order for 24 hours; refund it in the Stripe dashboard instead.', $r->getMessage());

$http->down('POST', 'refunds');
pin('Stripe down is ignored with a reason, and can be tried again', 'Stripe could not be reached: timeout', $gateway->refund($order)->getMessage());

$http->on('POST', 'refunds', 200, array('id' => 're_5', 'object' => 'refund', 'status' => 'canceled'));
pin('a canceled refund changes nothing', CallbackResult::OUTCOME_IGNORED, $gateway->refund($order)->getOutcome());

$old = $orders->add(9_990_000, 'USD', 'cs_test_old', 'paid');
$http->on('GET', 'checkout/sessions/cs_test_old', 200, array('id' => 'cs_test_old', 'payment_intent' => 'pi_test_old'));
$http->on('POST', 'refunds', 200, array('id' => 're_4', 'status' => 'succeeded'));
$gateway->refund($old);
pin('an order holding only a session id is refunded through its payment intent', 'pi_test_old', $http->lastParams()['payment_intent']);

$none  = $orders->add(9_990_000, 'USD', null, 'paid');
$count = count($http->requests);
pin('an order with no Stripe payment is refused', 'no Stripe payment found for this order', $gateway->refund($none)->getMessage());
pin('without calling Stripe', $count, count($http->requests));

exit(harness_result());
