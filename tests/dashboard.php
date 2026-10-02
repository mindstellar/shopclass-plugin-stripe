<?php
/*
 * This file is part of the Stripe Payments plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The "View in Stripe" link on the admin order screen.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\DashboardLinkGateway;
use mindstellar\billing\RefundableGateway;
use mindstellar\stripe\StripeGateway;
use mindstellar\stripe\Verified;

$http   = new FakeHttp();
$orders = new MemoryOrders();
$test   = fake_gateway($http, $orders);
$live   = fake_gateway($http, $orders, array('mode' => 'live'));

harness_section('dashboard link');

check('the gateway links to its dashboard', $test instanceof DashboardLinkGateway);
check('and still refunds', $test instanceof RefundableGateway);

$paid = $orders->add(9_990_000, 'USD', 'pi_3Abc123', 'paid');
pin('a paid order in test mode', 'https://dashboard.stripe.com/test/payments/pi_3Abc123', $test->dashboardUrl($paid));
pin('a paid order in live mode', 'https://dashboard.stripe.com/payments/pi_3Abc123', $live->dashboardUrl($paid));
pin('a refunded order links too', 'https://dashboard.stripe.com/test/payments/pi_3Abc123', $test->dashboardUrl($orders->add(9_990_000, 'USD', 'pi_3Abc123', 'refunded')));

$liveMeta = $orders->add(9_990_000, 'USD', 'pi_3Live', 'paid', 'stripe', '2026-01-01 00:00:00', array(StripeGateway::META_LIVEMODE => true));
pin('a mode stored on the order wins over the current setting', 'https://dashboard.stripe.com/payments/pi_3Live', $test->dashboardUrl($liveMeta));
$testMeta = $orders->add(9_990_000, 'USD', 'pi_3Test', 'paid', 'stripe', '2026-01-01 00:00:00', array(StripeGateway::META_LIVEMODE => false));
pin('in both directions', 'https://dashboard.stripe.com/test/payments/pi_3Test', $live->dashboardUrl($testMeta));

pin('a pending session has no documented dashboard page', null, $test->dashboardUrl($orders->add(9_990_000, 'USD', 'cs_test_abc')));
pin('nor a failed one', null, $test->dashboardUrl($orders->add(9_990_000, 'USD', 'cs_live_abc', 'failed')));
pin('a pending order with a payment intent gets none yet', null, $test->dashboardUrl($orders->add(9_990_000, 'USD', 'pi_3Abc', 'pending')));
pin('no ref, no link', null, $test->dashboardUrl($orders->add(9_990_000, 'USD', null, 'paid')));
foreach (array('pi_../../account', 'pi_x?y=1', 'pi_', 'ch_123', 'javascript:alert(1)', 'pi_ab cd', "pi_ab\ncd") as $bad) {
    pin('a bad ref gives no link: ' . json_encode($bad), null, $test->dashboardUrl($orders->add(9_990_000, 'USD', $bad, 'paid')));
}

harness_section('the mode is stored when a session is paid');


$settle = static fn (StripeGateway $g, array $session): CallbackResult => $g->handleCallback(array(StripeGateway::TRUSTED => Verified::event(event('checkout.session.completed', $session))));

$order = $orders->add(9_990_000, 'USD', 'cs_test_m1');
$settle($test, session($order));
pin('a test payment stores livemode false', false, $orders->find($order->getId())->meta(StripeGateway::META_LIVEMODE));

$order = $orders->add(9_990_000, 'USD', 'cs_live_m2');
$settle($live, session($order, array('livemode' => true)));
pin('a live payment stores livemode true', true, $orders->find($order->getId())->meta(StripeGateway::META_LIVEMODE));
$orders->set($order->getId(), 'paid', 'pi_live_m2');
pin('and its link stays on live after a switch to test', 'https://dashboard.stripe.com/payments/pi_live_m2', $test->dashboardUrl($orders->find($order->getId())));

$settle($test, session($orders->find($order->getId()), array('livemode' => false)));
pin('a replay on a paid order does not rewrite it', true, $orders->find($order->getId())->meta(StripeGateway::META_LIVEMODE));

$order = $orders->add(9_990_000, 'USD', 'cs_test_m3');
$orders->metaFails = true;
$r = $settle($test, session($order));
pin('a throwing meta write does not stop the payment', CallbackResult::OUTCOME_PAID, $r->getOutcome());
$orders->metaFails = false;
pin('nor a refused one', CallbackResult::OUTCOME_PAID, $settle($test, session($orders->add(9_990_000, 'USD', 'cs_test_m4')))->getOutcome());
$orders->metaFails = null;

$order = $orders->add(9_990_000, 'USD', 'cs_test_m5');
$plain = session($order);
unset($plain['livemode']);
$settle($test, $plain);
pin('a session without livemode stores nothing', null, $orders->find($order->getId())->meta(StripeGateway::META_LIVEMODE));

exit(harness_result());
