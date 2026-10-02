<?php
/*
Plugin Name: Stripe Payment
Plugin URI: https://github.com/mindstellar/shopclass-plugin-stripe
Description: Take card payments for credit packages through Stripe Checkout. Buyers pay on Stripe's hosted page; a signed webhook marks the order paid. Refunds from the admin.
Version: 0.1.0
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: stripe
Requires Shopclass: 6.4.0
Tested up to: 6.4
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-stripe/issues
*/

/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\stripe\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\stripe\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// Early 6.4.0 release candidates lack the billing API this plugin uses, so it stays off there.
if (!interface_exists('mindstellar\\billing\\DashboardLinkGateway')) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Stripe Payment needs a newer Shopclass 6.4.0 and is switched off. Update Shopclass to use it.', 'stripe'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_settings_page_url(Plugin::PAGE));
});

// The gateway, while billing is on. The webhook route registers it too, in case init has not run.
osc_add_hook('init', array(Plugin::class, 'register'));
osc_add_hook('init_billing_non_secure', array(Plugin::class, 'register'));

// The buyer comes back here from Stripe Checkout.
osc_add_route_hook(Plugin::ROUTE_RETURN, 'stripe/return/([0-9]+)', 'stripe/return/{order}');
osc_add_hook(Plugin::ROUTE_RETURN, array(Plugin::class, 'returnPage'));

// Retries for webhooks that could not be settled, and a daily check of pending orders.
osc_add_hook('register_jobs', array(Plugin::class, 'registerJobs'));
osc_add_hook('cron_daily', array(Plugin::class, 'daily'));
osc_add_hook('job_gave_up', array(Plugin::class, 'jobGaveUp'));

osc_add_hook('admin_page_header', array(Plugin::class, 'adminNotice'), 10);
