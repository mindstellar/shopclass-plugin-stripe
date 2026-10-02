<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The return page and the registered job handlers, run for real against fakes.
 */

require __DIR__ . '/lib/bootstrap.php';
require_once core_path() . '/oc-includes/osclass/classes/billing/PaymentGatewayRegistry.php';
require_once core_path() . '/oc-includes/osclass/classes/job/Job.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\job\Job;
use mindstellar\stripe\Plugin;
use mindstellar\stripe\StripeGateway;

$GLOBALS['billing'] = true;
$GLOBALS['jobs']    = array();
function osc_billing_enabled()
{
    return $GLOBALS['billing'];
}
function osc_job_register_handler($type, $handler)
{
    $GLOBALS['jobs'][$type] = $handler;
}
function osc_job_describe($type, $name, $detail = null)
{
}

/** A gateway whose settle step writes the outcome to the in-memory orders, as core would. */
function settling_gateway(FakeHttp $http, MemoryOrders $orders): StripeGateway
{
    $gateway = null;
    $gateway = fake_gateway($http, $orders, array(), static function (array $request) use (&$gateway, $orders): CallbackResult {
        $result               = $gateway->handleCallback($request);
        $GLOBALS['settled'][] = $result;
        $id                   = $result->getOrderId();
        if ($id !== null && $orders->find($id)->isPending() && in_array($result->getOutcome(), array('paid', 'failed'), true)) {
            $orders->set($id, $result->getOutcome(), $result->getExternalRef());
        }

        return $result;
    });

    return $gateway;
}

harness_section('the return page');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$gateway = settling_gateway($http, $orders);
$order   = $orders->add(9_990_000, 'USD', 'cs_test_abc1');
$http->on('GET', 'checkout/sessions/cs_test_abc1', 200, session($order));

$GLOBALS['flash'] = array();
pin('another user\'s order is refused', '', Plugin::settleReturn($gateway, $orders, $order->getId(), 99, 'cs_test_abc1'));
pin('Stripe is not asked', array(), $http->requests);
pin('the order is unchanged', Order::STATUS_PENDING, $orders->find(1)->getStatus());
pin('with an error message', 'error', $GLOBALS['flash'][0][0] ?? null);

$GLOBALS['flash'] = array();
pin('billing off is refused', '', Plugin::settleReturn(null, $orders, $order->getId(), 7, 'cs_test_abc1'));
pin('an order that does not exist is refused', '', Plugin::settleReturn($gateway, $orders, 999, 7, 'cs_test_abc1'));
$other = $orders->add(9_990_000, 'USD', 'cs_test_x', 'pending', 'test');
pin('another gateway\'s order is refused', '', Plugin::settleReturn($gateway, $orders, $other->getId(), 7, 'cs_test_x'));
pin('still without asking Stripe', array(), $http->requests);

$GLOBALS['flash'] = array();
pin('a session that is not the order\'s own is not looked up', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, 'cs_test_other'));
pin('Stripe is not asked', array(), $http->requests);
pin('the buyer is told it is processing', 'info', $GLOBALS['flash'][0][0] ?? null);

$http->down('GET', 'checkout/sessions/cs_test_abc1');
$GLOBALS['flash'] = array();
pin('Stripe down is caught; the order stays pending', Order::STATUS_PENDING, Plugin::settleReturn($gateway, $orders, 1, 7, 'cs_test_abc1'));
pin('and the buyer is told it is processing', 'info', $GLOBALS['flash'][0][0] ?? null);

$http->on('GET', 'checkout/sessions/cs_test_abc1', 200, session($order));
$GLOBALS['flash'] = array();
pin('the owner with the order\'s own session ends paid', Order::STATUS_PAID, Plugin::settleReturn($gateway, $orders, 1, 7, 'cs_test_abc1'));
pin('and is told credits were added', array('ok', 'Payment for order #1 received. Credits added.'), $GLOBALS['flash'][0] ?? null);

$count = count($http->requests);
$GLOBALS['flash'] = array();
Plugin::settleReturn($gateway, $orders, 1, 7, 'cs_test_abc1');
pin('a reload of a paid order does not call Stripe again', $count, count($http->requests));
pin('and just shows it paid', array('ok', 'Order #1 is paid.'), $GLOBALS['flash'][0] ?? null);

$failed = $orders->add(9_990_000, 'USD', 'cs_test_abc9', 'failed');
$count  = count($http->requests);
Plugin::settleReturn($gateway, $orders, $failed->getId(), 7, 'cs_test_abc9');
pin('an order that is no longer pending is not looked up, even with its own session', $count, count($http->requests));

harness_section('the job handlers');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->add(9_990_000, 'USD', 'cs_test_abc1');
$gateway = settling_gateway($http, $orders);
$http->on('GET', 'events/evt_x', 200, event('checkout.session.completed', session($order), false, 'evt_x'));
Plugin::$factory = static fn (): StripeGateway => $gateway;

Plugin::registerJobs();
check('both job types have a handler', isset($GLOBALS['jobs'][Plugin::JOB_EVENT], $GLOBALS['jobs'][Plugin::JOB_RECONCILE]));

($GLOBALS['jobs'][Plugin::JOB_EVENT])(new Job(array('pk_i_id' => 1), array('event' => 'evt_x')));
pin('the event job fetches that event from Stripe', 'https://api.stripe.com/v1/events/evt_x', end($http->requests)['url'] ?? null);
pin('and settles its order', Order::STATUS_PAID, $orders->find(1)->getStatus());
check('the gateway was registered for the job', \mindstellar\billing\PaymentGatewayRegistry::instance()->get('stripe') === $gateway);

$GLOBALS['billing'] = false;
$threw              = false;
try {
    ($GLOBALS['jobs'][Plugin::JOB_EVENT])(new Job(array('pk_i_id' => 2), array('event' => 'evt_x')));
} catch (RuntimeException $e) {
    $threw = true;
}
check('with billing off the job throws, so it is retried later', $threw);

$count = count($http->requests);
($GLOBALS['jobs'][Plugin::JOB_RECONCILE])(new Job(array('pk_i_id' => 3), array()));
pin('and the daily check does nothing', $count, count($http->requests));

$GLOBALS['billing'] = true;
$stale = $orders->add(9_990_000, 'USD', 'cs_test_abc2', 'pending', 'stripe', date('Y-m-d H:i:s', time() - 7200));
$http->on('GET', 'checkout/sessions/cs_test_abc2', 200, session($stale, array('id' => 'cs_test_abc2')));
($GLOBALS['jobs'][Plugin::JOB_RECONCILE])(new Job(array('pk_i_id' => 4), array()));
pin('the daily check job settles a stale order', Order::STATUS_PAID, $orders->find($stale->getId())->getStatus());

exit(harness_result());
