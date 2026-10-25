<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * On a core that does not list credit-package gateways by currency (Shopclass 7.0),
 * Stripe is offered only when it takes the billing currency.
 */

require __DIR__ . '/lib/bootstrap.php';

harness_section('isConfigured on Shopclass 7.0');

if (function_exists('osc_billing_gateways')) {
    check('skipped: this core lists gateways by currency', true);
} else {
    $http   = new FakeHttp();
    $orders = new MemoryOrders();
    check('offered for a billing currency it takes', fake_gateway($http, $orders)->isConfigured());
    check('not offered when its listed currencies leave out the billing currency', !fake_gateway($http, $orders, array('currencies' => array('EUR')))->isConfigured());
}
