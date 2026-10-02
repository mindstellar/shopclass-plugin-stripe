<?php
/*
 * This file is part of the Stripe Payment plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Micros to Stripe's minor units and back, for two-, zero- and three-decimal currencies.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\stripe\Money;

harness_section('two decimals');
pin('9.99 USD is 999 cents', 999, Money::toMinor(9_990_000, 'USD'));
pin('lower case works', 999, Money::toMinor(9_990_000, 'usd'));
pin('999 cents is 9.99 USD in micros', 9_990_000, Money::toMicros(999, 'USD'));
pin('a fraction of a cent cannot be charged', null, Money::toMinor(9_995_000, 'EUR'));
pin('zero is zero', 0, Money::toMinor(0, 'EUR'));
pin('a negative amount is refused', null, Money::toMinor(-1_000_000, 'EUR'));

harness_section('zero decimals');
pin('JPY has none', 0, Money::decimals('JPY'));
pin('1000 JPY is 1000', 1000, Money::toMinor(1_000_000_000, 'JPY'));
pin('and back', 1_000_000_000, Money::toMicros(1000, 'jpy'));
pin('KRW too', 5000, Money::toMinor(5_000_000_000, 'KRW'));
pin('half a yen cannot be charged', null, Money::toMinor(500_000, 'JPY'));

harness_section('three decimals');
pin('KWD has three', 3, Money::decimals('KWD'));
pin('1.250 KWD is 1250', 1250, Money::toMinor(1_250_000, 'KWD'));
pin('and back', 1_250_000, Money::toMicros(1250, 'KWD'));
pin('BHD too', 5000, Money::toMinor(5_000_000, 'BHD'));
pin('Stripe needs the last digit to be 0', null, Money::toMinor(1_251_000, 'KWD'));

harness_section('every zero-decimal currency: 5 units is 5');
foreach (array('BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF') as $code) {
    pin($code, array(0, 5, 5_000_000), array(Money::decimals($code), Money::toMinor(5_000_000, $code), Money::toMicros(5, $code)));
}
pin('the zero-decimal list is exactly these', 15, count(Money::ZERO_DECIMAL));

harness_section('every three-decimal currency: 5 units is 5000');
foreach (array('BHD', 'JOD', 'KWD', 'OMR', 'TND') as $code) {
    pin($code, array(3, 5000, 5_000_000), array(Money::decimals($code), Money::toMinor(5_000_000, $code), Money::toMicros(5000, $code)));
    pin($code . ' refuses a last digit other than 0', null, Money::toMinor(5_001_000, $code));
}
pin('the three-decimal list is exactly these', 5, count(Money::THREE_DECIMAL));

harness_section('whole units sent as two decimals (ISK, UGX)');
foreach (array('ISK', 'UGX') as $code) {
    pin($code . ': 5 units is 500, never 5', array(2, 500, 5_000_000), array(Money::decimals($code), Money::toMinor(5_000_000, $code), Money::toMicros(500, $code)));
    pin($code . ' refuses a fraction', null, Money::toMinor(5_500_000, $code));
}
pin('only these two', array('ISK', 'UGX'), Money::WHOLE_ONLY);

harness_section('HUF and TWD charge two-decimal amounts (their whole-unit rule is for payouts)');
foreach (array('HUF', 'TWD') as $code) {
    pin($code . ': 10.45 is 1045', array(2, 1045), array(Money::decimals($code), Money::toMinor(10_450_000, $code)));
}

harness_section('lists');
check('every supported code is three letters', array_filter(Money::SUPPORTED, static fn ($c) => !preg_match('/^[A-Z]{3}$/', $c)) === array());
check('every special-case code is supported', array_diff(array_merge(Money::ZERO_DECIMAL, Money::THREE_DECIMAL, Money::WHOLE_ONLY), Money::SUPPORTED) === array());
check('no code is in two special lists', count(array_unique(array_merge(Money::ZERO_DECIMAL, Money::THREE_DECIMAL, Money::WHOLE_ONLY))) === count(Money::ZERO_DECIMAL) + count(Money::THREE_DECIMAL) + count(Money::WHOLE_ONLY));

exit(harness_result());
