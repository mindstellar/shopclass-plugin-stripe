<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The queued event job and the daily check of pending orders.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\stripe\Http\HttpException;

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', 'cs_test_abc1');

harness_section('the event job');

$http->on('GET', 'events/evt_test_1', 200, event('checkout.session.completed', session($order)));
$r = $gateway->processEvent('evt_test_1');
pin('the event is fetched from Stripe', 'https://api.stripe.com/v1/events/evt_test_1', end($http->requests)['url']);
pin('and settled through the same path as the webhook', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('once', 1, count($GLOBALS['settled']));

$count = count($http->requests);
pin('a malformed id is refused', 'malformed event id', $gateway->processEvent('evt_../x')->getMessage());
pin('without calling Stripe', $count, count($http->requests));

$http->down('GET', 'events/evt_test_2');
$threw = false;
try {
    $gateway->processEvent('evt_test_2');
} catch (HttpException $e) {
    $threw = true;
}
check('Stripe down throws, so the queue retries', $threw);

harness_section('the daily check');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = fake_gateway($http, $orders);
$stale   = $orders->add(9_990_000, 'USD', 'cs_test_abc1', 'pending', 'stripe', date('Y-m-d H:i:s', time() - 7200));
$fresh   = $orders->add(9_990_000, 'USD', 'cs_test_abc2', 'pending', 'stripe', date('Y-m-d H:i:s'));
$noRef   = $orders->add(9_990_000, 'USD', null, 'pending', 'stripe', date('Y-m-d H:i:s', time() - 7200));

$http->on('GET', 'checkout/sessions/cs_test_abc1', 200, session($stale));
pin('only an order over an hour old with a session is checked', 1, $gateway->reconcile());
pin('and settled', array('paid'), array_map(static fn ($x) => $x->getOutcome(), $GLOBALS['settled']));

$GLOBALS['settled'] = array();
$live  = fake_gateway($http, $orders, array('mode' => 'live'));
$count = count($http->requests);
pin('after a switch to live, test sessions are skipped, not retried daily', 0, $live->reconcile());
pin('without calling Stripe', $count, count($http->requests));

$GLOBALS['settled'] = array();
$http->down('GET', 'checkout/sessions/cs_test_abc1');
$threw = false;
try {
    $gateway->reconcile();
} catch (HttpException $e) {
    $threw = str_contains($e->getMessage(), '#' . $stale->getId());
}
check('a failed lookup throws naming the order, so the job retries', $threw);

exit(harness_result());
