<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The Stripe-Signature check: only the endpoint's secret, over the exact body, recently.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\stripe\Signature;

$body   = '{"id":"evt_1","type":"checkout.session.completed"}';
$secret = 'whsec_abc';
$now    = 1790000000;
$header = Signature::sign($body, $secret, $now);

harness_section('signature');

check('a valid signature passes', Signature::verify($body, $header, $secret, $now));
check('a wrong secret fails', !Signature::verify($body, $header, 'whsec_other', $now));
check('a tampered body fails', !Signature::verify(str_replace('evt_1', 'evt_2', $body), $header, $secret, $now));
check('one changed byte of whitespace fails', !Signature::verify($body . ' ', $header, $secret, $now));
check('301 seconds old fails', !Signature::verify($body, $header, $secret, $now + 301));
check('300 seconds old passes', Signature::verify($body, $header, $secret, $now + 300));
check('from the future beyond the tolerance fails', !Signature::verify($body, $header, $secret, $now - 301));

$good  = hash_hmac('sha256', $now . '.' . $body, $secret);
check('any one of several v1 signatures passes (secret rolling)', Signature::verify($body, "t=$now,v1=" . str_repeat('0', 64) . ",v1=$good", $secret, $now));
check('a v0 signature alone is not enough', !Signature::verify($body, "t=$now,v0=$good", $secret, $now));
check('no timestamp fails', !Signature::verify($body, "v1=$good", $secret, $now));
check('an empty header fails', !Signature::verify($body, '', $secret, $now));
check('an empty secret never passes', !Signature::verify($body, Signature::sign($body, '', $now), '', $now));
check('spaces after commas are tolerated', Signature::verify($body, "t=$now, v1=$good", $secret, $now));

exit(harness_result());
