<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The gateway against core's real billing layer and a scratch database, with Stripe faked:
 * a webhook credits once, a replay credits nothing, a wrong amount is refused, the return
 * page and the daily check settle, and a refund followed by its webhook takes credits once.
 *
 * Needs core's scratch MySQL (see core's tests/lib/scratchdb.php). Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/billing.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/osclass', '/');
require_once $core . '/tests/lib/scratchdb.php';
require_once $core . '/tests/lib/harness.php';

$admin = scratchdb_session('osc_stripe_plugin');

if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
function osc_plugins_path()
{
    return PLUGINS_PATH;
}
function osc_register_render_target($id, $path)
{
}
function osc_base_url($with_index = false)
{
    return WEB_PATH . ($with_index ? 'index.php' : '');
}
function _m($key)
{
    return $key;
}
require_once $core . '/tests/lib/stubs.php';
function osc_route_url($id, $args = array())
{
    return WEB_PATH . 'index.php?page=route&route=' . $id . '&' . http_build_query($args);
}
function osc_core_url($name, $args = array())
{
    return \mindstellar\routing\CoreRoutes::url($name, $args);
}
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\stripe\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
require_once __DIR__ . '/../lib/fakes.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;
use mindstellar\stripe\CoreOrderStore;
use mindstellar\stripe\Plugin;
use mindstellar\stripe\StripeGateway;

$status = static fn (Order $order): string => Orders::find($order->getId())->getStatus();
$ledger = static function (Order $order) use ($admin): int {
    return (int) $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_billing_ledger WHERE s_ref_type = 'order' AND i_ref_id = " . $order->getId()
    )->fetch_assoc()['c'];
};

harness_section('settings page declaration');

