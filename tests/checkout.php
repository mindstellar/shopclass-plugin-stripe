<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Opening a checkout session, the return page's session lookup, and isConfigured().
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\stripe\StripeApi;

harness_section('isConfigured');

$http   = new FakeHttp();
$orders = new MemoryOrders();
check('ready with a secret key and a webhook secret', fake_gateway($http, $orders)->isConfigured());
check('not without the secret key', !fake_gateway($http, $orders, array('secret' => ''))->isConfigured());
check('not without the webhook secret', !fake_gateway($http, $orders, array('webhook' => ''))->isConfigured());
check('not when the billing currency is not allowed', !fake_gateway($http, $orders, array('currencies' => array('EUR')))->isConfigured());
check('yes when it is', fake_gateway($http, $orders, array('currencies' => array('EUR', 'USD')))->isConfigured());
check('in test mode, not offered to a buyer who is not an admin', !fake_gateway($http, $orders, array('admin' => false))->isConfigured());
check('but webhooks and lookups still work for them', fake_gateway($http, $orders, array('admin' => false))->hasKeys());
check('in live mode, offered to everyone', fake_gateway($http, $orders, array('mode' => 'live', 'admin' => false))->isConfigured());
pin('the billing currency when none are listed', array('USD'), fake_gateway($http, $orders)->getSupportedCurrencies());
pin('nothing when Stripe does not take the billing currency', array(), fake_gateway($http, $orders, array('billing' => 'XYZ'))->getSupportedCurrencies());
check('which leaves it unconfigured', !fake_gateway($http, $orders, array('billing' => 'XYZ'))->isConfigured());
pin('the configured list otherwise', array('EUR', 'USD'), fake_gateway($http, $orders, array('currencies' => array('EUR', 'USD')))->getSupportedCurrencies());
pin('a list from settings keeps only codes Stripe takes', array('EUR', 'JPY'), \mindstellar\stripe\Config::parseCurrencies('eur, jpy, nope, EUR'));

harness_section('createCheckout');

$order = $orders->add(9_990_000, 'USD');
$orders->emails[7] = 'buyer@example.test';
$http->on('POST', 'checkout/sessions', 200, session($order));
$gateway = fake_gateway($http, $orders, array('currencies' => array('USD', 'JPY', 'KWD')));
$intent  = $gateway->createCheckout($order);

