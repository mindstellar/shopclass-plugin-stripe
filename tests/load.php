<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin loads without a database and registers what it should.
 */

require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['__hooks']    = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__settings'] = array();
$GLOBALS['__install']  = null;

function osc_plugin_path($file)
{
    return 'stripe/index.php';
}
function osc_register_plugin($path, $fn)
{
    $GLOBALS['__install'] = array($path, $fn);
}
function osc_add_hook($name, $fn, $priority = 10)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
function osc_register_settings_page($id, $spec)
{
    $GLOBALS['__settings'][$id] = $spec;
}
function osc_add_route_hook($id, $regexp, $url)
{
    $GLOBALS['__routes'][$id] = array($regexp, $url);
}

require __DIR__ . '/../index.php';

use mindstellar\stripe\Plugin;

harness_section('what the plugin registers');

pin('install runs Plugin::install', array('stripe/index.php', array(Plugin::class, 'install')), $GLOBALS['__install']);
pin('uninstall runs Plugin::uninstall', array(array(Plugin::class, 'uninstall')), $GLOBALS['__hooks']['stripe/index.php_uninstall'] ?? null);
check('the configure link has a handler', isset($GLOBALS['__hooks']['stripe/index.php_configure']));
pin('the gateway registers on init and on the webhook route', array(array(array(Plugin::class, 'register')), array(array(Plugin::class, 'register'))), array($GLOBALS['__hooks']['init'] ?? null, $GLOBALS['__hooks']['init_billing_non_secure'] ?? null));
pin('the settings page is declared under the plugin id', array('stripe'), array_keys($GLOBALS['__settings']));
$names = array();
foreach ($GLOBALS['__settings']['stripe']['groups'] as $group) {
    $names = array_merge($names, array_column($group['fields'], 'name'));
}
pin('its fields', array('mode', 'test_secret_key', 'test_webhook_secret', 'live_secret_key', 'live_webhook_secret', 'webhook_info', 'name', 'currencies'), $names);
$secrets = array_filter($GLOBALS['__settings']['stripe']['groups'][0]['fields'], static fn ($f) => $f['type'] === 'secret');
check('every secret is masked, so it is never drawn back', array_filter($secrets, static fn ($f) => empty($f['masked'])) === array());
check('and a blank box keeps the stored one', array_filter($secrets, static fn ($f) => ($f['persist'])('') !== null) === array());
pin('the return route', array('stripe/return/([0-9]+)', 'stripe/return/{order}'), $GLOBALS['__routes'][Plugin::ROUTE_RETURN] ?? null);
pin('answered by Plugin::returnPage', array(array(Plugin::class, 'returnPage')), $GLOBALS['__hooks'][Plugin::ROUTE_RETURN] ?? null);
pin('jobs, the daily check and the give-up mail', array(
    array(array(Plugin::class, 'registerJobs')),
    array(array(Plugin::class, 'daily')),
    array(array(Plugin::class, 'jobGaveUp')),
), array($GLOBALS['__hooks']['register_jobs'] ?? null, $GLOBALS['__hooks']['cron_daily'] ?? null, $GLOBALS['__hooks']['job_gave_up'] ?? null));
pin('the test-mode notice', array(array(Plugin::class, 'adminNotice')), $GLOBALS['__hooks']['admin_page_header'] ?? null);
check('job types are namespaced', preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', Plugin::JOB_EVENT) && preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', Plugin::JOB_RECONCILE));

exit(harness_result());
