<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The webhook: what each event means for its order, and what is refused.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\stripe\StripeGateway;
use mindstellar\stripe\Verified;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', 'cs_test_abc1');

$decide = static fn (array $event): CallbackResult => $gateway->handleCallback(array(StripeGateway::TRUSTED => Verified::event($event)));

harness_section('event mapping');

$r = $decide(event('checkout.session.completed', session($order)));
pin('completed and paid is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('for the order', $order->getId(), $r->getOrderId());
pin('with Stripe\'s amount in micros', 9_990_000, $r->getAmount());
pin('and currency', 'USD', $r->getCurrency());
pin('the payment intent is the reference', 'pi_test_1', $r->getExternalRef());

$r = $decide(event('checkout.session.completed', session($order, array('payment_status' => 'unpaid'))));
pin('completed but unpaid (a bank debit) waits', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

$r = $decide(event('checkout.session.completed', session($order, array('amount_total' => 1))));
pin('a different amount is still reported as Stripe\'s own figure, for core to refuse', 10_000, $r->getAmount());

$r = $decide(event('checkout.session.async_payment_succeeded', session($order)));
pin('async success is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());

$r = $decide(event('checkout.session.async_payment_failed', session($order, array('payment_status' => 'unpaid'))));
pin('async failure fails the order', CallbackResult::OUTCOME_FAILED, $r->getOutcome());
pin('with the session as reference', 'cs_test_abc1', $r->getExternalRef());

$r = $decide(event('checkout.session.expired', session($order, array('status' => 'expired', 'payment_status' => 'unpaid'))));
pin('an expired session fails the order', CallbackResult::OUTCOME_FAILED, $r->getOutcome());

$r = $decide(event('checkout.session.expired', session($order, array('status' => 'expired', 'amount_total' => 5))));
pin('an expiry for another amount is refused', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

$charge = array('id' => 'ch_1', 'object' => 'charge', 'amount' => 999, 'amount_refunded' => 999, 'currency' => 'usd', 'refunded' => true, 'payment_intent' => 'pi_test_1', 'metadata' => array('order_id' => '1', 'site' => SITE));
$r      = $decide(event('charge.refunded', $charge));
pin('a full refund refunds the order', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('that order', 1, $r->getOrderId());

$r = $decide(event('charge.refunded', array('refunded' => false, 'amount_refunded' => 500) + $charge));
pin('a partial refund changes nothing', 'partial refund', $r->getMessage());

$orders->set(1, 'paid', 'pi_test_1');
$r = $decide(event('charge.refunded', array('metadata' => array()) + $charge));
pin('a charge without metadata is found by its payment intent', 1, $r->getOrderId());

$second = $orders->add(5_000_000, 'EUR', 'cs_test_x');
$http->on('GET', 'payment_intents/pi_test_other', 200, array('id' => 'pi_test_other', 'metadata' => array('order_id' => (string) $second->getId(), 'site' => SITE)));
$r = $decide(event('charge.refunded', array('metadata' => array(), 'payment_intent' => 'pi_test_other', 'amount' => 500, 'currency' => 'eur') + $charge));
pin('or by the payment intent\'s metadata at Stripe', $second->getId(), $r->getOrderId());

$r = $decide(event('charge.refunded', array('amount' => 100) + $charge));
pin('a refund for another amount is refused', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

$r = $decide(event('customer.created', array('id' => 'cus_1')));
pin('anything else is ignored', 'event not handled', $r->getMessage());

harness_section('orders that are not ours');

$r = $decide(event('checkout.session.completed', session($order, array('metadata' => array('order_id' => '1', 'site' => 'ffffffffffffffff')))));
pin('another site on the same Stripe account', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('checkout.session.completed', session($order, array('client_reference_id' => '2'))));
pin('a reference that disagrees with the metadata', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$other = $orders->add(9_990_000, 'USD', null, 'pending', 'test');
$r     = $decide(event('checkout.session.completed', session($other)));
pin('an order of another gateway', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
$r = $decide(event('checkout.session.completed', session($order, array('metadata' => array('order_id' => '999', 'site' => SITE), 'client_reference_id' => '999'))));
pin('an order that does not exist', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

harness_section('the webhook');

$orders  = new MemoryOrders();
$order   = $orders->add(9_990_000, 'USD', 'cs_test_abc1');
$gateway = fake_gateway($http, $orders);
$paid    = event('checkout.session.completed', session($order));

deliver($paid);
$r = $gateway->handleCallback(array());
pin('a signed event is settled', array('paid'), array_map(static fn ($x) => $x->getOutcome(), $GLOBALS['settled']));
check('and the route answers 200', !$r->isRetryable());

$GLOBALS['settled'] = array();
deliver($paid, 'whsec_wrong');
$r = $gateway->handleCallback(array());
pin('a bad signature is ignored', 'bad signature', $r->getMessage());
check('and never retried', !$r->isRetryable());
pin('nothing settled', array(), $GLOBALS['settled']);

$body = deliver($paid);
$GLOBALS['input'][0] = str_replace('"amount_total":999', '"amount_total":1', $body);
pin('a tampered body is ignored', 'bad signature', $gateway->handleCallback(array())->getMessage());

deliver($paid, WHSEC, time() - 600);
pin('an old delivery is ignored', 'bad signature', $gateway->handleCallback(array())->getMessage());

$GLOBALS['input'] = array(json_encode($paid), '');
pin('a decoded body and a forged trust key without a signature are ignored', 'bad signature', $gateway->handleCallback(array('raw' => $paid, StripeGateway::TRUSTED => 'yes'))->getMessage());

deliver(event('checkout.session.completed', session($order), true));
pin('a live event in test mode is ignored', 'event is for the other mode', $gateway->handleCallback(array())->getMessage());

deliver(event('payment_intent.created', array('id' => 'pi_1')));
pin('an event type we do not handle is ignored', 'event not handled', $gateway->handleCallback(array())->getMessage());

$GLOBALS['input'] = array('not json', \mindstellar\stripe\Signature::sign('not json', WHSEC, time()));
pin('a signed body that is not an event is ignored', 'malformed event', $gateway->handleCallback(array())->getMessage());

harness_section('when settling fails');

$broken = fake_gateway($http, $orders, array(), static function (array $request): CallbackResult {
    throw new RuntimeException('MySQL server has gone away');
});
deliver($paid);
$r = $broken->handleCallback(array());
check('Stripe is told to retry', $r->isRetryable());
pin('and the event id is queued', array('evt_test_1'), $GLOBALS['queued']);

$noQueue = new StripeGateway(fake_config(), $http, $orders, static fn () => $GLOBALS['input'], static function (array $r): CallbackResult {
    throw new RuntimeException('db down');
}, static function (string $id): void {
    throw new RuntimeException('queue down too');
});
deliver($paid);
check('even when the queue is down too, Stripe is told to retry', $noQueue->handleCallback(array())->isRetryable());

harness_section('not configured');

$bare = fake_gateway($http, $orders, array('webhook' => ''));
deliver($paid);
$r = $bare->handleCallback(array());
pin('without a webhook secret nothing is settled', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());
check('but Stripe keeps the event for later', $r->isRetryable());

exit(harness_result());
