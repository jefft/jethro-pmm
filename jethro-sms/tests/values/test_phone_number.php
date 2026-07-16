<?php

/**
 * Unit tests for PhoneNumber value object.
 *
 * Pins contracts from:
 *   - docs/sms/improvements/28-au-prefix-hardcoding.md  (internationalise(), prefix handling)
 *   - docs/sms/improvements/27-smsstatus-wire-codes-leak.md  (referenced for value-object context)
 *   - docs/sms/improvements/15-cellcast-delivery-polling-dead.md  (general SMS subsystem context)
 *
 * Reference: jethro-sms/src/sms.php PhoneNumber class (~line 937).
 */

namespace Test\Sms\Values;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_throws};
use \Sms\PhoneNumber;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Normalisation — strips non-digit characters
// ---------------------------------------------------------------------------

test('normalization: strips spaces', function () {
    $p = new PhoneNumber('0400 123 456');
    assert_eq($p->value, '0400123456');
});

test('normalization: strips brackets', function () {
    $p = new PhoneNumber('(0400)123456');
    assert_eq($p->value, '0400123456');
});

test('normalization: strips dashes', function () {
    $p = new PhoneNumber('0400-123-456');
    assert_eq($p->value, '0400123456');
});

test('normalization: strips leading +', function () {
    $p = new PhoneNumber('+61400123456');
    assert_eq($p->value, '61400123456');
});

test('normalization: strips all of spaces, brackets, dashes, and + together', function () {
    // '+61 (0)400-123 456' style
    $p = new PhoneNumber('+61 (0)400-123 456');
    assert_eq($p->value, '610400123456');
});

test('normalization: pure digits pass through unchanged', function () {
    $p = new PhoneNumber('0401234567');
    assert_eq($p->value, '0401234567');
});

// ---------------------------------------------------------------------------
// Empty after normalization → InvalidArgumentException
// ---------------------------------------------------------------------------

test('empty string → InvalidArgumentException', function () {
    assert_throws(\InvalidArgumentException::class, function () {
        new PhoneNumber('');
    });
});

test('only non-digit chars → InvalidArgumentException', function () {
    assert_throws(\InvalidArgumentException::class, function () {
        new PhoneNumber('+-() ');
    });
});

// ---------------------------------------------------------------------------
// internationalise() — conversion
// ---------------------------------------------------------------------------

test("internationalise('0','61') on '0401234567' → '61401234567'", function () {
    $p = new PhoneNumber('0401234567');
    $result = $p->internationalise('0', '61');
    assert_eq($result->value, '61401234567');
});

test('internationalise: converted number does not carry local prefix', function () {
    $p = new PhoneNumber('0400123456');
    $result = $p->internationalise('0', '61');
    // Must start with 61, not 0
    assert_eq(substr($result->value, 0, 2), '61');
    assert_false(str_starts_with($result->value, '0'));
});

test('internationalise: empty localPrefix → returns same instance', function () {
    $p = new PhoneNumber('0400123456');
    $result = $p->internationalise('', '61');
    assert_true($result === $p, 'Expected same object instance when localPrefix is empty');
});

test('internationalise: empty internationalPrefix → returns same instance', function () {
    $p = new PhoneNumber('0400123456');
    $result = $p->internationalise('0', '');
    assert_true($result === $p, 'Expected same object instance when internationalPrefix is empty');
});

test('internationalise: both prefixes empty → returns same instance', function () {
    $p = new PhoneNumber('0400123456');
    $result = $p->internationalise('', '');
    assert_true($result === $p, 'Expected same object instance when both prefixes are empty');
});

test('internationalise: number does not start with localPrefix → same instance', function () {
    $p = new PhoneNumber('61400123456');  // already international
    $result = $p->internationalise('0', '61');
    assert_true($result === $p, 'Expected same object when number does not start with local prefix');
});

test('internationalise: returns new PhoneNumber (not same instance) when conversion applies', function () {
    $p = new PhoneNumber('0400123456');
    $result = $p->internationalise('0', '61');
    assert_false($result === $p, 'Converted number should be a new object');
});

// ---------------------------------------------------------------------------
// getPhoneNumber() — returns $this
// ---------------------------------------------------------------------------

test('getPhoneNumber() returns same instance', function () {
    $p = new PhoneNumber('0400123456');
    assert_true($p->getPhoneNumber() === $p, 'getPhoneNumber() must return $this');
});

// ---------------------------------------------------------------------------
// __toString()
// ---------------------------------------------------------------------------

test('__toString returns normalized digit string', function () {
    $p = new PhoneNumber('+61 400-123 456');
    assert_eq((string)$p, '61400123456');
});

test('__toString equals ->value', function () {
    $p = new PhoneNumber('0400123456');
    assert_eq((string)$p, $p->value);
});