$threw = null;
try {
    osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/../../settings.php');
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
pin('core accepts the declaration', null, $threw);
pin('test mode until an admin picks live', 'test', osc_settings_value(Plugin::PAGE, 'mode'));
pin('no keys until an admin adds them', '', osc_settings_value(Plugin::PAGE, 'test_secret_key'));
$fields = \mindstellar\settings\SettingsPageRegistry::instance()->fields(Plugin::PAGE);
check('a key with the wrong prefix is refused', osc_settings_validate($fields['test_secret_key'], 'pk_test_123') !== null);
pin('a restricted key is fine', null, osc_settings_validate($fields['live_secret_key'], 'rk_live_123'));
check('a currency Stripe does not take is refused', osc_settings_validate($fields['currencies'], 'USD, XYZ') !== null);

$id = \mindstellar\stripe\Config::siteId();
pin('the site id is made once and kept', $id, \mindstellar\stripe\Config::siteId());
Plugin::uninstall();
Plugin::install();
pin('it survives an uninstall and reinstall', $id, \mindstellar\stripe\Config::siteId());
osc_delete_preference('site_id', Plugin::PAGE);
osc_reset_preferences();
Plugin::install();
check('install makes a new one when none is stored', preg_match('/^[a-f0-9]{16}$/', (string) osc_get_preference('site_id', Plugin::PAGE)) === 1);

harness_section('fixtures');

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

$http    = new FakeHttp();
$store   = new CoreOrderStore();
$queued  = array();
$gateway = new StripeGateway(
    fake_config(),
    $http,
    $store,
    static fn (): array => $GLOBALS['input'],
    null, // the real Billing::handleCallback
    static function (string $id) use (&$queued): void {
        $queued[] = $id;
    }
);
PaymentGatewayRegistry::instance()->register($gateway);
check('offered at checkout for USD', isset(PaymentGatewayRegistry::instance()->available('USD')[StripeGateway::ID]));

$buyer    = seed_user($admin, 'buyer', 'buyer@example.test');
$newOrder = static fn (int $amount = 9_990_000, string $currency = 'USD'): Order => Orders::create($buyer, StripeGateway::ID, $amount, $currency, 100);
$webhook  = static function (array $event): CallbackResult {
    deliver($event);

    // What core's callback route does with a POST from Stripe.
    return Billing::handleCallback(StripeGateway::ID, array());
};

harness_section('checkout');

$order = $newOrder();
$http->on('POST', 'checkout/sessions', 200, session($order));
$intent = $gateway->createCheckout($order);
check('the buyer is sent to Stripe', $intent->isRedirect());
pin('the session id is stored on the order', 'cs_test_abc' . $order->getId(), Orders::find($order->getId())->getExternalRef());
pin('which is still pending', Order::STATUS_PENDING, $status($order));
pin('the buyer\'s e-mail is sent along', 'buyer@example.test', $http->lastParams()['customer_email'] ?? null);

$intent = $gateway->createCheckout(Orders::find($order->getId()));
check('a retry that gets back the same session still goes to Stripe', $intent->getPayload() === session($order)['url']);

$other = $newOrder();
$intent = $gateway->createCheckout($other); // Stripe answers with the first order's session
pin('core refuses a session another order holds, so the buyer is not sent to pay', 'https://shop.test/buy', $intent->getPayload());
pin('that order is closed', Order::STATUS_FAILED, $status($other));
pin('and the first order keeps its session', 'cs_test_abc' . $order->getId(), Orders::find($order->getId())->getExternalRef());

harness_section('webhook: paid once, replay credits nothing');

$paid = event('checkout.session.completed', session($order));
$r    = $webhook($paid);
check('the route answers 200', !$r->isRetryable());
pin('the credits land', 100, Wallet::balance($buyer));
pin('the order is paid', Order::STATUS_PAID, $status($order));
pin('the payment intent is stored', 'pi_test_' . $order->getId(), Orders::find($order->getId())->getExternalRef());
pin('the admin order screen links to it in Stripe', 'https://dashboard.stripe.com/test/payments/pi_test_' . $order->getId(), $gateway->dashboardUrl(Orders::find($order->getId())));
pin('the order records it was paid in test mode', false, Orders::find($order->getId())->meta(StripeGateway::META_LIVEMODE));
$liveGateway = new StripeGateway(fake_config(array('mode' => 'live')), $http, $store);
pin('after a switch to live, its link still opens the test dashboard', 'https://dashboard.stripe.com/test/payments/pi_test_' . $order->getId(), $liveGateway->dashboardUrl(Orders::find($order->getId())));

$webhook($paid);
$webhook(event('checkout.session.async_payment_succeeded', session($order), false, 'evt_test_2'));
pin('a replay credits nothing more', 100, Wallet::balance($buyer));
pin('one ledger row', 1, $ledger($order));

harness_section('webhook: refused');

$short = $newOrder();
$webhook(event('checkout.session.completed', session($short, array('amount_total' => 1))));
pin('a session for another amount is refused by core', Order::STATUS_PENDING, $status($short));
$webhook(event('checkout.session.completed', session($short, array('currency' => 'eur'))));
pin('so is another currency', Order::STATUS_PENDING, $status($short));
deliver($paid, 'whsec_forged');
Billing::handleCallback(StripeGateway::ID, array('raw' => event('checkout.session.completed', session($short))));
pin('so is a forged signature', Order::STATUS_PENDING, $status($short));
pin('none credited anything', 100, Wallet::balance($buyer));

$webhook(event('checkout.session.expired', session($short, array('status' => 'expired', 'payment_status' => 'unpaid'))));
pin('an expired session fails the order', Order::STATUS_FAILED, $status($short));
$webhook(event('checkout.session.completed', session($short)));
pin('and a late paid event cannot reopen it', Order::STATUS_FAILED, $status($short));

harness_section('return page');

$back = $newOrder();
$store->attachSession($back->getId(), 'cs_test_abc' . $back->getId());
$http->on('GET', 'checkout/sessions/cs_test_abc' . $back->getId(), 200, session($back));
$gateway->processSession('cs_test_abc' . $back->getId(), $back->getId());
pin('the return page settles a paid session', Order::STATUS_PAID, $status($back));
pin('credits added', 200, Wallet::balance($buyer));
$webhook(event('checkout.session.completed', session($back)));
pin('and the webhook that follows adds nothing', 200, Wallet::balance($buyer));

harness_section('daily check');

$stale = $newOrder();
$store->attachSession($stale->getId(), 'cs_test_abc' . $stale->getId());
$admin->query('UPDATE ' . DB_TABLE_PREFIX . "t_billing_order SET dt_date = NOW() - INTERVAL 2 HOUR WHERE pk_i_id = " . $stale->getId());
$fresh = $newOrder();
$store->attachSession($fresh->getId(), 'cs_test_abc' . $fresh->getId());
pin('only the stale order is due', array($stale->getId()), array_map(static fn (Order $o): int => $o->getId(), $store->stalePending(3600, 100)));
$http->on('GET', 'checkout/sessions/cs_test_abc' . $stale->getId(), 200, session($stale));
pin('it is checked', 1, $gateway->reconcile());
pin('and settled', Order::STATUS_PAID, $status($stale));
pin('the fresh one is left alone', Order::STATUS_PENDING, $status($fresh));

harness_section('refund, then its webhook');

$http->on('POST', 'refunds', 200, array('id' => 're_1', 'status' => 'succeeded'));
$paidOrder = Orders::find($order->getId());
$r         = $gateway->refund($paidOrder);
pin('Stripe accepts the refund', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
pin('refunded from the stored payment intent', 'pi_test_' . $order->getId(), $http->lastParams()['payment_intent']);
Billing::refund($paidOrder);
pin('core takes the credits back', 200, Wallet::balance($buyer));

$charge = array(
    'id' => 'ch_1', 'object' => 'charge', 'amount' => 999, 'amount_refunded' => 999, 'currency' => 'usd',
    'refunded' => true, 'payment_intent' => 'pi_test_' . $order->getId(), 'metadata' => array(),
);
$webhook(event('charge.refunded', $charge, false, 'evt_test_refund'));
pin('the charge.refunded webhook that follows takes nothing more', 200, Wallet::balance($buyer));
pin('the order stays refunded', Order::STATUS_REFUNDED, $status($order));

$webhook(event('charge.refunded', array('payment_intent' => 'pi_test_' . $back->getId()) + $charge, false, 'evt_test_refund2'));
pin('a refund made in the Stripe dashboard is recorded too', Order::STATUS_REFUNDED, $status($back));
pin('and takes its credits back', 100, Wallet::balance($buyer));

pin('nothing needed the queue', array(), $queued);

exit(harness_result());