check('the buyer is sent to Stripe', $intent->isRedirect() && $intent->getPayload() === 'https://checkout.stripe.com/c/pay/cs_test_abc1');
$req    = end($http->requests);
$params = $http->lastParams();
pin('one POST to checkout/sessions', array('POST', 'https://api.stripe.com/v1/checkout/sessions'), array($req['method'], $req['url']));
pin('with the secret key', 'Bearer sk_test_123', $req['headers']['Authorization']);
pin('a pinned API version', StripeApi::VERSION, $req['headers']['Stripe-Version']);
pin('an idempotency key per order and site', 'checkout-' . SITE . '-1', $req['headers']['Idempotency-Key']);
pin('payment mode', 'payment', $params['mode']);
pin('one line item in cents', array('quantity' => '1', 'price_data' => array('currency' => 'usd', 'unit_amount' => '999', 'product_data' => array('name' => '100 credits'))), $params['line_items'][0]);
pin('the order id as client reference', '1', $params['client_reference_id']);
pin('the order and site in metadata', array('order_id' => '1', 'site' => SITE), $params['metadata']);
pin('on the payment intent too', array('order_id' => '1', 'site' => SITE), $params['payment_intent_data']['metadata']);
pin('the buyer\'s e-mail', 'buyer@example.test', $params['customer_email']);
pin('a return URL Stripe fills in', 'https://shop.test/index.php?page=route&route=stripe-return&order=1&session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
pin('cancel goes back to the buy page', 'https://shop.test/buy', $params['cancel_url']);
pin('the session is remembered on the order', 'cs_test_abc1', $orders->find(1)->getExternalRef());
pin('which stays pending', Order::STATUS_PENDING, $orders->find(1)->getStatus());

$jpy = $orders->add(1_000_000_000, 'JPY');
$http->on('POST', 'checkout/sessions', 200, session($jpy));
$gateway->createCheckout($jpy);
pin('yen go as whole yen', '1000', $http->lastParams()['line_items'][0]['price_data']['unit_amount']);

$kwd = $orders->add(1_250_000, 'KWD');
$http->on('POST', 'checkout/sessions', 200, session($kwd));
$gateway->createCheckout($kwd);
pin('dinars go in fils', '1250', $http->lastParams()['line_items'][0]['price_data']['unit_amount']);

harness_section('createCheckout when it cannot');

$GLOBALS['flash'] = array();
$down = $orders->add();
$http->down('POST', 'checkout/sessions');
$intent = $gateway->createCheckout($down);
check('Stripe down sends the buyer back to the buy page', $intent->isRedirect() && $intent->getPayload() === 'https://shop.test/buy');
pin('with an error message', 'error', $GLOBALS['flash'][0][0] ?? null);
pin('and the order is closed', Order::STATUS_FAILED, $orders->find($down->getId())->getStatus());

$http->on('POST', 'checkout/sessions', 400, array('error' => array('message' => 'Invalid currency')));
$refused = $orders->add();
$gateway->createCheckout($refused);
pin('an API error closes the order too', Order::STATUS_FAILED, $orders->find($refused->getId())->getStatus());

$count = count($http->requests);
$odd   = $orders->add(9_995_000, 'USD');
$gateway->createCheckout($odd);
pin('an amount Stripe cannot charge never reaches Stripe', $count, count($http->requests));
pin('and closes the order', Order::STATUS_FAILED, $orders->find($odd->getId())->getStatus());

$gbp   = $orders->add(5_000_000, 'GBP');
$count = count($http->requests);
$gateway->createCheckout($gbp);
pin('a currency the admin did not allow never reaches Stripe', $count, count($http->requests));

harness_section('storing the session on the order');

$again = $orders->add(9_990_000, 'USD', 'cs_test_abc' . (count($orders->orders) + 1));
$http->on('POST', 'checkout/sessions', 200, session($again));
$intent = $gateway->createCheckout($again);
pin('a retry that gets back the session the order already holds still goes to Stripe', session($again)['url'], $intent->getPayload());

$closed = $orders->add(9_990_000, 'USD', null, Order::STATUS_FAILED);
$http->on('POST', 'checkout/sessions', 200, session($closed));
$GLOBALS['flash'] = array();
$intent = $gateway->createCheckout($closed);
pin('an order no longer pending is not sent to Stripe to pay', 'https://shop.test/buy', $intent->getPayload());
pin('the buyer sees an error', 'error', $GLOBALS['flash'][0][0] ?? null);

$taken = $orders->add();
$http->on('POST', 'checkout/sessions', 200, session($again));
$intent = $gateway->createCheckout($taken);
pin('a session another order holds is refused', 'https://shop.test/buy', $intent->getPayload());
pin('and this order is closed', Order::STATUS_FAILED, $orders->find($taken->getId())->getStatus());
pin('the other order keeps its session', 'cs_test_abc' . $again->getId(), $orders->find($again->getId())->getExternalRef());

harness_section('the return page');

$http    = new FakeHttp();
$orders  = new MemoryOrders();
$order   = $orders->add(9_990_000, 'USD', 'cs_test_abc1');
$gateway = fake_gateway($http, $orders);

$http->on('GET', 'checkout/sessions/cs_test_abc1', 200, session($order));
$r = $gateway->processSession('cs_test_abc1', 1);
pin('a paid session is paid', CallbackResult::OUTCOME_PAID, $r->getOutcome());
pin('it was fetched from Stripe', 'https://api.stripe.com/v1/checkout/sessions/cs_test_abc1', end($http->requests)['url']);
pin('with Stripe\'s figures', array(9_990_000, 'USD'), array($r->getAmount(), $r->getCurrency()));

$other = $orders->add(9_990_000, 'USD', 'cs_test_abc2');
$r     = $gateway->processSession('cs_test_abc1', $other->getId());
pin('someone else\'s session cannot pay this order', CallbackResult::OUTCOME_IGNORED, $r->getOutcome());

$http->on('GET', 'checkout/sessions/cs_test_abc1', 200, session($order, array('payment_status' => 'unpaid', 'status' => 'open')));
pin('an unpaid session waits', 'not paid yet', $gateway->processSession('cs_test_abc1', 1)->getMessage());

$http->on('GET', 'checkout/sessions/cs_test_abc1', 200, session($order, array('payment_status' => 'unpaid', 'status' => 'expired')));
pin('an expired one fails', CallbackResult::OUTCOME_FAILED, $gateway->processSession('cs_test_abc1', 1)->getOutcome());

$count = count($http->requests);
pin('a malformed session id is refused', 'malformed session id', $gateway->processSession('cs_test_../../x', 1)->getMessage());
pin('without asking Stripe', $count, count($http->requests));

$http->down('GET', 'checkout/sessions/cs_test_abc1');
$threw = false;
try {
    $gateway->processSession('cs_test_abc1', 1);
} catch (\mindstellar\stripe\Http\HttpException $e) {
    $threw = true;
}
check('Stripe down throws, so the caller can say "processing"', $threw);

exit(harness_result());
